<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Association\Loader;

use ArrayAccess;
use Cake\Datasource\EntityInterface;
use Cake\Datasource\QueryInterface;
use Closure;
use Crustum\Mongo\Database\Expression\TupleInExpression;
use Crustum\Mongo\Database\Query\Query;
use Crustum\Mongo\ODM\Query\SelectQuery;
use Traversable;

/**
 * Loads referenced documents with one batched query.
 *
 * @see cake60/src/ORM/Association/Loader/SelectLoader.php
 */
class SelectLoader implements LoaderInterface
{
    /**
     * Loader configuration.
     *
     * @var array<string, mixed>
     */
    protected array $options;

    /**
     * Constructor.
     *
     * @param array<string, mixed> $options Loader configuration.
     */
    public function __construct(array $options)
    {
        $this->options = $options;
    }

    /**
     * Builds a callable that injects fetched rows into source entities.
     *
     * @param array<string, mixed> $options Runtime loader options.
     * @return \Closure
     */
    public function buildEagerLoader(array $options): Closure
    {
        $options += $this->options;

        // Association conditions AND per-load conditions both apply (cake
        // parity); per-load keys win on a collision. Closure conditions are
        // resolved by the loader's where() path and cannot be merged.
        $callConditions = $options['conditions'] ?? null;
        if (
            isset($this->options['conditions'])
            && is_array($this->options['conditions'])
            && is_array($callConditions)
        ) {
            $options['conditions'] = array_merge($this->options['conditions'], $callConditions);
        }

        return function (iterable $entities) use ($options): iterable {
            $query = $options['finder']();
            if (!$query instanceof QueryInterface) {
                return $entities;
            }

            if ($query instanceof SelectQuery) {
                $query->eagerLoaded(true);
            }

            $parentQuery = $options['query'] ?? null;
            if ($query instanceof Query && $parentQuery instanceof Query) {
                $query->setConnectionRole($parentQuery->getConnectionRole());
            }

            if ($query instanceof SelectQuery && $parentQuery instanceof SelectQuery) {
                $query->hydrate($parentQuery->isHydrationEnabled());
            }

            $many = ($options['associationType'] ?? '') === 'oneToMany'
                || ($options['associationType'] ?? '') === 'manyToMany';

            $sourceHoldsForeignKey = ($options['associationType'] ?? '') === 'manyToOne';
            $foreignKeyDef = $options['foreignKey'] ?? '_id';
            // `foreignKey => false` disables FK matching: the association loads
            // by conditions alone (cake parity) and attaches the single match.
            $keyMatchingDisabled = in_array($foreignKeyDef, [false, null, ''], true);
            if ($keyMatchingDisabled && $sourceHoldsForeignKey && empty($options['conditions'])) {
                return $entities;
            }

            $sourceKeyFields = $this->normalizeKeyFields(
                $sourceHoldsForeignKey ? $foreignKeyDef : ($options['bindingKey'] ?? '_id'),
            );
            $targetKeyFields = $this->normalizeKeyFields(
                $sourceHoldsForeignKey ? ($options['bindingKey'] ?? '_id') : $foreignKeyDef,
            );
            $filterByKey = !$keyMatchingDisabled && $targetKeyFields !== [];
            $collectSourceKeys = !$keyMatchingDisabled && $sourceKeyFields !== [];

            $sourcePath = isset($options['sourcePath']) ? (string)$options['sourcePath'] : '';
            $sourceEntities = $this->collectSourceEntities($entities, $sourcePath);

            $tuples = [];
            if ($collectSourceKeys) {
                foreach ($sourceEntities as $sourceEntity) {
                    $tuple = $this->extractKeyTuple($sourceEntity, $sourceKeyFields);
                    if ($tuple !== null) {
                        $tuples[$this->tupleMapKey($tuple)] = $tuple;
                    }
                }
            }

            if ($collectSourceKeys && $tuples === []) {
                return $entities;
            }

            $conditions = $options['conditions'] ?? [];
            if ($conditions instanceof Closure) {
                // Let the query layer invoke the closure with (expression, query)
                // so association conditions match the `where()` contract.
                $query->where($conditions);
                $conditions = null;
            }

            $conditions = $conditions === null ? [] : (is_array($conditions) ? $conditions : []);
            if ($filterByKey && $tuples !== []) {
                if (count($targetKeyFields) === 1) {
                    $conditions[$targetKeyFields[0] . ' IN'] = array_map(
                        static fn(array $tuple): mixed => $tuple[0],
                        array_values($tuples),
                    );
                } elseif ($query instanceof Query) {
                    $query->where(new TupleInExpression($targetKeyFields, array_values($tuples)));
                }
            }

            $query->where($conditions);
            if (!empty($options['fields'])) {
                $fields = $options['fields'];
                if ($fields instanceof Closure) {
                    $fields = $fields($query);
                }

                $fields = (array)$fields;
                foreach ($targetKeyFields as $field) {
                    if (!in_array($field, $fields, true)) {
                        $fields[] = $field;
                    }
                }

                $query->select($fields);
            }

            if (!empty($options['sort'])) {
                $query->orderBy($options['sort']);
            }

            if (!empty($options['limit'])) {
                $query->limit($options['limit']);
            }

            if (!empty($options['skip'])) {
                $query->offset((int)$options['skip']);
            }

            if (!empty($options['queryBuilder'])) {
                $builder = $options['queryBuilder'];
                $built = $builder($query);
                if (is_object($built) && is_callable([$built, 'all'])) {
                    $query = $built;
                }
            }

            if (!empty($options['contain']) && $query instanceof SelectQuery) {
                $query->contain($options['contain']);
            }

            $rows = $query->all();
            $map = [];
            if (!$filterByKey) {
                $map['*'] = $rows instanceof Traversable ? iterator_to_array($rows, false) : (array)$rows;
            } else {
                foreach ($rows as $rowKey => $row) {
                    $tuple = $this->extractKeyTuple($row, $targetKeyFields);
                    if ($tuple === null) {
                        continue;
                    }

                    $mapKey = $this->tupleMapKey($tuple);
                    if ($many) {
                        if (is_int($rowKey)) {
                            $map[$mapKey][] = $row;
                        } else {
                            $map[$mapKey][$rowKey] = $row;
                        }
                    } else {
                        $map[$mapKey] = $row;
                    }
                }
            }

            $property = (string)$options['nestKey'];

            $loadedMap = [];
            foreach ($sourceEntities as $i => $sourceEntity) {
                if (!$filterByKey) {
                    $matchedRows = $map['*'];
                    $loadedMap[$i] = $many ? $matchedRows : ($matchedRows !== [] ? reset($matchedRows) : null);
                    continue;
                }

                $tuple = $this->extractKeyTuple($sourceEntity, $sourceKeyFields);
                $mapKey = $tuple === null ? '' : $this->tupleMapKey($tuple);
                $loadedMap[$i] = $many ? ($map[$mapKey] ?? []) : ($map[$mapKey] ?? null);
            }

            $sourcePaths = $sourcePath === ''
                ? []
                : $this->collectSourcePaths($entities, $sourcePath);

            foreach ($loadedMap as $i => $loaded) {
                if ($sourcePath === '') {
                    $sourceEntity = $sourceEntities[$i] ?? null;
                    if ($sourceEntity instanceof EntityInterface) {
                        $sourceEntity->set($property, $loaded);
                        $sourceEntity->setDirty($property, false);
                    } elseif (is_array($sourceEntity) && is_array($entities)) {
                        $entities[$i][$property] = $loaded;
                    }

                    continue;
                }

                $path = $sourcePaths[$i] ?? null;
                if ($path === null) {
                    continue;
                }

                $entities = $this->setByPath($entities, $path, $property, $loaded);
            }

            return $entities;
        };
    }

    /**
     * Normalizes an association key definition to a list of field names.
     *
     * @param array<string>|string|false|null $key The key definition.
     * @return list<string>
     */
    protected function normalizeKeyFields(array|string|false|null $key): array
    {
        if (in_array($key, [false, null, ''], true)) {
            return [];
        }

        return array_values(array_filter((array)$key, is_string(...)));
    }

    /**
     * Extracts a tuple of key values from an entity or array row.
     *
     * @param mixed $entity The source row.
     * @param list<string> $fields The key field names.
     * @return list<mixed>|null The tuple, or null when any field is missing.
     */
    protected function extractKeyTuple(mixed $entity, array $fields): ?array
    {
        if ($fields === []) {
            return null;
        }

        $tuple = [];
        foreach ($fields as $field) {
            $value = $entity instanceof EntityInterface
                ? $entity->get($field)
                : (is_array($entity) ? ($entity[$field] ?? null) : null);
            if ($value === null) {
                return null;
            }

            $tuple[] = $value;
        }

        return $tuple;
    }

    /**
     * Builds a stable map key for a tuple of values.
     *
     * @param list<mixed> $tuple The tuple values.
     * @return string
     */
    protected function tupleMapKey(array $tuple): string
    {
        return implode(';', array_map(static fn(mixed $value): string => (string)$value, $tuple));
    }

    /**
     * Collects the nested property paths for each source entity.
     *
     * For a nested association the dotted `sourcePath` is walked on each root
     * result; each visited entity records the key/index path that leads to it
     * inside the original result, so associations can be injected by reference.
     *
     * @param iterable<mixed> $entities The result set.
     * @param string $sourcePath The dotted property path to the parent entities.
     * @return array<int, list<int|string>>
     */
    protected function collectSourcePaths(iterable $entities, string $sourcePath): array
    {
        $segments = explode('.', $sourcePath);

        $paths = [];
        foreach ($entities as $rootIndex => $root) {
            $this->walkSourcePaths($root, $segments, [$rootIndex], $paths);
        }

        return $paths;
    }

    /**
     * Recursively walks a property path collecting key paths to leaves.
     *
     * @param mixed $document The current value.
     * @param array<int, string> $segments The remaining path segments.
     * @param list<int|string> $path The accumulated key path.
     * @param array<int, list<int|string>> $paths The collected paths.
     * @return void
     */
    protected function walkSourcePaths(mixed $document, array $segments, array $path, array &$paths): void
    {
        $segment = array_shift($segments);
        if ($segment === null) {
            if ($document instanceof EntityInterface || is_array($document)) {
                $paths[] = $path;
            }

            return;
        }

        $value = $document instanceof EntityInterface
            ? $document->get($segment)
            : (is_array($document) ? ($document[$segment] ?? null) : null);

        if ($value === null) {
            return;
        }

        if (is_iterable($value) && !($value instanceof EntityInterface) && !is_array($value)) {
            $value = iterator_to_array($value, false);
        }

        if (is_array($value) && array_is_list($value)) {
            foreach ($value as $itemKey => $item) {
                $this->walkSourcePaths($item, $segments, [...$path, $segment, $itemKey], $paths);
            }

            return;
        }

        $this->walkSourcePaths($value, $segments, [...$path, $segment], $paths);
    }

    /**
     * Sets a nested property by key path, mutating the original structure.
     *
     * @param iterable<mixed> $entities The result set (array by reference).
     * @param list<int|string> $path The key path to the target entity.
     * @param string $property The property to set.
     * @param mixed $loaded The value to inject.
     * @return iterable<mixed> The (possibly rewritten) result set.
     */
    protected function setByPath(iterable $entities, array $path, string $property, mixed $loaded): iterable
    {
        $target = &$entities;
        foreach ($path as $key) {
            if (!is_array($target) && !($target instanceof ArrayAccess)) {
                return $entities;
            }

            if (!isset($target[$key])) {
                return $entities;
            }

            $target = &$target[$key];
        }

        if ($target instanceof EntityInterface) {
            $target->set($property, $loaded);
            $target->setDirty($property, false);
        } elseif (is_array($target)) {
            $target[$property] = $loaded;
        }

        return $entities;
    }

    /**
     * Collects the flat list of source entities to match against.
     *
     * For a top-level association this is the passed result set. For a nested
     * association the dotted `sourcePath` (e.g. `comments.user`) is walked on
     * each result so keys are read from the already-loaded parent entities.
     *
     * @param iterable<mixed> $entities The result set.
     * @param string $sourcePath The dotted property path, or empty for top-level.
     * @return array<int, mixed>
     */
    protected function collectSourceEntities(iterable $entities, string $sourcePath): array
    {
        if ($sourcePath === '') {
            return is_array($entities) ? $entities : iterator_to_array($entities, false);
        }

        $segments = explode('.', $sourcePath);
        $collected = [];
        foreach ($entities as $document) {
            $this->walkSourcePath($document, $segments, $collected);
        }

        return $collected;
    }

    /**
     * Recursively walks a property path on an entity/array collecting leaves.
     *
     * @param mixed $document The current value.
     * @param array<int, string> $segments The remaining path segments.
     * @param array<int, mixed> $collected The collected leaves.
     * @return void
     */
    protected function walkSourcePath(mixed $document, array $segments, array &$collected): void
    {
        $segment = array_shift($segments);
        if ($segment === null) {
            if ($document instanceof EntityInterface || is_array($document)) {
                $collected[] = $document;
            }

            return;
        }

        $value = $document instanceof EntityInterface
            ? $document->get($segment)
            : (is_array($document) ? ($document[$segment] ?? null) : null);

        if ($value === null) {
            return;
        }

        if (is_iterable($value) && !($value instanceof EntityInterface) && !is_array($value)) {
            $value = iterator_to_array($value, false);
        }

        if (is_array($value) && array_is_list($value)) {
            foreach ($value as $item) {
                $this->walkSourcePath($item, $segments, $collected);
            }

            return;
        }

        $this->walkSourcePath($value, $segments, $collected);
    }
}

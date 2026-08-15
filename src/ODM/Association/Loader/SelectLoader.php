<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Association\Loader;

use ArrayAccess;
use Cake\Datasource\EntityInterface;
use Cake\Datasource\QueryInterface;
use Closure;
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
            $rawSourceKey = $sourceHoldsForeignKey
                ? ($options['foreignKey'] ?? '_id')
                : ($options['bindingKey'] ?? '_id');
            if (in_array($rawSourceKey, [false, null, ''], true)) {
                return $entities;
            }

            $keys = [];
            $sourceKey = (string)$rawSourceKey;
            $sourcePath = isset($options['sourcePath']) ? (string)$options['sourcePath'] : '';
            $sourceEntities = $this->collectSourceEntities($entities, $sourcePath);
            foreach ($sourceEntities as $sourceEntity) {
                $key = $sourceEntity instanceof EntityInterface
                    ? $sourceEntity->get($sourceKey)
                    : (is_array($sourceEntity) ? ($sourceEntity[$sourceKey] ?? null) : null);
                if ($key !== null) {
                    $keys[(string)$key] = $key;
                }
            }

            if ($keys === []) {
                return $entities;
            }

            $targetKey = (string)($sourceHoldsForeignKey
                ? ($options['bindingKey'] ?? '_id')
                : ($options['foreignKey'] ?? '_id'));
            $conditions = $options['conditions'] ?? [];
            if ($conditions instanceof Closure) {
                // Let the query layer invoke the closure with (expression, query)
                // so association conditions match the `where()` contract.
                $query->where($conditions);
                $conditions = null;
            }

            $conditions = $conditions === null ? [] : (is_array($conditions) ? $conditions : []);
            if ($targetKey !== '') {
                $conditions[$targetKey . ' IN'] = array_values($keys);
            }

            $query->where($conditions);
            if (!empty($options['fields'])) {
                $fields = $options['fields'];
                if ($fields instanceof Closure) {
                    $fields = $fields($query);
                }

                $fields = (array)$fields;
                if (!in_array($targetKey, $fields, true)) {
                    $fields[] = $targetKey;
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

            $rows = $query->all();
            $map = [];
            $disabledKey = $targetKey === '';
            if ($disabledKey) {
                $map['*'] = $rows instanceof Traversable ? iterator_to_array($rows, false) : (array)$rows;
            } else {
                foreach ($rows as $rowKey => $row) {
                    $value = $row instanceof EntityInterface ? $row->get($targetKey) : ($row[$targetKey] ?? null);
                    if ($value === null) {
                        continue;
                    }

                    if ($many) {
                        if (is_int($rowKey)) {
                            $map[(string)$value][] = $row;
                        } else {
                            $map[(string)$value][$rowKey] = $row;
                        }
                    } else {
                        $map[(string)$value] = $row;
                    }
                }
            }

            $property = (string)$options['nestKey'];
            $many = ($options['associationType'] ?? '') === 'oneToMany'
                || ($options['associationType'] ?? '') === 'manyToMany';

            $loadedMap = [];
            foreach ($sourceEntities as $i => $sourceEntity) {
                if ($disabledKey) {
                    $loadedMap[$i] = $many ? $map['*'] : ($map['*'][0] ?? null);
                } else {
                    $value = $sourceEntity instanceof EntityInterface
                        ? $sourceEntity->get($sourceKey)
                        : (is_array($sourceEntity) ? ($sourceEntity[$sourceKey] ?? null) : null);
                    $key = $value === null ? '' : (string)$value;
                    $loadedMap[$i] = $many ? ($map[$key] ?? []) : ($map[$key] ?? null);
                }
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

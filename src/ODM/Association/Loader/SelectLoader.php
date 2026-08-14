<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Association\Loader;

use Cake\Datasource\EntityInterface;
use Cake\Datasource\QueryInterface;
use Closure;
use Crustum\Mongo\Database\Query\Query;
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

            $parentQuery = $options['query'] ?? null;
            if ($query instanceof Query && $parentQuery instanceof Query) {
                $query->setConnectionRole($parentQuery->getConnectionRole());
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
                foreach ($rows as $row) {
                    $value = $row instanceof EntityInterface ? $row->get($targetKey) : ($row[$targetKey] ?? null);
                    if ($value === null) {
                        continue;
                    }

                    if ($many) {
                        $map[(string)$value][] = $row;
                    } else {
                        $map[(string)$value] = $row;
                    }
                }
            }

            $property = (string)$options['nestKey'];
            $many = ($options['associationType'] ?? '') === 'oneToMany'
                || ($options['associationType'] ?? '') === 'manyToMany';
            foreach ($sourceEntities as $sourceEntity) {
                if ($disabledKey) {
                    $loaded = $many ? $map['*'] : ($map['*'][0] ?? null);
                } else {
                    $value = $sourceEntity instanceof EntityInterface
                        ? $sourceEntity->get($sourceKey)
                        : (is_array($sourceEntity) ? ($sourceEntity[$sourceKey] ?? null) : null);
                    $key = $value === null ? '' : (string)$value;
                    $loaded = $many ? ($map[$key] ?? []) : ($map[$key] ?? null);
                }

                if ($sourceEntity instanceof EntityInterface) {
                    $sourceEntity->set($property, $loaded);
                    $sourceEntity->setDirty($property, false);
                } elseif (is_array($sourceEntity)) {
                    $sourceEntity[$property] = $loaded;
                }
            }

            return $entities;
        };
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
            return is_array($entities) ? array_values($entities) : iterator_to_array($entities, false);
        }

        $segments = explode('.', $sourcePath);
        array_pop($segments);
        $collected = [];
        foreach ($entities as $entity) {
            $this->walkSourcePath($entity, $segments, $collected);
        }

        return $collected;
    }

    /**
     * Recursively walks a property path on an entity/array collecting leaves.
     *
     * @param mixed $entity The current value.
     * @param array<int, string> $segments The remaining path segments.
     * @param array<int, mixed> $collected The collected leaves.
     * @return void
     */
    protected function walkSourcePath(mixed $entity, array $segments, array &$collected): void
    {
        $segment = array_shift($segments);
        if ($segment === null) {
            if ($entity instanceof EntityInterface || is_array($entity)) {
                $collected[] = $entity;
            }

            return;
        }

        $value = $entity instanceof EntityInterface
            ? $entity->get($segment)
            : (is_array($entity) ? ($entity[$segment] ?? null) : null);

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

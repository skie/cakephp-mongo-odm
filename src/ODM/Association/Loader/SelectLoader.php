<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Association\Loader;

use Cake\Datasource\EntityInterface;
use Closure;

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
            if (!is_object($query) || !is_callable([$query, 'where']) || !is_callable([$query, 'all'])) {
                return $entities;
            }

            $many = ($options['associationType'] ?? '') === 'oneToMany'
                || ($options['associationType'] ?? '') === 'manyToMany';
            $rawSourceKey = $many ? ($options['bindingKey'] ?? '_id') : ($options['foreignKey'] ?? '_id');
            if ($rawSourceKey === false || $rawSourceKey === null || $rawSourceKey === '') {
                return $entities;
            }

            $keys = [];
            $sourceKey = (string)$rawSourceKey;
            foreach ($entities as $entity) {
                $key = $entity->get($sourceKey);
                if ($key !== null) {
                    $keys[(string)$key] = $key;
                }
            }

            if ($keys === []) {
                return $entities;
            }

            $targetKey = (string)($many ? ($options['foreignKey'] ?? '_id') : ($options['bindingKey'] ?? '_id'));
            $conditions = is_array($options['conditions'] ?? null) ? $options['conditions'] : [];
            $conditions[$targetKey . ' IN'] = array_values($keys);
            $query->where($conditions);
            if (!empty($options['fields'])) {
                $fields = (array)$options['fields'];
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
                $query->skip($options['skip']);
            }
            $rows = $query->all();
            $map = [];
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

            $property = (string)$options['nestKey'];
            $many = ($options['associationType'] ?? '') === 'oneToMany'
                || ($options['associationType'] ?? '') === 'manyToMany';
            foreach ($entities as $entity) {
                $value = $entity->get($sourceKey);
                $key = $value === null ? '' : (string)$value;
                $loaded = $many ? ($map[$key] ?? []) : ($map[$key] ?? null);

                if ($entity instanceof EntityInterface) {
                    $entity->set($property, $loaded);
                    $entity->setDirty($property, false);
                } elseif (is_array($entity)) {
                    $entity[$property] = $loaded;
                }
            }

            return $entities;
        };
    }
}

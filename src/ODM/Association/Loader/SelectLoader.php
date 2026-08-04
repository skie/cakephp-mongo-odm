<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Association\Loader;

use Cake\Datasource\EntityInterface;

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
     * @return callable
     */
    public function buildEagerLoader(array $options): callable
    {
        $options += $this->options;

        return function (iterable $entities) use ($options): iterable {
            $query = $options['finder']();
            if (!is_object($query) || !is_callable([$query, 'where']) || !is_callable([$query, 'all'])) {
                return $entities;
            }

            $many = ($options['associationType'] ?? '') === 'oneToMany'
                || ($options['associationType'] ?? '') === 'manyToMany';
            $keys = [];
            $sourceKey = (string)($many ? ($options['bindingKey'] ?? '_id') : ($options['foreignKey'] ?? '_id'));
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
            $rows = $query->where($conditions)->all();
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
                $entity->set($property, $many ? ($map[$key] ?? []) : ($map[$key] ?? null));
                $entity->setDirty($property, false);
            }

            return $entities;
        };
    }
}

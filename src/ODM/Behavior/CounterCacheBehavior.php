<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Behavior;

use ArrayObject;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Closure;
use Crustum\Mongo\ODM\Association;
use Crustum\Mongo\ODM\Association\BelongsTo;
use Crustum\Mongo\ODM\Behavior;

/**
 * Updates configured parent counters after saves and deletes.
 *
 * @see cake60/src/ORM/Behavior/CounterCacheBehavior.php
 */
class CounterCacheBehavior extends Behavior
{
    /**
     * Store the fields which should be ignored.
     *
     * @var array<string, array<string, bool>>
     */
    protected array $ignoreDirty = [];

    /**
     * beforeSave callback.
     *
     * Check if a field, which should be ignored, is dirty.
     *
     * @param \Cake\Event\EventInterface<object> $event The beforeSave event that was fired.
     * @param \Cake\Datasource\EntityInterface $entity The entity that is going to be saved.
     * @param \ArrayObject<string, mixed> $options The options for the query.
     * @return void
     */
    public function beforeSave(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        if (($options['ignoreCounterCache'] ?? false) === true) {
            return;
        }

        foreach ($this->getConfig() as $assoc => $settings) {
            $assoc = $this->collection->getAssociation($assoc);
            foreach ($settings as $field => $config) {
                if (is_int($field)) {
                    continue;
                }

                $registryAlias = $assoc->getTarget()->getRegistryAlias();
                $entityAlias = $assoc->getProperty();
                /** @var \Cake\Datasource\EntityInterface|null $assocEntity */
                $assocEntity = $entity->{$entityAlias};

                if (
                    !$config instanceof Closure &&
                    is_array($config) &&
                    ($config['ignoreDirty'] ?? false) === true &&
                    $assocEntity instanceof EntityInterface &&
                    $assocEntity->isDirty($field)
                ) {
                    $this->ignoreDirty[$registryAlias][$field] = true;
                }
            }
        }
    }

    /**
     * afterSave callback.
     *
     * Makes sure to update counter cache when a new record is created or updated.
     *
     * @param \Cake\Event\EventInterface<object> $event The afterSave event that was fired.
     * @param \Cake\Datasource\EntityInterface $entity The entity that was saved.
     * @param \ArrayObject<string, mixed> $options The options for the query.
     * @return void
     */
    public function afterSave(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        if (($options['ignoreCounterCache'] ?? false) === true) {
            return;
        }

        $this->processAssociations($event, $entity);
        $this->ignoreDirty = [];
    }

    /**
     * afterDelete callback.
     *
     * Makes sure to update counter cache when a record is deleted.
     *
     * @param \Cake\Event\EventInterface<object> $event The afterDelete event that was fired.
     * @param \Cake\Datasource\EntityInterface $entity The entity that was deleted.
     * @param \ArrayObject<string, mixed> $options The options for the query.
     * @return void
     */
    public function afterDelete(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        if (($options['ignoreCounterCache'] ?? false) === true) {
            return;
        }

        $this->processAssociations($event, $entity);
    }

    /**
     * Update counter cache for a batch of records.
     *
     * Counter caches configured to use closures will not be updated by the method.
     *
     * @param string|null $assocName The association name to update counter cache for.
     *   If null, all configured associations will be processed.
     * @param int $limit The number of records to update per page/iteration.
     * @param int|null $page The page/iteration number. If null (default), all
     *   records will be updated one page at a time.
     * @return void
     */
    public function updateCounterCache(?string $assocName = null, int $limit = 100, ?int $page = null): void
    {
        $config = $this->getConfig();
        if ($assocName !== null) {
            $config = [$assocName => $config[$assocName]];
        }

        foreach ($config as $assoc => $settings) {
            /** @var \Crustum\Mongo\ODM\Association\BelongsTo $belongsTo */
            $belongsTo = $this->collection->getAssociation($assoc);

            foreach ($settings as $field => $config) {
                if ($config instanceof Closure) {
                    continue;
                }

                if (is_int($field)) {
                    $field = $config;
                    $config = [];
                }

                $this->updateCountForAssociation($belongsTo, (string)$field, $config, $limit, $page);
            }
        }
    }

    /**
     * Update counter cache for the given association.
     *
     * @param \Crustum\Mongo\ODM\Association\BelongsTo $assoc The association object.
     * @param string $field Counter cache field.
     * @param array<string, mixed> $config Config array.
     * @param int $limit Limit.
     * @param int|null $page Page number.
     * @return void
     */
    protected function updateCountForAssociation(
        BelongsTo $assoc,
        string $field,
        array $config,
        int $limit = 100,
        ?int $page = null,
    ): void {
        $primaryKeys = (array)$assoc->getBindingKey();
        /** @var array<string> $foreignKeys */
        $foreignKeys = (array)$assoc->getForeignKey();

        $query = $assoc->getTarget()->find()
            ->select($primaryKeys)
            ->limit($limit);

        foreach ($primaryKeys as $key) {
            $query->orderByAsc($key);
        }

        $singlePage = $page !== null;
        $page ??= 1;

        do {
            $results = $query
                ->page($page++)
                ->all();

            foreach ($results as $entity) {
                /** @var \Cake\Datasource\EntityInterface $entity */
                $updateConditions = $entity->extract($primaryKeys);

                $countConditions = array_combine($foreignKeys, $updateConditions);

                $count = $this->getCount($config, $countConditions);
                $assoc->getTarget()->updateAll([$field => $count], $updateConditions);
            }
        } while (!$singlePage && $results->count() === $limit);
    }

    /**
     * Iterate all associations and update counter caches.
     *
     * @param \Cake\Event\EventInterface<object> $event Event instance.
     * @param \Cake\Datasource\EntityInterface $entity Entity.
     * @return void
     */
    protected function processAssociations(EventInterface $event, EntityInterface $entity): void
    {
        foreach ($this->getConfig() as $assoc => $settings) {
            if (!is_string($assoc)) {
                continue;
            }
            if (!$this->collection->hasAssociation($assoc)) {
                continue;
            }

            $assoc = $this->collection->getAssociation($assoc);
            $this->processAssociation($event, $entity, $assoc, $settings);
        }
    }

    /**
     * Updates counter cache for a single association.
     *
     * @param \Cake\Event\EventInterface<object> $event Event instance.
     * @param \Cake\Datasource\EntityInterface $entity Entity.
     * @param \Crustum\Mongo\ODM\Association $assoc The association object.
     * @param array<string|int, mixed> $settings The settings for counter cache for this association.
     * @return void
     */
    protected function processAssociation(
        EventInterface $event,
        EntityInterface $entity,
        Association $assoc,
        array $settings,
    ): void {
        /** @var array<string> $foreignKeys */
        $foreignKeys = (array)$assoc->getForeignKey();
        $countConditions = $entity->extract($foreignKeys);

        $primaryKeys = (array)$assoc->getBindingKey();
        $updateConditions = array_combine($primaryKeys, $countConditions);

        $countOriginalConditions = $entity->extractOriginalChanged($foreignKeys);
        $updateOriginalConditions = null;
        if ($countOriginalConditions !== []) {
            $updateOriginalConditions = array_combine($primaryKeys, $countOriginalConditions);
        }

        foreach ($settings as $field => $config) {
            if (is_int($field)) {
                $field = $config;
                $config = [];
            }

            if (
                isset($this->ignoreDirty[$assoc->getTarget()->getRegistryAlias()][$field]) &&
                $this->ignoreDirty[$assoc->getTarget()->getRegistryAlias()][$field]
            ) {
                continue;
            }

            if ($this->shouldUpdateCount($updateConditions)) {
                if ($config instanceof Closure) {
                    $count = $config($event, $entity, $this->collection, false);
                } else {
                    $count = $this->getCount((array)$config, $countConditions);
                }

                if ($count !== false) {
                    $assoc->getTarget()->updateAll([(string)$field => $count], $updateConditions);
                }
            }

            if ($updateOriginalConditions && $this->shouldUpdateCount($updateOriginalConditions)) {
                if ($config instanceof Closure) {
                    $count = $config($event, $entity, $this->collection, true);
                } else {
                    $count = $this->getCount((array)$config, $countOriginalConditions);
                }

                if ($count !== false) {
                    $assoc->getTarget()->updateAll([(string)$field => $count], $updateOriginalConditions);
                }
            }
        }
    }

    /**
     * Checks if the count should be updated given a set of conditions.
     *
     * @param array<string, mixed> $conditions Conditions to update count.
     * @return bool True if the count update should happen, false otherwise.
     */
    protected function shouldUpdateCount(array $conditions): bool
    {
        return !empty(array_filter($conditions, static fn(mixed $value): bool => $value !== null));
    }

    /**
     * Fetches and returns the count for a single field in an association.
     *
     * @param array<string, mixed> $config The counter cache configuration for a single field.
     * @param array<string, mixed> $conditions Additional conditions given to the query.
     * @return int The number of relations matching the given config and conditions.
     */
    protected function getCount(array $config, array $conditions): int
    {
        $finder = 'all';
        if (!empty($config['finder'])) {
            $finder = $config['finder'];
            unset($config['finder']);
        }

        $config['conditions'] = array_merge($conditions, $config['conditions'] ?? []);

        return $this->collection->find($finder, ...$config)->count();
    }
}

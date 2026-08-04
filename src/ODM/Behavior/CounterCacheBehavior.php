<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Behavior;

use ArrayObject;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Closure;
use Crustum\Mongo\ODM\Behavior;
use Crustum\Mongo\ODM\Collection;

/**
 * Updates configured parent counters after saves and deletes.
 *
 * @see cake60/src/ORM/Behavior/CounterCacheBehavior.php
 */
class CounterCacheBehavior extends Behavior
{
    /**
     * Handles the before-save callback.
     *
     * @param \Cake\Event\EventInterface<object> $event The dispatched event.
     * @param \Cake\Datasource\EntityInterface $entity The affected document.
     * @param \ArrayObject<string, mixed> $options Event options.
     * @return void
     */
    public function beforeSave(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
    }

    /**
     * Recalculates configured counters after a save.
     *
     * @param \Cake\Event\EventInterface<object> $event The dispatched event.
     * @param \Cake\Datasource\EntityInterface $entity The affected document.
     * @param \ArrayObject<string, mixed> $options Event options.
     * @return void
     */
    public function afterSave(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        if (($options['ignoreCounterCache'] ?? false) !== true) {
            $this->process($event, $entity);
        }
    }

    /**
     * Recalculates configured counters after a delete.
     *
     * @param \Cake\Event\EventInterface<object> $event The dispatched event.
     * @param \Cake\Datasource\EntityInterface $entity The affected document.
     * @param \ArrayObject<string, mixed> $options Event options.
     * @return void
     */
    public function afterDelete(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        if (($options['ignoreCounterCache'] ?? false) !== true) {
            $this->process($event, $entity);
        }
    }

    /**
     * Processes configured counter fields.
     *
     * @param \Cake\Event\EventInterface<object> $event The dispatched event.
     * @param \Cake\Datasource\EntityInterface $entity The affected document.
     * @return void
     */
    private function process(EventInterface $event, EntityInterface $entity): void
    {
        $collection = $this->collection();
        foreach ($this->getConfig() as $associationName => $settings) {
            if (!is_string($associationName)) {
                continue;
            }

            $association = $collection->getAssociation($associationName);
            if ($association === null) {
                continue;
            }

            $foreignKeys = (array)$association->getForeignKey();
            $bindingKeys = (array)$association->getBindingKey();
            $conditions = [];
            foreach ($foreignKeys as $key) {
                $value = $entity->get((string)$key);
                if ($value !== null) {
                    $conditions[(string)$key] = $value;
                }
            }

            $target = $association->getTarget();
            if ($conditions === []) {
                continue;
            }

            $updateConditions = array_combine(array_map(strval(...), $bindingKeys), array_values($conditions));
            foreach ($settings as $field => $config) {
                if (is_int($field)) {
                    $field = (string)$config;
                    $config = [];
                }

                $count = $config instanceof Closure
                    ? $config($event, $entity, $collection)
                    : $this->count($target, $config, $conditions);
                $target->updateAll([(string)$field => $count], $updateConditions);
            }
        }
    }

    /**
     * Counts matching target documents.
     *
     * @param \Crustum\Mongo\ODM\Collection $target The target collection.
     * @param mixed $config Counter configuration.
     * @param array<string, mixed> $conditions Base conditions.
     * @return int
     */
    private function count(Collection $target, mixed $config, array $conditions): int
    {
        $finder = (string)($config['finder'] ?? 'all');
        $conditions = array_merge($conditions, is_array($config['conditions'] ?? null) ? $config['conditions'] : []);

        return (int)$target->find($finder)->where($conditions)->count();
    }
}

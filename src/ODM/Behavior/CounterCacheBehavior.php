<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Behavior;

use ArrayObject;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Closure;
use Crustum\Mongo\ODM\Behavior;

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
        foreach ($this->getConfig() as $associationName => $settings) {
            if (!is_string($associationName)) {
                continue;
            }
            if (!is_callable([$this->collection(), 'getAssociation'])) {
                continue;
            }

            $association = call_user_func([$this->collection(), 'getAssociation'], $associationName);
            if (!is_object($association)) {
                continue;
            }
            if (!is_callable([$association, 'getForeignKey'])) {
                continue;
            }
            if (!is_callable([$association, 'getBindingKey'])) {
                continue;
            }

            $foreignKeys = (array)call_user_func([$association, 'getForeignKey']);
            $bindingKeys = (array)call_user_func([$association, 'getBindingKey']);
            $conditions = [];
            foreach ($foreignKeys as $key) {
                $value = $entity->get((string)$key);
                if ($value !== null) {
                    $conditions[(string)$key] = $value;
                }
            }

            $target = is_callable([$association, 'getTarget']) ? call_user_func([$association, 'getTarget']) : null;
            if (!is_object($target)) {
                continue;
            }
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
                    ? $config($event, $entity, $this->collection())
                    : $this->count($target, $config, $conditions);
                if ($count !== false && is_callable([$target, 'updateAll'])) {
                    call_user_func([$target, 'updateAll'], [(string)$field => $count], $updateConditions);
                }
            }
        }
    }

    /**
     * Counts matching target documents.
     *
     * @param object $target The target collection.
     * @param mixed $config Counter configuration.
     * @param array<string, mixed> $conditions Base conditions.
     * @return int|false
     */
    private function count(object $target, mixed $config, array $conditions): int|false
    {
        if (!is_callable([$target, 'find'])) {
            return false;
        }

        $finder = (string)($config['finder'] ?? 'all');
        $conditions = array_merge($conditions, is_array($config['conditions'] ?? null) ? $config['conditions'] : []);
        $query = call_user_func([$target, 'find'], $finder);
        if (!is_object($query) || !is_callable([$query, 'where']) || !is_callable([$query, 'count'])) {
            return false;
        }

        $filtered = call_user_func([$query, 'where'], $conditions);

        return is_object($filtered) && is_callable([$filtered, 'count'])
            ? (int)call_user_func([$filtered, 'count'])
            : false;
    }
}

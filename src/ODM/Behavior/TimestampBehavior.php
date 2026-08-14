<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Behavior;

use ArrayObject;
use AssertionError;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\I18n\DateTime as CakeDateTime;
use Crustum\Mongo\ODM\Behavior;
use DateTimeInterface;
use UnexpectedValueException;

/**
 * Automatically updates configured timestamp fields with BSON UTC dates.
 *
 * @see cake60/src/ORM/Behavior/TimestampBehavior.php
 */
class TimestampBehavior extends Behavior
{
    /**
     * Timestamp fields and event configuration.
     *
     * @var array<string, mixed>
     */
    protected array $defaultConfig = [
        'events' => ['Collection.beforeSave' => ['created' => 'new', 'modified' => 'always']],
        'refreshTimestamp' => true,
    ];

    /**
     * Cached timestamp shared by fields in one event cycle.
     *
     * @var \Cake\I18n\DateTime|null
     */
    private ?CakeDateTime $timestampValue = null;

    /**
     * Initializes timestamp event configuration.
     *
     * @param array<string, mixed> $config Behavior configuration.
     * @return void
     */
    public function initialize(array $config): void
    {
        if (isset($config['events'])) {
            $this->setConfig('events', $config['events'], false);
        }
    }

    /**
     * Gets the configured timestamp event callbacks.
     *
     * @return array<string, string>
     */
    public function implementedEvents(): array
    {
        $events = $this->getConfig('events');
        if (!is_array($events)) {
            return [];
        }

        return array_fill_keys(array_keys($events), 'handleEvent');
    }

    /**
     * Handles a configured model event.
     *
     * @param \Cake\Event\EventInterface<object> $event The dispatched event.
     * @param \Cake\Datasource\EntityInterface $entity The affected document.
     * @param \ArrayObject<string, mixed>|null $options Event options.
     * @return void
     * @throws \UnexpectedValueException If a timestamp condition is invalid.
     */
    public function handleEvent(EventInterface $event, EntityInterface $entity, ?ArrayObject $options = null): void
    {
        $events = $this->getConfig('events');
        $fields = is_array($events) ? ($events[$event->getName()] ?? []) : [];
        if (!is_array($fields)) {
            return;
        }

        foreach ($fields as $field => $when) {
            if (!in_array($when, ['always', 'new', 'existing'], true)) {
                throw new UnexpectedValueException(sprintf(
                    'When should be one of "always", "new" or "existing". The passed value `%s` is invalid.',
                    (string)$when,
                ));
            }

            if ($when === 'always' || ($when === 'new' && $entity->isNew()) || ($when === 'existing' && !$entity->isNew())) {
                $this->updateField($entity, (string)$field);
            }
        }
    }

    /**
     * Returns a reusable Cake timestamp.
     *
     * @param \DateTimeInterface|null $timestamp Explicit timestamp to use.
     * @param bool $refresh Whether to refresh the cached timestamp.
     * @return \Cake\I18n\DateTime
     */
    public function timestamp(?DateTimeInterface $timestamp = null, bool $refresh = false): CakeDateTime
    {
        if ($timestamp instanceof DateTimeInterface) {
            $this->setConfig('refreshTimestamp', false);

            return $this->timestampValue = new CakeDateTime($timestamp);
        }

        if (!$this->timestampValue instanceof CakeDateTime || $refresh) {
            $this->timestampValue = new CakeDateTime();
        }

        return $this->timestampValue;
    }

    /**
     * Updates timestamp fields for an event.
     *
     * @param \Cake\Datasource\EntityInterface $entity The affected document.
     * @param string $eventName The configured event name.
     * @return bool Whether a field was updated.
     */
    public function touch(EntityInterface $entity, string $eventName = 'Collection.beforeSave'): bool
    {
        $events = $this->getConfig('events');
        $fields = is_array($events) ? ($events[$eventName] ?? []) : [];
        if (!is_array($fields)) {
            return false;
        }

        $updated = false;
        foreach ($fields as $field => $when) {
            if (in_array($when, ['always', 'existing'], true)) {
                $entity->setDirty((string)$field, false);
                $this->updateField($entity, (string)$field, true);
                $updated = true;
            }
        }

        return $updated;
    }

    /**
     * Sets one timestamp field when it has not already been changed.
     *
     * @param \Cake\Datasource\EntityInterface $entity The affected document.
     * @param string $field The field to update.
     * @param bool $refresh Whether to refresh the timestamp.
     * @return void
     */
    private function updateField(EntityInterface $entity, string $field, bool $refresh = false): void
    {
        if ($entity->isDirty($field)) {
            return;
        }

        $schema = $this->collection->getSchema();
        $columnType = $schema->getColumnType($field);
        if (!$columnType) {
            return;
        }

        if (!in_array($columnType, ['datetime', 'datetimefractional', 'timestamp', 'date'], true)) {
            throw new AssertionError(
                'TimestampBehavior only supports columns of type `Cake\Database\Type\DateTimeType`.',
            );
        }

        $entity->set($field, $this->timestamp(null, $refresh && (bool)$this->getConfig('refreshTimestamp')));
    }
}

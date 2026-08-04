<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Behavior;

use ArrayObject;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Crustum\Mongo\ODM\Behavior;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use MongoDB\BSON\UTCDateTime;
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
        'events' => ['Model.beforeSave' => ['created' => 'new', 'modified' => 'always']],
        'refreshTimestamp' => true,
    ];

    /**
     * Cached timestamp shared by fields in one event cycle.
     *
     * @var \MongoDB\BSON\UTCDateTime|null
     */
    private ?UTCDateTime $timestampValue = null;

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
                throw new UnexpectedValueException(sprintf('Invalid timestamp condition `%s`.', (string)$when));
            }
            if ($when === 'always' || ($when === 'new' && $entity->isNew()) || ($when === 'existing' && !$entity->isNew())) {
                $this->updateField($entity, (string)$field);
            }
        }
    }

    /**
     * Returns a reusable BSON UTC timestamp.
     *
     * @param \DateTimeInterface|null $timestamp Explicit timestamp to use.
     * @param bool $refresh Whether to refresh the cached timestamp.
     * @return \MongoDB\BSON\UTCDateTime
     */
    public function timestamp(?DateTimeInterface $timestamp = null, bool $refresh = false): UTCDateTime
    {
        if ($timestamp !== null) {
            $this->setConfig('refreshTimestamp', false);

            return $this->timestampValue = new UTCDateTime($timestamp);
        }
        if ($this->timestampValue === null || $refresh) {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $this->timestampValue = new UTCDateTime($now);
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
    public function touch(EntityInterface $entity, string $eventName = 'Model.beforeSave'): bool
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
        $entity->set($field, $this->timestamp(null, $refresh && (bool)$this->getConfig('refreshTimestamp')));
    }
}

<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Behavior;

use ArrayObject;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Crustum\Mongo\ODM\Behavior;
use Crustum\Mongo\ODM\Query\SelectQuery;
use DateTimeImmutable;
use DateTimeZone;
use MongoDB\BSON\UTCDateTime;

/**
 * Soft-deletes documents by setting a BSON deletion timestamp.
 *
 * @see cake60/src/ORM/Behavior.php
 */
class SoftDeleteBehavior extends Behavior
{
    /**
     * Soft-delete field and query option configuration.
     *
     * @var array<string, mixed>
     */
    protected array $defaultConfig = ['field' => 'deleted_at', 'withDeletedOption' => 'withDeleted'];

    /**
     * Adds the soft-delete condition to primary queries.
     *
     * @param \Cake\Event\EventInterface<object> $event The dispatched event.
     * @param \Crustum\Mongo\ODM\Query\SelectQuery $query The query being built.
     * @param \ArrayObject<string, mixed> $options Query options.
     * @param bool $primary Whether this is the primary query.
     * @return void
     */
    public function beforeFind(EventInterface $event, SelectQuery $query, ArrayObject $options, bool $primary = true): void
    {
        if (($options[$this->getConfig('withDeletedOption')] ?? false) === true) {
            return;
        }

        $query->where([$this->getConfig('field') . ' IS' => null]);
    }

    /**
     * Replaces a delete with a timestamp update unless forced.
     *
     * @param \Cake\Event\EventInterface<object> $event The dispatched event.
     * @param \Cake\Datasource\EntityInterface $document The document being deleted.
     * @param \ArrayObject<string, mixed> $options Delete options.
     * @return void
     */
    public function beforeDelete(EventInterface $event, EntityInterface $document, ArrayObject $options): void
    {
        if (($options['forceDelete'] ?? false) === true) {
            return;
        }

        $field = (string)$this->getConfig('field');
        $conditions = ['_id' => $document->get('_id')];
        $this->collection()->updateAll(
            [$field => new UTCDateTime(new DateTimeImmutable('now', new DateTimeZone('UTC')))],
            $conditions,
        );

        $this->collection()->dispatchEvent('Collection.afterSoftDelete', [
            'document' => $document,
            'options' => $options,
        ]);

        $event->stopPropagation();
        $event->setResult(true);
    }
}

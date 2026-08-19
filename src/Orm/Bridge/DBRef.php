<?php
declare(strict_types=1);

namespace Crustum\Mongo\Orm\Bridge;

use Cake\Datasource\EntityInterface;
use Cake\Utility\Inflector;
use function Cake\Core\pluginSplit;

/**
 * DBRef cross-boundary association (Direction 1).
 *
 * A SQL column stores a Mongo DBRef pointer (`{ $ref, $id }`); the association
 * loads the referenced document. The `$id` may be stored either as the DBRef
 * array itself or as the raw Mongo `_id` value.
 *
 * @see docs/reference/29-orm-mongo-association-bridge.md §5.5
 */
class DBRef extends Association
{
    /**
     * @inheritDoc
     */
    public function loadByKeys(array $keys): array
    {
        $collection = $this->getTarget();
        $bindingKey = $this->bindingKey();

        $ids = [];
        foreach ($keys as $key) {
            $value = $this->extractDbrefId($key);
            if ($value !== null && $value !== '') {
                $ids[(string)$value] = $value;
            }
        }

        $documents = $ids === []
            ? []
            : $collection->find()
                ->where([$bindingKey . ' IN' => array_values($ids)])
                ->toArray();

        $map = [];
        foreach ($documents as $document) {
            $key = $document instanceof EntityInterface
                ? $document->get($bindingKey)
                : ($document[$bindingKey] ?? null);
            if ($key !== null) {
                $map[(string)$key] = $document;
            }
        }

        return $map;
    }

    /**
     * Direction-1 save is a no-op for DBRef.
     *
     * The foreign key lives on the SQL column; the referenced document already
     * exists. The base `Association::save()` would marshal the value into a
     * bogus new target document stamped with the source binding key, so it
     * must not run during `saveWithBridge()`.
     *
     * @param \Cake\Datasource\EntityInterface $entity The source entity.
     * @param mixed $value The dirty association value.
     * @return bool Whether the write succeeded.
     */
    public function save(EntityInterface $entity, mixed $value): bool
    {
        return true;
    }

    /**
     * Extracts the `$id` from a DBRef array or returns the raw value.
     *
     * @param mixed $value The stored column value.
     * @return mixed
     */
    protected function extractDbrefId(mixed $value): mixed
    {
        if (is_array($value) && array_key_exists('$id', $value)) {
            return $value['$id'];
        }

        return $value;
    }

    /**
     * @inheritDoc
     */
    public function injectRow(EntityInterface|array $row, array $map, ?string $nestKey = null): EntityInterface|array
    {
        $id = $this->extractSourceKey($row);
        $loaded = $id !== null ? ($map[(string)$id] ?? $this->emptyValue()) : $this->emptyValue();
        $this->attachToRow($row, $this->wrapValue($loaded), $nestKey);

        return $row;
    }

    /**
     * @inheritDoc
     */
    protected function extractSourceKey(EntityInterface|array $row): mixed
    {
        $value = $this->extractField($row, $this->sourceKeyField());

        return $value !== null ? $this->extractDbrefId($value) : null;
    }

    /**
     * @inheritDoc
     */
    protected function sourceKeyField(): string
    {
        return $this->foreignKey();
    }

    /**
     * @inheritDoc
     */
    protected function emptyValue(): mixed
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    protected function defaultForeignKey(): string
    {
        [, $name] = pluginSplit($this->getName());

        return Inflector::underscore(Inflector::singularize($name)) . '_ref';
    }

    /**
     * @inheritDoc
     */
    protected function defaultBindingKey(): string
    {
        return '_id';
    }

    /**
     * @inheritDoc
     */
    protected function defaultProperty(): string
    {
        [, $name] = pluginSplit($this->getName());

        return Inflector::underscore(Inflector::singularize($name));
    }

    /**
     * Single foreign key (string) read from the source row.
     *
     * @return string
     */
    protected function foreignKey(): string
    {
        $key = $this->getForeignKey();

        return is_array($key) ? ($key[0] ?? '') : $key;
    }

    /**
     * Single binding key (string) used in the batched `whereIn`.
     *
     * @return string
     */
    protected function bindingKey(): string
    {
        $key = $this->getBindingKey();

        return is_array($key) ? ($key[0] ?? '_id') : $key;
    }
}

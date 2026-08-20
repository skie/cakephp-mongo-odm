<?php
declare(strict_types=1);

namespace Crustum\Mongo\Orm\Bridge;

use Cake\Datasource\EntityInterface;
use Cake\Utility\Inflector;
use Override;
use function Cake\Core\pluginSplit;

/**
 * BelongsTo cross-boundary association (Direction 1).
 *
 * A SQL ORM row holds a Mongo `_id` in a column (convention `{name}_id`); the
 * association loads the matching Mongo `Document`. This is the Mongo mirror of
 * `Cake\ORM\Association\BelongsTo`.
 *
 * @see docs/reference/29-orm-mongo-association-bridge.md §5.1
 */
class BelongsTo extends Association
{
    /**
     * @inheritDoc
     */
    public function loadByKeys(array $keys): array
    {
        $collection = $this->getTarget();
        $bindingKey = $this->bindingKey();

        $query = $collection->find()->where([$bindingKey . ' IN' => $keys]);
        if ($this->getConditions() !== []) {
            $query->where($this->getConditions());
        }

        $documents = $query->toArray();

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
     * Direction-1 save is a no-op for BelongsTo.
     *
     * The foreign key lives on the SQL column; the referenced Mongo document
     * already exists. The base `Association::save()` would marshal the value
     * into a bogus new target document stamped with the source binding key, so
     * it must not run during `saveWithBridge()`.
     *
     * @param \Cake\Datasource\EntityInterface $entity The source entity.
     * @param mixed $value The dirty association value.
     * @return bool Whether the write succeeded.
     */
    #[Override]
    public function save(EntityInterface $entity, mixed $value): bool
    {
        return true;
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
        return $this->modelKey($this->getTarget()->getAlias());
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
    #[Override]
    protected function defaultProperty(): string
    {
        [, $name] = pluginSplit($this->name);

        return Inflector::underscore(Inflector::singularize($name));
    }

    /**
     * Single binding key (string) used in the batched `whereIn`.
     *
     * @return string
     */
    #[Override]
    protected function bindingKey(): string
    {
        $key = $this->getBindingKey();

        return is_array($key) ? ($key[0] ?? '_id') : $key;
    }

    /**
     * Single foreign key (string) read from the source row.
     *
     * @return string
     */
    #[Override]
    protected function foreignKey(): string
    {
        $key = $this->getForeignKey();

        return is_array($key) ? ($key[0] ?? '') : $key;
    }
}

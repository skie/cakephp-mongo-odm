<?php
declare(strict_types=1);

namespace Crustum\Mongo\Orm\Bridge;

use Cake\Datasource\EntityInterface;
use Cake\Utility\Inflector;
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
    protected function foreignKey(): string
    {
        $key = $this->getForeignKey();

        return is_array($key) ? ($key[0] ?? '') : $key;
    }
}

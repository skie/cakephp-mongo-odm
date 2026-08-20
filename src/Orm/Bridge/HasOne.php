<?php
declare(strict_types=1);

namespace Crustum\Mongo\Orm\Bridge;

use Cake\Datasource\EntityInterface;
use Cake\Utility\Inflector;
use Override;
use function Cake\Core\pluginSplit;

/**
 * HasOne cross-boundary association (Direction 1).
 *
 * A Mongo document holds the SQL source row's primary key in a `foreignKey`
 * field (unique); the association loads the single matching document. This is
 * the Mongo mirror of `Cake\ORM\Association\HasOne`.
 *
 * @see docs/reference/29-orm-mongo-association-bridge.md §5.2
 */
class HasOne extends Association
{
    /**
     * @inheritDoc
     */
    public function loadByKeys(array $keys): array
    {
        $collection = $this->getTarget();
        $foreignKey = $this->foreignKey();

        $query = $collection->find()->where([$foreignKey . ' IN' => $keys]);
        if ($this->getConditions() !== []) {
            $query->where($this->getConditions());
        }

        $documents = $query->toArray();

        $map = [];
        foreach ($documents as $document) {
            $value = $document instanceof EntityInterface
                ? $document->get($foreignKey)
                : ($document[$foreignKey] ?? null);
            if ($value !== null && !isset($map[(string)$value])) {
                $map[(string)$value] = $document;
            }
        }

        return $map;
    }

    /**
     * @inheritDoc
     */
    protected function sourceKeyField(): string
    {
        return $this->bindingKey();
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
    #[Override]
    public function cascadeDelete(EntityInterface $entity, array $options = []): bool
    {
        if (!$this->getDependent()) {
            return true;
        }

        $collection = $this->getTarget();
        $fkValue = $entity->get($this->bindingKey());
        if ($fkValue === null) {
            return true;
        }

        $collection->deleteAll([$this->foreignKey() => $fkValue]);

        return true;
    }

    /**
     * @inheritDoc
     */
    protected function defaultForeignKey(): string
    {
        return $this->modelKey($this->getSource()->getAlias());
    }

    /**
     * @inheritDoc
     */
    protected function defaultBindingKey(): string
    {
        $pk = $this->getSource()->getPrimaryKey();

        return is_array($pk) ? ($pk[0] ?? '_id') : $pk;
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
     * Single foreign key (string) used in the batched `whereIn`.
     *
     * @return string
     */
    #[Override]
    protected function foreignKey(): string
    {
        $key = $this->getForeignKey();

        return is_array($key) ? ($key[0] ?? '') : $key;
    }

    /**
     * Single binding key (string) read from the source row.
     *
     * @return string
     */
    #[Override]
    protected function bindingKey(): string
    {
        $key = $this->getBindingKey();

        return is_array($key) ? ($key[0] ?? '_id') : $key;
    }
}

<?php
declare(strict_types=1);

namespace Crustum\Mongo\Orm\Bridge;

use Cake\Datasource\EntityInterface;

/**
 * HasMany cross-boundary association (Direction 1).
 *
 * Mongo documents hold the SQL source row's primary key in a `foreignKey`
 * field (convention `{source}_id`); the association loads all matching
 * documents into an array property. This is the Mongo mirror of
 * `Cake\ORM\Association\HasMany` (the generalized zulucare file-association
 * case).
 *
 * @see docs/reference/29-orm-mongo-association-bridge.md §5.3
 */
class HasMany extends Association
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
            if ($value !== null) {
                $map[(string)$value][] = $document;
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
     * Persists a list of documents, each carrying the source foreign key.
     *
     * @param \Cake\Datasource\EntityInterface $entity The source entity.
     * @param mixed $value List of document data rows.
     * @return bool
     */
    public function save(EntityInterface $entity, mixed $value): bool
    {
        $fkValue = $entity->get($this->bindingKey());
        $collection = $this->getTarget();

        $documents = [];
        foreach ((array)$value as $data) {
            if (!is_array($data)) {
                continue;
            }
            $data[$this->foreignKey()] = $fkValue;
            $documents[] = $collection->newDocument($data);
        }

        return $collection->saveMany($documents) !== false;
    }

    /**
     * @inheritDoc
     */
    protected function emptyValue(): mixed
    {
        return [];
    }

    /**
     * @inheritDoc
     */
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

        $conditions = [$this->foreignKey() => $fkValue];
        if ($this->getDependent() === 'nullify') {
            $collection->updateAll([$this->foreignKey() => null], $conditions);

            return true;
        }

        $collection->deleteAll($conditions);

        return true;
    }

    /**
     * @inheritDoc
     */
    protected function defaultForeignKey(): string
    {
        return $this->modelKey($this->getSource()->getTable());
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
     * Single foreign key (string) used in the batched `whereIn`.
     *
     * @return string
     */
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
    protected function bindingKey(): string
    {
        $key = $this->getBindingKey();

        return is_array($key) ? ($key[0] ?? '_id') : $key;
    }
}

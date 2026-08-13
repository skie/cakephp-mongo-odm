<?php
declare(strict_types=1);

namespace Crustum\Mongo\Orm\Bridge;

use Cake\Datasource\EntityInterface;
use Cake\Utility\Inflector;
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
    public function load(iterable $entities): void
    {
        $collection = $this->getTarget();
        $foreignKey = $this->foreignKey();
        $bindingKey = $this->bindingKey();

        $rows = [];
        $ids = [];
        foreach ($entities as $row) {
            $rows[] = $row;
            $value = $this->extractField($row, $bindingKey);
            if ($value !== null && $value !== '') {
                $ids[(string)$value] = $value;
            }
        }

        $map = [];
        if ($ids !== []) {
            $query = $collection->find()->where([$foreignKey . ' IN' => array_values($ids)]);
            if ($this->getConditions() !== []) {
                $query->where($this->getConditions());
            }

            $documents = $query->toArray();
            foreach ($documents as $document) {
                $value = $document instanceof EntityInterface
                    ? $document->get($foreignKey)
                    : ($document[$foreignKey] ?? null);
                if ($value !== null && !isset($map[(string)$value])) {
                    $map[(string)$value] = $document;
                }
            }
        }

        foreach ($rows as $row) {
            $value = $this->extractField($row, $bindingKey);
            $loaded = $value !== null ? ($map[(string)$value] ?? null) : null;
            $this->attachToRow($row, $loaded);
        }
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

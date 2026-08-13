<?php
declare(strict_types=1);

namespace Crustum\Mongo\Orm\Bridge\Orm;

/**
 * Direction-2 `hasMany` bridge association (Mongo doc → SQL rows).
 *
 * SQL rows hold the Mongo document key in a `foreignKey` column; the
 * association loads all matching SQL rows into an array property.
 *
 * @see docs/reference/29-orm-mongo-association-bridge.md §8
 */
class HasManyOrm extends OrmAssociation
{
    /**
     * @inheritDoc
     */
    public function loadByKeys(array $keys): array
    {
        $target = $this->getTarget();
        $foreignKey = $this->foreignKey();

        $query = $target->find()
            ->where([$target->aliasField($foreignKey) . ' IN' => $keys]);
        if ($this->getConditions() !== []) {
            $query->where($this->getConditions());
        }

        $rows = $query->all();

        $map = [];
        foreach ($rows as $row) {
            $value = $row->get($foreignKey);
            if ($value !== null) {
                $map[(string)$value][] = $row;
            }
        }

        return $map;
    }

    /**
     * @inheritDoc
     */
    protected function sourceKeyField(): string
    {
        return '_id';
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
    protected function defaultForeignKey(): string
    {
        return $this->modelKey($this->getSource()->getCollection());
    }

    /**
     * @inheritDoc
     */
    protected function defaultBindingKey(): string
    {
        $pk = $this->getTarget()->getPrimaryKey();

        return is_array($pk) ? ($pk[0] ?? '_id') : $pk;
    }
}

<?php
declare(strict_types=1);

namespace Crustum\Mongo\Orm\Bridge\Orm;

use Cake\Utility\Inflector;
use function Cake\Core\pluginSplit;

/**
 * Direction-2 `belongsTo` bridge association (Mongo doc → SQL row).
 *
 * The SQL target row holds the Mongo `_id` in a `foreignKey` column; the
 * association loads the single matching SQL row.
 *
 * @see docs/reference/29-orm-mongo-association-bridge.md §8
 */
class BelongsToOrm extends OrmAssociation
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
            $key = $row->get($foreignKey);
            if ($key !== null) {
                $map[(string)$key] = $row;
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
        return null;
    }

    /**
     * @inheritDoc
     */
    protected function defaultForeignKey(): string
    {
        [, $name] = pluginSplit($this->name);

        return Inflector::underscore(Inflector::singularize($name)) . '_id';
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
}

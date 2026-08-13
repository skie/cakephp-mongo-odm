<?php
declare(strict_types=1);

namespace Crustum\Mongo\Orm\Bridge;

/**
 * BelongsToMany cross-boundary association (Direction 1).
 *
 * N:N between a SQL source row and Mongo target documents, via either a
 * junction collection (`{source}_{target}`) or an in-document `*_ids` array
 * pivot. Load-only in P4; save/cascade comes in P3/P5.
 *
 * @see docs/reference/29-orm-mongo-association-bridge.md §5.4
 */
class BelongsToMany extends Association
{
    /**
     * @inheritDoc
     */
    public function loadByKeys(array $keys): array
    {
        // Implemented in P4 (junction + array pivot load).
        return [];
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
        return [];
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

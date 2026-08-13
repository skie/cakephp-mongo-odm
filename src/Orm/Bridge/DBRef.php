<?php
declare(strict_types=1);

namespace Crustum\Mongo\Orm\Bridge;

/**
 * DBRef cross-boundary association (Direction 1).
 *
 * A SQL column stores a Mongo DBRef pointer (`{ $ref, $id }`); the association
 * loads the referenced document. Load-only in P4.
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
        // Implemented in P4 (DBRef pointer load).
        return [];
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
        return lcfirst($this->getName()) . '_ref';
    }

    /**
     * @inheritDoc
     */
    protected function defaultBindingKey(): string
    {
        return '_id';
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

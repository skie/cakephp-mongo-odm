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
    public function load(iterable $entities): void
    {
        // Implemented in P4 (DBRef pointer load).
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
}

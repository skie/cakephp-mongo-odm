<?php
declare(strict_types=1);

namespace Crustum\Mongo\Migration\Db\Action;

/**
 * Renames an existing collection.
 */
class RenameCollection extends Action
{
    /**
     * Constructor.
     *
     * @param string $from Current name
     * @param string $to New name
     * @param bool $dropTarget Whether to drop an existing target first
     */
    public function __construct(
        protected string $from,
        protected string $to,
        protected bool $dropTarget = false,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'renameCollection';
    }

    /**
     * Returns the source collection name.
     *
     * @return string
     */
    public function getFrom(): string
    {
        return $this->from;
    }

    /**
     * Returns the target collection name.
     *
     * @return string
     */
    public function getTo(): string
    {
        return $this->to;
    }

    /**
     * Whether the target should be dropped first.
     *
     * @return bool
     */
    public function getDropTarget(): bool
    {
        return $this->dropTarget;
    }
}

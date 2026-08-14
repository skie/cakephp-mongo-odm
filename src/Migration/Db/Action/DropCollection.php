<?php
declare(strict_types=1);

namespace Crustum\Mongo\Migration\Db\Action;

/**
 * Drops an existing collection.
 */
class DropCollection extends Action
{
    /**
     * Constructor.
     *
     * @param string $name Collection name
     */
    public function __construct(protected string $name)
    {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'dropCollection';
    }

    /**
     * Returns the collection name.
     *
     * @return string
     */
    public function getCollectionName(): string
    {
        return $this->name;
    }
}

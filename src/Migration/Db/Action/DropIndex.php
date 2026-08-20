<?php
declare(strict_types=1);

namespace Crustum\Mongo\Migration\Db\Action;

/**
 * Drops an index from a collection.
 *
 * @inspired-by \Migrations\Db\Action\DropIndex
 */
class DropIndex extends Action
{
    /**
     * Constructor.
     *
     * @param string $collection Collection name
     * @param string $indexName Index name
     */
    public function __construct(
        protected string $collection,
        protected string $indexName,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'dropIndex';
    }

    /**
     * Returns the collection name.
     *
     * @return string
     */
    public function getCollectionName(): string
    {
        return $this->collection;
    }

    /**
     * Returns the index name.
     *
     * @return string
     */
    public function getIndexName(): string
    {
        return $this->indexName;
    }
}

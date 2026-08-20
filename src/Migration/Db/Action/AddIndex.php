<?php
declare(strict_types=1);

namespace Crustum\Mongo\Migration\Db\Action;

/**
 * Creates an index on a collection.
 *
 * @inspired-by \Migrations\Db\Action\AddIndex
 */
class AddIndex extends Action
{
    /**
     * Constructor.
     *
     * @param string $collection Collection name
     * @param string $indexName Index name
     * @param array<string, int|string> $key Index key map
     * @param array<string, mixed> $options Index options
     */
    public function __construct(
        protected string $collection,
        protected string $indexName,
        protected array $key,
        protected array $options = [],
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'addIndex';
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

    /**
     * Returns the index key map.
     *
     * @return array<string, int|string>
     */
    public function getKey(): array
    {
        return $this->key;
    }

    /**
     * Returns the index options.
     *
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }
}

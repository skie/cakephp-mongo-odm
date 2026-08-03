<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Schema;

use Cake\Datasource\SchemaInterface;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Datasource\SchemaCollectionInterface;
use MongoDB\Exception\RuntimeException;

/**
 * Introspects MongoDB collections and builds `CollectionSchema` instances.
 *
 * Implements the Datasource `SchemaCollectionInterface` contract (the
 * Datasource layer stays free of Database imports — this concrete lives in the
 * Database layer where the connection is available).
 *
 * @see cake50/src/Database/Schema/Collection.php
 */
class SchemaCollection implements SchemaCollectionInterface
{
    /**
     * The connection to introspect.
     *
     * @var \Crustum\Mongo\Database\Connection
     */
    protected Connection $connection;

    /**
     * Constructor.
     *
     * @param \Crustum\Mongo\Database\Connection $connection The connection.
     */
    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @inheritDoc
     */
    public function listCollections(): array
    {
        try {
            $names = [];
            foreach ($this->connection->getDatabase()->listCollections() as $collection) {
                $names[] = $collection->getName();
            }

            return $names;
        } catch (RuntimeException) {
            return [];
        }
    }

    /**
     * Cake-compatible alias of `listCollections()`.
     *
     * @return list<string>
     */
    public function listTables(): array
    {
        return $this->listCollections();
    }

    /**
     * @inheritDoc
     */
    public function describe(string $name): SchemaInterface
    {
        return new CollectionSchema(
            $name,
            $this->connection->getCollection($name),
            $this->connection->getDatabase(),
        );
    }

    /**
     * @inheritDoc
     */
    public function clearCache(?string $name = null): void
    {
        // Schema instances are constructed on demand; nothing is cached yet.
    }
}

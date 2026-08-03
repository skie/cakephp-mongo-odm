<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Query;

use Crustum\Mongo\Database\Connection;

/**
 * Creates the four query types bound to a connection.
 *
 * @see cake50/src/Database/Query/QueryFactory.php
 */
class QueryFactory
{
    /**
     * The connection queries are bound to.
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
     * Returns a select query.
     *
     * @param string $collection The target collection.
     * @return \Crustum\Mongo\Database\Query\SelectQuery
     */
    public function select(string $collection = ''): SelectQuery
    {
        return new SelectQuery($this->connection, $collection);
    }

    /**
     * Returns an insert query.
     *
     * @param string $collection The target collection.
     * @return \Crustum\Mongo\Database\Query\InsertQuery
     */
    public function insert(string $collection = ''): InsertQuery
    {
        return new InsertQuery($this->connection, $collection);
    }

    /**
     * Returns an update query.
     *
     * @param string $collection The target collection.
     * @return \Crustum\Mongo\Database\Query\UpdateQuery
     */
    public function update(string $collection = ''): UpdateQuery
    {
        return new UpdateQuery($this->connection, $collection);
    }

    /**
     * Returns a delete query.
     *
     * @param string $collection The target collection.
     * @return \Crustum\Mongo\Database\Query\DeleteQuery
     */
    public function delete(string $collection = ''): DeleteQuery
    {
        return new DeleteQuery($this->connection, $collection);
    }
}

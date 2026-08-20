<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Query;

use Cake\Database\ExpressionInterface;
use Closure;
use Crustum\Mongo\Database\Connection;

/**
 * Creates the four query types bound to a connection.
 *
 * @ported-from \Cake\Database\Query\QueryFactory
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
     * @param \Cake\Database\ExpressionInterface|\Closure|array<int|string, mixed>|string|float|int $fields Fields for the select clause.
     * @param array<int, string>|string $collection The collection(s) to query.
     * @param array<int|string, string> $types Field => type map used to cast values.
     * @return \Crustum\Mongo\Database\Query\SelectQuery
     */
    public function select(
        ExpressionInterface|Closure|array|string|float|int $fields = [],
        array|string $collection = [],
        array $types = [],
    ): SelectQuery {
        $query = new SelectQuery($this->connection);

        $query->select($fields);
        if (is_string($collection) ? $collection !== '' : $collection !== []) {
            $query->from(is_array($collection) ? reset($collection) : $collection);
        }

        $query->setDefaultTypes($types);

        return $query;
    }

    /**
     * Returns an insert query.
     *
     * @param string|null $collection The collection to insert into.
     * @param array<string, mixed> $values The document to insert.
     * @param array<int|string, string> $types Field => type map used to cast values.
     * @return \Crustum\Mongo\Database\Query\InsertQuery
     */
    public function insert(?string $collection = null, array $values = [], array $types = []): InsertQuery
    {
        $query = new InsertQuery($this->connection);

        if ($collection !== null) {
            $query->into($collection);
        }

        if ($values !== []) {
            $query->insert(array_keys($values))->values($values);
        }

        return $query;
    }

    /**
     * Returns an update query.
     *
     * @param string|null $collection The collection to update.
     * @param array<string, mixed> $values The update assignments.
     * @param array<string, mixed> $conditions The filter conditions.
     * @param array<int|string, string> $types Field => type map used to cast values.
     * @return \Crustum\Mongo\Database\Query\UpdateQuery
     */
    public function update(
        ?string $collection = null,
        array $values = [],
        array $conditions = [],
        array $types = [],
    ): UpdateQuery {
        $query = new UpdateQuery($this->connection);

        if ($collection !== null) {
            $query->update($collection);
        }

        if ($values !== []) {
            $query->set($values, $types);
        }

        if ($conditions !== []) {
            $query->where($conditions, $types);
        }

        return $query;
    }

    /**
     * Returns a delete query.
     *
     * @param string|null $collection The collection to delete from.
     * @param array<string, mixed> $conditions The filter conditions.
     * @param array<int|string, string> $types Field => type map used to cast values.
     * @return \Crustum\Mongo\Database\Query\DeleteQuery
     */
    public function delete(?string $collection = null, array $conditions = [], array $types = []): DeleteQuery
    {
        $query = new DeleteQuery($this->connection);
        $query->delete($collection);

        if ($conditions !== []) {
            $query->where($conditions, $types);
        }

        return $query;
    }
}

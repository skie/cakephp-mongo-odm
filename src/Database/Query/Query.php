<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Query;

use Cake\Core\Exception\CakeException;
use Crustum\Mongo\Database\Connection;

/**
 * Base query class for MongoDB.
 *
 * All query types share a connection, a target collection and a `QueryCompiler`
 * (filter/projection/sort/limit/pipeline → compiled shape).
 *
 * @see cake50/src/Database/Query/Query.php
 */
abstract class Query
{
    public const string TYPE_SELECT = 'find';

    public const string TYPE_INSERT = 'insert';

    public const string TYPE_UPDATE = 'update';

    public const string TYPE_DELETE = 'delete';

    /**
     * @var \Crustum\Mongo\Database\Connection|null
     */
    protected ?Connection $connection;

    /**
     * The query compiler.
     *
     * @var \Crustum\Mongo\Database\Query\QueryCompiler
     */
    protected QueryCompiler $builder;

    /**
     * The target collection name.
     *
     * @var string
     */
    protected string $collection;

    /**
     * Constructor.
     *
     * @param \Crustum\Mongo\Database\Connection|null $connection The connection to execute on.
     * @param string $collection The target collection name.
     */
    public function __construct(?Connection $connection = null, string $collection = '')
    {
        $this->connection = $connection;
        $this->collection = $collection;
        $this->builder = new QueryCompiler();
    }

    /**
     * Returns the connection the query executes on.
     *
     * @return \Crustum\Mongo\Database\Connection|null
     */
    public function getConnection(): ?Connection
    {
        return $this->connection;
    }

    /**
     * Sets the connection.
     *
     * @param \Crustum\Mongo\Database\Connection $connection The connection.
     * @return $this
     */
    public function setConnection(Connection $connection): static
    {
        $this->connection = $connection;

        return $this;
    }

    /**
     * Sets the target collection.
     *
     * @param string $collection The collection name.
     * @return $this
     */
    public function from(string $collection): static
    {
        $this->collection = $collection;

        return $this;
    }

    /**
     * Returns the target collection name.
     *
     * @return string
     */
    public function getCollection(): string
    {
        return $this->collection;
    }

    /**
     * Returns the query compiler.
     *
     * @return \Crustum\Mongo\Database\Query\QueryCompiler
     */
    public function getBuilder(): QueryCompiler
    {
        return $this->builder;
    }

    /**
     * Compiles the query into its executable shape.
     *
     * @return array<string, mixed>
     */
    public function compile(): array
    {
        $compiled = $this->builder->compile();
        $compiled['collection'] = $this->collection;

        return $compiled;
    }

    /**
     * Returns a JSON representation of the compiled query (for logging).
     *
     * @return string
     */
    public function sql(): string
    {
        return (string)json_encode($this->compile(), JSON_PRETTY_PRINT);
    }

    /**
     * Executes the query on its connection.
     *
     * @return mixed The result of the underlying MongoDB operation.
     * @throws \Cake\Core\Exception\CakeException When no connection is set.
     */
    public function execute(): mixed
    {
        if (!$this->connection instanceof Connection) {
            throw new CakeException('Query has no connection set.');
        }

        return $this->connection->run($this);
    }
}

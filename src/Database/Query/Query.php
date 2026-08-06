<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Query;

use Cake\Core\Exception\CakeException;
use Cake\Database\ExpressionInterface;
use Closure;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Database\Driver\MongoDriver;
use Crustum\Mongo\Database\Expression\QueryExpression;
use Crustum\Mongo\Database\FunctionsBuilder;
use Crustum\Mongo\Database\TypeMapTrait;
use InvalidArgumentException;
use Stringable;

/**
 * Base query class for MongoDB.
 *
 * All query types share a connection, a target collection and a `QueryCompiler`
 * (filter/projection/sort/limit/pipeline → compiled shape).
 *
 * @see cake50/src/Database/Query/Query.php
 */
abstract class Query implements Stringable
{
    use TypeMapTrait;

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
     * The functions builder for this query.
     *
     * @var \Crustum\Mongo\Database\FunctionsBuilder|null
     */
    protected ?FunctionsBuilder $functionsBuilder = null;

    /**
     * The target collection name.
     *
     * @var string
     */
    protected string $collection;

    /**
     * Whether the query state was modified since the last execution.
     *
     * Used to discard internal caches (compiled query, executed results) and
     * to apply execution-time optimizations like `first()`'s `limit(1)`.
     *
     * @var bool
     */
    protected bool $dirty = false;

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

        $driver = $connection instanceof Connection && $connection->getDriver() instanceof MongoDriver
            ? $connection->getDriver()
            : null;
        $this->builder = new QueryCompiler($driver);
    }

    /**
     * Marks the query as dirty, discarding any cached pre-execution state.
     *
     * @return void
     */
    protected function dirty(): void
    {
        $this->dirty = true;
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
        $this->dirty();

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
     * Returns a functions builder for this query.
     *
     * @return \Crustum\Mongo\Database\FunctionsBuilder
     */
    public function func(): FunctionsBuilder
    {
        return $this->functionsBuilder ??= new FunctionsBuilder();
    }

    /**
     * Sets the filter conditions.
     *
     * A `Closure` receives `(QueryExpression $exp, static $query)` and must
     * return the conditions to merge into the filter.
     *
     * @param \Cake\Database\ExpressionInterface|\Closure|array<string, mixed>|string|null $conditions The conditions.
     * @param array<int|string, string>                                                    $types      Field => type map used to cast values.
     * @param bool                                                                         $overwrite  Whether to overwrite existing conditions.
     * @return $this
     */
    public function where(
        ExpressionInterface|Closure|array|string|null $conditions = [],
        array $types = [],
        bool $overwrite = false,
    ): static {
        if ($conditions instanceof Closure) {
            $exp = new QueryExpression();
            $conditions = $conditions($exp, $this) ?? $exp;
        }

        $types += $this->getDefaultTypes();
        $this->builder->where($conditions, $types, $overwrite);
        $this->dirty();

        return $this;
    }

    /**
     * Adds conditions with an `$and` operator.
     *
     * A `Closure` receives `(QueryExpression $exp, static $query)` and must
     * return the conditions to add.
     *
     * @param \Cake\Database\ExpressionInterface|\Closure|array<string, mixed>|string $conditions The conditions to add.
     * @param array<int|string, string>                                                $types      Field => type map used to cast values.
     * @return $this
     */
    public function andWhere(ExpressionInterface|Closure|array|string $conditions, array $types = []): static
    {
        if ($conditions instanceof Closure) {
            $exp = new QueryExpression();
            $conditions = $conditions($exp, $this) ?? $exp;
        }

        $types += $this->getDefaultTypes();
        $this->builder->andWhere($conditions, $types);
        $this->dirty();

        return $this;
    }

    /**
     * Adds an IS NULL condition for the given field(s).
     *
     * Compiles to `$exists => false` (Mongo has no null-equality semantics).
     *
     * @param \Cake\Database\ExpressionInterface|array<int, string>|string $fields A single field or list of fields.
     * @return $this
     */
    public function whereNull(ExpressionInterface|array|string $fields): static
    {
        $conditions = [];
        foreach (is_array($fields) ? $fields : [$fields] as $field) {
            $conditions[$field . ' IS'] = null;
        }

        return $this->where($conditions);
    }

    /**
     * Adds an IS NOT NULL condition for the given field(s).
     *
     * Compiles to `$exists => true` (Mongo has no null-equality semantics).
     *
     * @param \Cake\Database\ExpressionInterface|array<int, string>|string $fields A single field or list of fields.
     * @return $this
     */
    public function whereNotNull(ExpressionInterface|array|string $fields): static
    {
        $conditions = [];
        foreach (is_array($fields) ? $fields : [$fields] as $field) {
            $conditions[$field . ' IS NOT'] = null;
        }

        return $this->where($conditions);
    }

    /**
     * Adds an IN condition for a field.
     *
     * When `allowEmpty` is set and `$values` is empty, an always-false filter is
     * applied so no documents match.
     *
     * @param string $field The field name.
     * @param array<int, mixed> $values The values to match.
     * @param array<string, mixed> $options Options (`types`, `allowEmpty`).
     * @return $this
     */
    public function whereInList(string $field, array $values, array $options = []): static
    {
        $options += [
            'types' => [],
            'allowEmpty' => false,
        ];

        if ($options['allowEmpty'] && !$values) {
            return $this->where(['$expr' => ['$eq' => [1, 0]]]);
        }

        return $this->where([$field . ' IN' => $values], $options['types']);
    }

    /**
     * Adds a NOT IN condition for a field.
     *
     * When `allowEmpty` is set and `$values` is empty, an always-true filter is
     * applied so all documents match.
     *
     * @param string $field The field name.
     * @param array<int, mixed> $values The values to exclude.
     * @param array<string, mixed> $options Options (`types`, `allowEmpty`).
     * @return $this
     */
    public function whereNotInList(string $field, array $values, array $options = []): static
    {
        $options += [
            'types' => [],
            'allowEmpty' => false,
        ];

        if ($options['allowEmpty'] && !$values) {
            return $this->where(['$expr' => ['$eq' => [1, 1]]]);
        }

        return $this->where([$field . ' NOT IN' => $values], $options['types']);
    }

    /**
     * Adds a NOT IN condition that also allows the field to be null.
     *
     * When `allowEmpty` is set and `$values` is empty, an always-true filter is
     * applied so all documents match.
     *
     * @param string $field The field name.
     * @param array<int, mixed> $values The values to exclude.
     * @param array<string, mixed> $options Options (`types`, `allowEmpty`).
     * @return $this
     */
    public function whereNotInListOrNull(string $field, array $values, array $options = []): static
    {
        $options += [
            'types' => [],
            'allowEmpty' => false,
        ];

        if ($options['allowEmpty'] && !$values) {
            return $this->where(['$expr' => ['$eq' => [1, 1]]]);
        }

        return $this->where(
            [
                'OR' => [$field . ' NOT IN' => $values, $field . ' IS' => null],
            ],
            $options['types'],
        );
    }

    /**
     * Sets the sort order.
     *
     * A `Closure` receives the query and must return the fields to sort by.
     *
     * @param \Cake\Database\ExpressionInterface|\Closure|array<string, mixed>|string $fields Fields to sort by.
     * @param bool                                                                    $overwrite Whether to overwrite the existing sort.
     * @return $this
     */
    public function orderBy(ExpressionInterface|Closure|array|string $fields, bool $overwrite = false): static
    {
        if ($fields instanceof Closure) {
            $fields = $fields($this);
        }

        $this->builder->orderBy($fields, $overwrite);
        $this->dirty();

        return $this;
    }

    /**
     * Orders results ascending by a field.
     *
     * @param \Cake\Database\ExpressionInterface|\Closure|string $field Field name.
     * @param bool $overwrite Whether to overwrite the existing sort.
     * @return $this
     */
    public function orderByAsc(ExpressionInterface|Closure|string $field, bool $overwrite = false): static
    {
        return $this->orderBy([$field => 'ASC'], $overwrite);
    }

    /**
     * Orders results descending by a field.
     *
     * @param \Cake\Database\ExpressionInterface|\Closure|string $field Field name.
     * @param bool $overwrite Whether to overwrite the existing sort.
     * @return $this
     */
    public function orderByDesc(ExpressionInterface|Closure|string $field, bool $overwrite = false): static
    {
        return $this->orderBy([$field => 'DESC'], $overwrite);
    }

    /**
     * Sets the result limit.
     *
     * @param int|null $limit Number of results to return.
     * @return $this
     */
    public function limit(?int $limit): static
    {
        $this->builder->limit($limit);
        $this->dirty();

        return $this;
    }

    /**
     * Sets the number of results to skip.
     *
     * @param int|null $skip Number of results to skip.
     * @return $this
     */
    public function skip(?int $skip): static
    {
        $this->builder->skip($skip);
        $this->dirty();

        return $this;
    }

    /**
     * Sets the number of records to skip (alias for `skip()`).
     *
     * @param int|null $offset Number of rows to skip.
     * @return $this
     */
    public function offset(?int $offset): static
    {
        return $this->skip($offset);
    }

    /**
     * Sets the result page using limit/skip.
     *
     * Page numbers start at 1. When no limit is set it defaults to 25.
     *
     * @param int $page The page number.
     * @param int|null $limit The page size.
     * @return $this
     * @throws \InvalidArgumentException When the page number is below 1.
     */
    public function page(int $page, ?int $limit = null): static
    {
        if ($page < 1) {
            throw new InvalidArgumentException('Pages must start at 1.');
        }

        if ($limit !== null) {
            $this->limit($limit);
        }

        $limit = $this->builder->getLimit();
        if ($limit === null) {
            $limit = 25;
            $this->limit($limit);
        }

        $this->skip(($page - 1) * $limit);

        return $this;
    }

    /**
     * Returns the stored value of a query clause.
     *
     * Supports the Mongo clause names: `where`, `select`, `order`, `group`,
     * `having`, `limit`, `skip`, `offset`, `pipeline`, `options`.
     *
     * @param string $name Name of the clause to be returned.
     * @return mixed
     * @throws \InvalidArgumentException When the named clause does not exist.
     */
    public function clause(string $name): mixed
    {
        return match ($name) {
            'where' => $this->builder->getFilter(),
            'select' => $this->builder->getProjection(),
            'order' => $this->builder->getSort(),
            'group' => $this->builder->getGroup(),
            'limit' => $this->builder->getLimit(),
            'skip', 'offset' => $this->builder->getSkip(),
            'pipeline' => $this->builder->getPipeline(),
            'options' => $this->builder->getOptions(),
            default => throw new InvalidArgumentException(sprintf('Invalid clause `%s`.', $name)),
        };
    }

    /**
     * Returns an empty expression object for building conditions.
     *
     * @return \Crustum\Mongo\Database\Expression\QueryExpression
     */
    public function expr(): QueryExpression
    {
        return new QueryExpression();
    }

    /**
     * Deep-clones the query so the clone owns an independent compiler.
     */
    public function __clone()
    {
        $this->builder = clone $this->builder;
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

        $result = $this->connection->run($this);
        $this->dirty = false;

        return $result;
    }

    /**
     * Returns the compiled query for debugging.
     *
     * @return array{sql: string}
     */
    public function __debugInfo(): array
    {
        return ['sql' => $this->sql()];
    }

    /**
     * Returns the string representation of this query.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->sql();
    }
}

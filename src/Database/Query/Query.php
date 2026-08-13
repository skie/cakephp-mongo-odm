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
use Throwable;

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
     * Connection role (read/write) this query runs under.
     *
     * Defaults to write, mirroring cake60 `Query::$connectionRole`.
     *
     * @var string
     */
    protected string $connectionRole = Connection::ROLE_WRITE;

    /**
     * Returns the connection role this query runs under.
     *
     * @return string
     */
    public function getConnectionRole(): string
    {
        return $this->connectionRole;
    }

    /**
     * Sets the connection role this query runs under.
     *
     * @param string $role `Connection::ROLE_READ` or `Connection::ROLE_WRITE`.
     * @return $this
     */
    public function setConnectionRole(string $role): static
    {
        assert($role === Connection::ROLE_READ || $role === Connection::ROLE_WRITE);
        $this->connectionRole = $role;

        return $this;
    }

    /**
     * Routes this query through the read driver (replica-set secondary).
     *
     * @return $this
     */
    public function useReadRole(): static
    {
        return $this->setConnectionRole(Connection::ROLE_READ);
    }

    /**
     * Routes this query through the write driver (primary, the default).
     *
     * @return $this
     */
    public function useWriteRole(): static
    {
        return $this->setConnectionRole(Connection::ROLE_WRITE);
    }

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
        $this->applySchemaTypes();
        $this->dirty();

        return $this;
    }

    /**
     * Loads the collection's schema type map into the compiler.
     *
     * When the connection exposes schema metadata for the target collection,
     * field types (e.g. `objectid` for foreign keys) are registered on the
     * compiler so condition values are cast before compilation. Missing or
     * incomplete schema metadata is tolerated — the query still runs, just
     * without type-based casting.
     *
     * @return void
     */
    protected function applySchemaTypes(): void
    {
        if ($this->collection === '' || !$this->connection instanceof Connection) {
            return;
        }

        try {
            $schema = $this->connection->getSchemaCollection()->describe($this->collection);
            $typeMap = $schema->typeMap();
            $this->builder->setTypeMap($typeMap);
            $this->getTypeMap()->addDefaults($typeMap);
        } catch (Throwable) {
            // Schema metadata is best-effort; ignore and run untyped.
        }
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
     * Sets the field resolver applied to query field names.
     *
     * Delegates to the compiler, which forwards it to the condition expression
     * builder. Used by the ODM layer to strip repository aliases from Mongo
     * field names at compilation time.
     *
     * @param \Closure|null $resolver Callable receiving a field name and returning the Mongo field.
     * @return $this
     */
    public function setFieldResolver(?Closure $resolver): static
    {
        $this->builder->setFieldResolver($resolver);

        return $this;
    }

    /**
     * Adds an ad-hoc `$lookup` join against another collection.
     *
     * SQL-style facade over Mongo `$lookup`. The builder receives a select
     * query for the target collection and configures it fluently; the
     * conditions are compiled into a `$lookup` pipeline. Field-to-field
     * comparisons use `equalFields()` (left = source field, right = target
     * field); value conditions use the ordinary `where()` / expression API.
     *
     * ```php
     * $connection->selectQuery()
     *     ->from('authors')
     *     ->join('author_audits', function (SelectQuery $q) {
     *         $q->where(fn($exp) => $exp
     *             ->equalFields('authors.user_id', 'author_audits.foreign_key')
     *             ->eq('author_audits.model', 'Author'));
     *     });
     * ```
     *
     * A field is treated as a **source** field when it is prefixed with
     * anything other than the target alias/collection; it becomes a `let`
     * variable referenced as `$$field` inside the pipeline. A field prefixed
     * with the target alias (or unqualified) is a target field and stays a
     * bare `$field`.
     *
     * @param array<string, string>|string $from The target collection, or `[alias => collection]`.
     * @param callable $builder Callable receiving a target query to configure.
     * @param array{inner?: bool, asArray?: bool} $options Join options.
     * @return $this
     */
    public function join(string|array $from, callable $builder, array $options = []): static
    {
        return $this->buildJoin($from, $builder, $options, left: false);
    }

    /**
     * Adds a LEFT-style `$lookup` join (keeps source rows with no match).
     *
     * @param array<string, string>|string $from The target collection, or `[alias => collection]`.
     * @param callable $builder Callable receiving a target query to configure.
     * @param array{inner?: bool, asArray?: bool} $options Join options.
     * @return $this
     */
    public function leftJoin(string|array $from, callable $builder, array $options = []): static
    {
        return $this->buildJoin($from, $builder, $options, left: true);
    }

    /**
     * Shared `$lookup` join construction.
     *
     * `join()` is INNER (drops source rows with no match); `leftJoin()` is
     * LEFT (keeps them, `$unwind preserveNullAndEmptyArrays`). `asArray`
     * keeps the nested array without `$unwind`.
     *
     * @param array<string, string>|string $from The target collection, or `[alias => collection]`.
     * @param callable $builder Callable receiving a target query to configure.
     * @param array{inner?: bool, asArray?: bool} $options Join options.
     * @param bool $left Whether this is a LEFT join.
     * @return $this
     */
    protected function buildJoin(string|array $from, callable $builder, array $options, bool $left): static
    {
        $join = $this->normalizeJoin($from);

        $connection = $this->getConnection();
        if (!$connection instanceof Connection) {
            throw new CakeException('Join requires a connection.');
        }

        $targetQuery = $connection->selectQuery()->from($join['collection']);
        $result = $builder($targetQuery);
        $targetQuery = $result instanceof SelectQuery ? $result : $targetQuery;
        $compiled = $targetQuery->compile();
        $filter = $compiled['filter'] ?? [];

        [$let, $match] = $this->buildJoinLookup($filter, $join);

        $lookup = ['from' => $join['collection'], 'as' => $join['as']];
        if ($let !== []) {
            $lookup['let'] = $let;
        }

        if ($match !== []) {
            $lookup['pipeline'] = [['$match' => $match]];
        }

        $this->builder->pipeline([['$lookup' => $lookup]]);

        // `join` is INNER (drop unmatched), `leftJoin` is LEFT (keep them).
        $inner = !$left;
        if ($inner) {
            $this->builder->pipeline([['$match' => [$join['as'] => ['$ne' => []]]]]);
        }

        if (empty($options['asArray'])) {
            $this->builder->pipeline([
                ['$unwind' => ['path' => '$' . $join['as'], 'preserveNullAndEmptyArrays' => $left]],
            ]);
        }

        return $this;
    }

    /**
     * Normalizes the join target argument.
     *
     * @param array<string, string>|string $from The target collection or `[alias => collection]`.
     * @return array{alias: string, as: string, collection: string}
     */
    protected function normalizeJoin(string|array $from): array
    {
        if (is_string($from)) {
            return ['alias' => $from, 'as' => $from, 'collection' => $from];
        }

        $alias = (string)key($from);
        $collection = (string)current($from);

        return ['alias' => $alias, 'as' => $alias, 'collection' => $collection];
    }

    /**
     * Builds `let` variables and the rewritten `$match` from a join filter.
     *
     * @param array<string, mixed> $filter The compiled target filter.
     * @param array{alias: string, as: string, collection: string} $join The normalized join.
     * @return array{0: array<string, string>, 1: array<string, mixed>}
     */
    protected function buildJoinLookup(array $filter, array $join): array
    {
        $targetAlias = $join['alias'];
        $let = [];
        $match = $this->rewriteJoinFilter($filter, $targetAlias, $let);

        return [$let, $match];
    }

    /**
     * Recursively rewrites a join filter, extracting source refs into `let`.
     *
     * @param mixed $value The filter node.
     * @param string $targetAlias The target collection alias.
     * @param array<string, string> $let Accumulated let variables.
     * @return mixed
     */
    protected function rewriteJoinFilter(mixed $value, string $targetAlias, array &$let): mixed
    {
        if (is_array($value)) {
            $rewritten = [];
            foreach ($value as $k => $v) {
                if ($k === '$expr') {
                    $rewritten[$k] = $this->rewriteExpr($v, $targetAlias, $let);
                } elseif (is_string($k) && str_starts_with($k, $targetAlias . '.')) {
                    $rewritten[substr($k, strlen($targetAlias) + 1)] = $this->rewriteJoinFilter($v, $targetAlias, $let);
                } elseif (is_string($k) && str_contains($k, '.')) {
                    $bare = substr($k, strpos($k, '.') + 1);
                    $letName = $this->letVariableName($bare);
                    $let[$letName] = '$' . $k;
                    $rewritten[$bare] = $this->rewriteJoinFilter($v, $targetAlias, $let);
                } else {
                    $rewritten[$k] = $this->rewriteJoinFilter($v, $targetAlias, $let);
                }
            }

            return $rewritten;
        }

        return $value;
    }

    /**
     * Rewrites `$expr` operands, extracting source field paths into `let`.
     *
     * @param mixed $expr The `$expr` value.
     * @param string $targetAlias The target collection alias.
     * @param array<string, string> $let Accumulated let variables.
     * @return mixed
     */
    protected function rewriteExpr(mixed $expr, string $targetAlias, array &$let): mixed
    {
        if (is_array($expr)) {
            $rewritten = [];
            foreach ($expr as $k => $v) {
                if (is_string($v) && str_starts_with($v, '$' . $targetAlias . '.')) {
                    $rewritten[$k] = '$' . substr($v, strlen('$' . $targetAlias . '.'));
                } elseif (is_string($v) && str_starts_with($v, '$') && str_contains($v, '.')) {
                    $field = substr($v, 1);
                    $bare = substr($field, strpos($field, '.') + 1);
                    $letName = $this->letVariableName($bare);
                    $let[$letName] = '$' . $bare;
                    $rewritten[$k] = '$$' . $letName;
                } else {
                    $rewritten[$k] = $this->rewriteExpr($v, $targetAlias, $let);
                }
            }

            return $rewritten;
        }

        return $expr;
    }

    /**
     * Returns a Mongo-safe `let` variable name for a source field.
     *
     * Mongo `let` variables may not start with `_` (e.g. `_id`), so such
     * fields get a `source_` prefix (`_id` → `source_id`).
     *
     * @param string $field The source field name.
     * @return string The let variable name.
     */
    protected function letVariableName(string $field): string
    {
        return str_starts_with($field, '_') ? 'source_' . $field : $field;
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
     * @param \Cake\Database\ExpressionInterface|\Closure|array<int|string, mixed>|string|null $conditions The conditions.
     * @param array<int|string, string>                                                        $types      Field => type map used to cast values.
     * @param bool                                                                             $overwrite  Whether to overwrite existing conditions.
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
     * @param array<int, string>|string $fields A single field or list of fields.
     * @return $this
     */
    public function whereNull(array|string $fields): static
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
     * @param array<int, string>|string $fields A single field or list of fields.
     * @return $this
     */
    public function whereNotNull(array|string $fields): static
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
        if ($field instanceof Closure) {
            $field = $field($this->expr(), $this);
        }

        return $this->orderBy([(string)$field => 'ASC'], $overwrite);
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
        if ($field instanceof Closure) {
            $field = $field($this->expr(), $this);
        }

        return $this->orderBy([(string)$field => 'DESC'], $overwrite);
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

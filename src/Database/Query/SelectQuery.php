<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Query;

use Cake\Database\ExpressionInterface;
use Cake\Datasource\ResultSetInterface;
use Closure;
use Crustum\Mongo\Database\Aggregation\AggregationBuilder;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Database\Driver\MongoDriver;
use Crustum\Mongo\Database\Expression\QueryExpression;
use Crustum\Mongo\Database\ResultSet;
use Crustum\Mongo\Database\Type\TypeFactory;
use Crustum\Mongo\Database\TypeMap;
use InvalidArgumentException;
use IteratorAggregate;
use Throwable;
use Traversable;

/**
 * Select query for MongoDB find() and aggregation pipeline operations.
 *
 * @implements \IteratorAggregate<int, array<string, mixed>>
 * @see cake50/src/Database/Query/SelectQuery.php
 */
class SelectQuery extends Query implements IteratorAggregate
{
    use AggregationQueryTrait;

    /**
     * A list of callbacks to be called to alter each row from the result set
     * upon retrieval.
     *
     * @var list<\Closure>
     */
    protected array $resultDecorators = [];

    /**
     * The type map for fields in the select clause.
     *
     * @var \Crustum\Mongo\Database\TypeMap|null
     */
    protected ?TypeMap $selectTypeMap = null;

    /**
     * Whether rows are cast through the select type map on fetch.
     *
     * @var bool
     */
    protected bool $typeCastEnabled = true;

    /**
     * Sets the filter conditions.
     *
     * A `Closure` receives `(QueryExpression $exp, SelectQuery $query)` and must
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

        return $this;
    }

    /**
     * Adds conditions with an `$and` operator.
     *
     * A `Closure` receives `(QueryExpression $exp, SelectQuery $query)` and must
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

        return $this;
    }

    /**
     * Sets the field projection.
     *
     * A `Closure` receives the query and must return the fields to project.
     *
     * @param \Cake\Database\ExpressionInterface|\Closure|array<int|string, mixed>|string|float|int $fields Fields to include/exclude.
     * @param bool                                                                             $overwrite Whether to overwrite the existing projection.
     * @return $this
     */
    public function select(
        ExpressionInterface|Closure|array|string|float|int $fields = [],
        bool $overwrite = false,
    ): static {
        if ($fields instanceof Closure) {
            $fields = $fields($this);
        }

        if (is_float($fields) || is_int($fields)) {
            $fields = (string)$fields;
        }

        $this->builder->select($fields, $overwrite);

        return $this;
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

        return $this;
    }

    /**
     * Adds fields to group by, compiling to a `$group` stage.
     *
     * A single field compiles to `_id => '$field'`; multiple fields compile to an
     * `_id` document `{field: '$field', ...}`.
     *
     * @param \Cake\Database\ExpressionInterface|array<string>|string $fields Fields to group by.
     * @param bool $overwrite Whether to overwrite the existing group fields.
     * @return $this
     */
    public function groupBy(ExpressionInterface|array|string $fields, bool $overwrite = false): static
    {
        $this->builder->groupBy($fields, $overwrite);

        return $this;
    }

    /**
     * Adds post-`$group` filter conditions, compiling to a `$match` stage placed
     * after the `$group` stage.
     *
     * A `Closure` receives `(QueryExpression $exp, SelectQuery $query)` and must
     * return the conditions to merge.
     *
     * @param \Cake\Database\ExpressionInterface|\Closure|array<string, mixed>|string|null $conditions The conditions.
     * @param array<int|string, string>                                                    $types      Field => type map used to cast values.
     * @param bool                                                                         $overwrite  Whether to overwrite existing conditions.
     * @return $this
     */
    public function having(
        ExpressionInterface|Closure|array|string|null $conditions = [],
        array $types = [],
        bool $overwrite = false,
    ): static {
        if ($conditions instanceof Closure) {
            $exp = new QueryExpression();
            $conditions = $conditions($exp, $this) ?? $exp;
        }

        $types += $this->getDefaultTypes();
        $this->builder->having($conditions, $types, $overwrite);

        return $this;
    }

    /**
     * Adds post-`$group` filter conditions with an `$and` operator.
     *
     * @param \Cake\Database\ExpressionInterface|\Closure|array<string, mixed>|string $conditions The conditions to add.
     * @param array<int|string, string>                                                $types      Field => type map used to cast values.
     * @return $this
     */
    public function andHaving(ExpressionInterface|Closure|array|string $conditions, array $types = []): static
    {
        if ($conditions instanceof Closure) {
            $exp = new QueryExpression();
            $conditions = $conditions($exp, $this) ?? $exp;
        }

        $types += $this->getDefaultTypes();
        $this->builder->andHaving($conditions, $types);

        return $this;
    }

    /**
     * Adds a `$setWindowFields` stage.
     *
     * Accepts a `WindowInterface` object or a raw `$setWindowFields` body array
     * (`partitionBy` / `sortBy` / `output`).
     *
     * @param \Crustum\Mongo\Database\Query\WindowInterface|array<string, mixed> $windows The window specification.
     * @return $this
     */
    public function window(WindowInterface|array $windows): static
    {
        $window = $windows instanceof WindowInterface ? $windows->getWindow() : $windows;

        return $this->pipeline([['$setWindowFields' => $window]]);
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

        return $this;
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
     * Adds aggregation pipeline stage(s).
     *
     * A `Closure` receives an `AggregationBuilder` and builds stages in place;
     * the compiled stages are appended on return.
     *
     * @param \Closure|array<int, array<string, mixed>> $stages Pipeline stages or a builder closure.
     * @return $this
     */
    public function pipeline(array|Closure $stages): static
    {
        if ($stages instanceof Closure) {
            $builder = new AggregationBuilder();
            $stages($builder);
            $stages = $builder->getPipeline();
        }

        $this->builder->pipeline($stages);

        return $this;
    }

    /**
     * Sets additional MongoDB options.
     *
     * @param array<string, mixed> $options Options to set.
     * @return $this
     */
    public function options(array $options): static
    {
        $this->builder->options($options);

        return $this;
    }

    /**
     * Appends fields to the projection without overwriting the existing list.
     *
     * @param \Cake\Database\ExpressionInterface|\Closure|array<int|string, mixed>|string|float|int ...$fields Fields to add.
     * @return $this
     */
    public function selectAlso(ExpressionInterface|Closure|array|string|float|int ...$fields): static
    {
        foreach ($fields as $field) {
            $this->select($field);
        }

        return $this;
    }

    /**
     * Selects all fields for the given collection except the excluded ones.
     *
     * When the collection exposes a schema the excluded fields are removed from
     * the field list; otherwise an exclusion projection is built instead.
     *
     * @param string $collection The collection name.
     * @param array<string> $excludedFields The un-aliased field names not to select.
     * @param bool $overwrite Whether to overwrite the existing projection.
     * @return $this
     */
    public function selectAllExcept(string $collection, array $excludedFields, bool $overwrite = false): static
    {
        $fields = $this->collectionFields($collection);
        if ($fields === []) {
            return $this->select(array_fill_keys($excludedFields, 0), $overwrite);
        }

        return $this->select(array_values(array_diff($fields, $excludedFields)), $overwrite);
    }

    /**
     * Enables result casting.
     *
     * @return $this
     */
    public function enableResultsCasting(): static
    {
        $this->typeCastEnabled = true;

        return $this;
    }

    /**
     * Disables result casting.
     *
     * @return $this
     */
    public function disableResultsCasting(): static
    {
        $this->typeCastEnabled = false;

        return $this;
    }

    /**
     * Returns whether result casting is enabled.
     *
     * @return bool
     */
    public function isResultsCastingEnabled(): bool
    {
        return $this->typeCastEnabled;
    }

    /**
     * Registers a callback to be executed for each fetched result row.
     *
     * @param \Closure|null $callback The callback to invoke with each row.
     * @param bool $overwrite Whether to replace all existing decorators.
     * @return $this
     */
    public function decorateResults(?Closure $callback, bool $overwrite = false): static
    {
        if ($overwrite) {
            $this->resultDecorators = [];
        }

        if ($callback instanceof Closure) {
            $this->resultDecorators[] = $callback;
        }

        return $this;
    }

    /**
     * Returns the registered result decorators.
     *
     * @return list<\Closure>
     */
    public function getResultDecorators(): array
    {
        return $this->resultDecorators;
    }

    /**
     * Sets the type map for fields in the select clause.
     *
     * @param \Crustum\Mongo\Database\TypeMap|array<int|string, string> $typeMap Creates a TypeMap if array, otherwise sets the given TypeMap.
     * @return $this
     */
    public function setSelectTypeMap(TypeMap|array $typeMap): static
    {
        $this->selectTypeMap = is_array($typeMap) ? new TypeMap($typeMap) : $typeMap;

        return $this;
    }

    /**
     * Gets the type map for fields in the select clause.
     *
     * @return \Crustum\Mongo\Database\TypeMap
     */
    public function getSelectTypeMap(): TypeMap
    {
        return $this->selectTypeMap ??= new TypeMap();
    }

    /**
     * Returns all documents as a result set.
     *
     * Rows are cast through the select type map (when enabled) and passed through
     * the registered result decorators in order.
     *
     * @return \Cake\Datasource\ResultSetInterface<array-key, mixed>
     */
    public function all(): ResultSetInterface
    {
        $result = $this->execute();
        if ($result instanceof ResultSetInterface) {
            return $result;
        }

        $rows = $result instanceof Traversable ? $result : (array)$result;

        return new ResultSet($this->decorateRows($rows));
    }

    /**
     * Returns the first document or `null`.
     *
     * @return array<string, mixed>|null
     */
    public function first(): ?array
    {
        $documents = $this->all()->first();

        return is_array($documents) ? $documents : null;
    }

    /**
     * Returns the number of matching documents.
     *
     * @return int
     */
    public function count(): int
    {
        $connection = $this->getConnection();
        if (!$connection instanceof Connection) {
            return 0;
        }

        return $connection->getCollection($this->collection)->countDocuments($this->builder->getFilter());
    }

    /**
     * Returns the distinct values for a field.
     *
     * @param string $field The field name.
     * @return list<mixed>
     */
    public function distinct(string $field): array
    {
        $connection = $this->getConnection();
        if (!$connection instanceof Connection) {
            return [];
        }

        return array_values($connection->getCollection($this->collection)->distinct($field, $this->builder->getFilter()));
    }

    /**
     * Returns an iterator over the executed results.
     *
     * @return \Traversable<int, array<string, mixed>>
     */
    public function getIterator(): Traversable
    {
        return $this->execute();
    }

    /**
     * Deep-clones the query so the clone owns an independent compiler and type map.
     */
    public function __clone()
    {
        parent::__clone();

        if ($this->selectTypeMap instanceof TypeMap) {
            $this->selectTypeMap = clone $this->selectTypeMap;
        }
    }

    /**
     * Returns an array describing the internal state of this query.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        $info = parent::__debugInfo();
        $info['decorators'] = count($this->resultDecorators);

        return $info;
    }

    /**
     * Applies result casting and decorators to each row.
     *
     * @param iterable<array<string, mixed>|mixed> $rows The raw result rows.
     * @return iterable<array<string, mixed>|mixed>
     */
    protected function decorateRows(iterable $rows): iterable
    {
        foreach ($rows as $row) {
            if (is_array($row)) {
                $row = $this->castRow($row);
                foreach ($this->resultDecorators as $decorator) {
                    $row = $decorator($row);
                }
            }

            yield $row;
        }
    }

    /**
     * Casts a row's fields through the select type map.
     *
     * @param array<string, mixed> $row The row to cast.
     * @return array<string, mixed>
     */
    protected function castRow(array $row): array
    {
        if (!$this->typeCastEnabled) {
            return $row;
        }

        $types = $this->selectTypeMap?->toArray() ?? [];
        if ($types === []) {
            return $row;
        }

        $driver = $this->connection instanceof Connection ? $this->connection->getDriver() : null;
        if (!$driver instanceof MongoDriver) {
            return $row;
        }

        foreach ($types as $field => $type) {
            if (array_key_exists($field, $row)) {
                $row[$field] = TypeFactory::build((string)$type)->toPHP($row[$field], $driver);
            }
        }

        return $row;
    }

    /**
     * Returns the schema columns for a collection, when available.
     *
     * @param string $collection The collection name.
     * @return list<string>
     */
    protected function collectionFields(string $collection): array
    {
        if (!$this->connection instanceof Connection) {
            return [];
        }

        try {
            $schema = $this->connection->getSchemaCollection()->describe($collection);
        } catch (Throwable) {
            return [];
        }

        return array_values($schema->columns());
    }
}

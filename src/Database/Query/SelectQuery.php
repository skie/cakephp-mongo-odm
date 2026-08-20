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
use IteratorAggregate;
use Override;
use Traversable;

/**
 * Select query for MongoDB find() and aggregation pipeline operations.
 *
 * @implements \IteratorAggregate<int, array<string, mixed>>
 * @inspired-by \Cake\Database\Query\SelectQuery
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
     * Cached decorated results, reused until the query is modified.
     *
     * @var iterable<array-key, mixed>|null
     */
    protected ?iterable $results = null;

    /**
     * Whether rows are cast through the select type map on fetch.
     *
     * @var bool
     */
    protected bool $typeCastEnabled = true;

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

        $this->builder->select($fields, $overwrite);
        $this->dirty();

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
        $this->dirty();

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
        $this->dirty();

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
        $this->dirty();

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
     * Adds aggregation pipeline stage(s).
     *
     * A `Closure` receives an `AggregationBuilder` and builds stages in place;
     * the compiled stages are appended on return.
     *
     * @param \Closure|array<int, array<int|string, mixed>>|array<int|string, mixed> $stages Pipeline stages or a builder closure.
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
        $this->dirty();

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
        $this->dirty();

        return $this;
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
     * The executed rows are decorated once and cached until the query is
     * modified (marked dirty), so repeated `all()` / `first()` / `toArray()`
     * calls reuse the same result instead of re-hitting Mongo.
     *
     * @return \Cake\Datasource\ResultSetInterface<array-key, mixed>
     */
    public function all(): ResultSetInterface
    {
        if ($this->results === null || $this->dirty) {
            $result = $this->execute();
            if ($result instanceof ResultSetInterface) {
                $this->results = $result;
            } else {
                $rows = $result instanceof Traversable ? $result : (array)$result;
                $this->results = new ResultSet($this->decorateRows($rows));
            }
        }

        assert($this->results instanceof ResultSetInterface);

        return $this->results;
    }

    /**
     * Deduplicates result documents by the given fields.
     *
     * Mirrors SQL `DISTINCT`: results are grouped by the given fields and the
     * first document of each group is emitted. Pass an empty array to disable.
     *
     * @param \Cake\Database\ExpressionInterface|\Closure|array<int|string, mixed>|string|float|int $fields Fields to deduplicate by.
     * @param bool                                                                             $overwrite Whether to replace previously configured fields.
     * @return $this
     */
    public function distinct(
        ExpressionInterface|Closure|array|string|float|int $fields = [],
        bool $overwrite = false,
    ): static {
        if ($fields instanceof Closure) {
            $fields = $fields($this);
        }

        if (is_float($fields) || is_int($fields)) {
            $fields = (string)$fields;
        }

        $this->builder->distinct($fields === '' || $fields === [] ? [] : (array)$fields, $overwrite);
        $this->dirty();

        return $this;
    }

    /**
     * Returns the distinct values for a field.
     *
     * @param string $field The field name.
     * @return list<mixed>
     */
    public function distinctValues(string $field): array
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
    #[Override]
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
    #[Override]
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
}

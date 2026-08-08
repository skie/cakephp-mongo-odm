<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Query;

use Cake\Database\ExpressionInterface;
use Closure;
use Crustum\Mongo\Database\Driver\MongoDriver;
use Crustum\Mongo\Database\Expression\MongoExpressionInterface;
use Crustum\Mongo\Database\QueryBuilder as ExpressionBuilder;
use Crustum\Mongo\Database\Type\TypeFactory;
use InvalidArgumentException;

/**
 * Query builder for MongoDB queries and aggregation pipelines.
 *
 * This class is responsible for building MongoDB query DSL (filter, projection, sort, etc.)
 * and compiling them into executable MongoDB query format.
 *
 * This is the datasource layer - it has no ORM concerns (no associations, hydration, etc.)
 */
class QueryCompiler
{
    /**
     * MongoDB filter conditions
     *
     * @var array<string, mixed>
     */
    protected array $filter = [];

    /**
     * Field projection (which fields to return)
     *
     * @var array<string, mixed>
     */
    protected array $projection = [];

    /**
     * Sort order
     *
     * @var array<string, int>
     */
    protected array $sort = [];

    /**
     * Limit number of results
     *
     * @var int|null
     */
    protected ?int $limit = null;

    /**
     * Skip number of results
     *
     * @var int|null
     */
    protected ?int $skip = null;

    /**
     * Aggregation pipeline stages
     *
     * @var array<int, array<int|string, mixed>>
     */
    protected array $pipeline = [];

    /**
     * Additional MongoDB options
     *
     * @var array<string, mixed>
     */
    protected array $options = [];

    /**
     * Fields to group by (compiled to a `$group` stage).
     *
     * @var list<string>
     */
    protected array $group = [];

    /**
     * Post-`$group` filter conditions (compiled to a `$match` stage).
     *
     * @var array<string, mixed>
     */
    protected array $having = [];

    /**
     * The driver used for type casting, when available.
     *
     * @var \Crustum\Mongo\Database\Driver\MongoDriver|null
     */
    protected ?MongoDriver $driver = null;

    /**
     * Expression builder for complex conditions
     *
     * @var \Crustum\Mongo\Database\QueryBuilder
     */
    protected ExpressionBuilder $expressionBuilder;

    /**
     * Constructor
     *
     * @param \Crustum\Mongo\Database\Driver\MongoDriver|null $driver The driver used to cast typed values.
     */
    public function __construct(?MongoDriver $driver = null)
    {
        $this->driver = $driver;
        $this->expressionBuilder = new ExpressionBuilder();
    }

    /**
     * Sets the field resolver applied to query field names.
     *
     * The resolver is forwarded to the condition expression builder so filter
     * fields resolve the same way regardless of how conditions were provided
     * (arrays or closures). When unset, fields pass through unchanged.
     *
     * @param \Closure|null $resolver Callable receiving a field name and returning the Mongo field.
     * @return $this
     */
    public function setFieldResolver(?\Closure $resolver): static
    {
        $this->expressionBuilder->setFieldResolver($resolver);

        return $this;
    }

    /**
     * Resolves a field name through the configured resolver.
     *
     * @param string $field The raw field name.
     * @return string The resolved Mongo field name.
     */
    protected function resolveField(string $field): string
    {
        return $this->expressionBuilder->resolveField($field);
    }

    /**
     * Add filter conditions to the query.
     *
     * A `Closure` receives the expression builder and must return the conditions.
     * Non-Mongo `ExpressionInterface` instances are rejected with a clear exception.
     *
     * @param \Cake\Database\ExpressionInterface|\Closure|array<string, mixed>|string|null $conditions The conditions to add
     * @param array<int|string, string>|bool                                                $types      Field => type map used to cast values; a legacy `bool` is treated as `$overwrite`
     * @param bool                                                                          $overwrite  Whether to overwrite existing conditions
     * @return $this
     * @throws \InvalidArgumentException When a non-Mongo expression is passed.
     */
    public function where(
        ExpressionInterface|Closure|array|string|null $conditions = [],
        array|bool $types = [],
        bool $overwrite = false,
    ) {
        if (is_bool($types)) {
            $overwrite = $types;
            $types = [];
        }

        if ($conditions === null || $conditions === '') {
            return $this;
        }

        if ($conditions instanceof Closure) {
            $conditions = $conditions($this->expressionBuilder);
        }

        if ($conditions instanceof MongoExpressionInterface) {
            $conditions = $conditions->getConditions();
        } elseif ($conditions instanceof ExpressionInterface) {
            $this->assertMongoExpression($conditions);
        }

        if (is_string($conditions)) {
            $conditions = [$conditions];
        }

        if (is_array($conditions)) {
            $conditions = $this->castConditions($conditions, $types);
            $parsed = $this->expressionBuilder->parse($conditions);
            $this->filter = $overwrite ? $parsed : array_merge_recursive($this->filter, $parsed);
        }

        return $this;
    }

    /**
     * Add additional conditions using $and operator.
     *
     * @param \Cake\Database\ExpressionInterface|\Closure|array<string, mixed>|string $conditions The conditions to add
     * @param array<int|string, string>                                               $types      Field => type map used to cast values
     * @return $this
     * @throws \InvalidArgumentException When a non-Mongo expression is passed.
     */
    public function andWhere(ExpressionInterface|Closure|array|string $conditions, array $types = [])
    {
        if ($conditions instanceof Closure) {
            $conditions = $conditions($this->expressionBuilder);
        }

        if ($conditions instanceof MongoExpressionInterface) {
            $conditions = $conditions->getConditions();
        } elseif ($conditions instanceof ExpressionInterface) {
            $this->assertMongoExpression($conditions);
        }

        if (is_array($conditions)) {
            $conditions = $this->castConditions($conditions, $types);
        }

        if ($this->filter === []) {
            return $this->where($conditions);
        }

        $existing = $this->filter;
        $this->filter = [
            '$and' => [
                $existing,
                $this->expressionBuilder->parse(is_array($conditions) ? $conditions : [$conditions]),
            ],
        ];

        return $this;
    }

    /**
     * Set field projection (which fields to return).
     *
     * A `Closure` receives the compiler instance and must return the fields
     * (array or string) to project.
     *
     * @param \Cake\Database\ExpressionInterface|\Closure|array<int|string, mixed>|string|float|int $fields    Fields to include/exclude
     * @param bool                                                                            $overwrite Whether to overwrite existing projection
     * @return $this
     * @throws \InvalidArgumentException When a non-Mongo expression is passed.
     */
    public function select(ExpressionInterface|Closure|array|string|float|int $fields = [], bool $overwrite = false)
    {
        if ($fields instanceof Closure) {
            $fields = $fields($this);
        }

        if ($fields instanceof ExpressionInterface) {
            $this->assertMongoExpression($fields);
            $fields = [$fields];
        }

        if (!is_array($fields)) {
            $fields = [$fields];
        }

        $projection = [];
        foreach ($fields as $key => $value) {
            if ($value instanceof MongoExpressionInterface) {
                $value = $value->getConditions();
            }

            if (is_numeric($key)) {
                $projection[$this->resolveField((string)$value)] = 1;
            } else {
                $resolvedValue = is_string($value) && !str_starts_with($value, '$')
                    ? $this->resolveField($value)
                    : $value;
                $projection[$this->resolveField((string)$key)] = $resolvedValue;
            }
        }

        $this->projection = $overwrite ? $projection : array_replace($this->projection, $projection);

        return $this;
    }

    /**
     * Set sort order.
     *
     * A `Closure` receives the compiler instance and must return the sort fields
     * (array or string) to order by.
     *
     * @param \Cake\Database\ExpressionInterface|\Closure|array<string, mixed>|string $fields    Fields to sort by
     * @param bool                                                                    $overwrite Whether to overwrite existing sort
     * @return $this
     * @throws \InvalidArgumentException When a non-Mongo expression is passed.
     */
    public function orderBy(ExpressionInterface|Closure|array|string $fields, bool $overwrite = false)
    {
        if ($fields instanceof Closure) {
            $fields = $fields($this);
        }

        if ($fields instanceof MongoExpressionInterface) {
            $fields = array_fill_keys(array_keys($fields->getConditions()), 1);
        } elseif ($fields instanceof ExpressionInterface) {
            $this->assertMongoExpression($fields);
        }

        if (is_string($fields)) {
            $parts = explode(' ', trim($fields), 2);
            if (count($parts) === 2) {
                $fields = [$parts[0] => strtolower($parts[1]) === 'desc' ? -1 : 1];
            } else {
                $fields = [$fields => 1];
            }
        }

        $normalized = [];
        foreach ($fields as $field => $direction) {
            if (is_string($direction)) {
                $direction = strtolower($direction) === 'desc' ? -1 : 1;
            }

            $normalized[$this->resolveField((string)$field)] = $direction;
        }

        $this->sort = $overwrite ? $normalized : array_merge($this->sort, $normalized);

        return $this;
    }

    /**
     * Add fields to group by (compiled to a `$group` stage).
     *
     * A single field becomes `_id => '$field'`; multiple fields become an
     * `_id` document `{field: '$field', ...}`.
     *
     * @param \Cake\Database\ExpressionInterface|array<string>|string $fields    Fields to group by
     * @param bool                                                    $overwrite Whether to overwrite existing group fields
     * @return $this
     * @throws \InvalidArgumentException When a non-Mongo expression is passed.
     */
    public function groupBy(ExpressionInterface|array|string $fields, bool $overwrite = false)
    {
        if ($overwrite) {
            $this->group = [];
        }

        if ($fields instanceof ExpressionInterface) {
            $fields = array_keys($this->assertMongoExpression($fields)->getConditions());
        }

        if (!is_array($fields)) {
            $fields = [$fields];
        }

        $this->group = array_merge($this->group, array_values(array_map(
            fn(string $field): string => $this->resolveField($field),
            array_map(strval(...), $fields),
        )));

        return $this;
    }

    /**
     * Add post-`$group` filter conditions (compiled to a `$match` stage).
     *
     * Behaves like `where()`, but targets the having clause.
     *
     * @param \Cake\Database\ExpressionInterface|\Closure|array<string, mixed>|string|null $conditions The conditions to add
     * @param array<int|string, string>|bool                                                $types      Field => type map used to cast values; a legacy `bool` is treated as `$overwrite`
     * @param bool                                                                          $overwrite  Whether to overwrite existing conditions
     * @return $this
     * @throws \InvalidArgumentException When a non-Mongo expression is passed.
     */
    public function having(
        ExpressionInterface|Closure|array|string|null $conditions = [],
        array|bool $types = [],
        bool $overwrite = false,
    ) {
        if (is_bool($types)) {
            $overwrite = $types;
            $types = [];
        }

        if ($conditions === null || $conditions === '') {
            return $this;
        }

        if ($conditions instanceof Closure) {
            $conditions = $conditions($this->expressionBuilder);
        }

        if ($conditions instanceof MongoExpressionInterface) {
            $conditions = $conditions->getConditions();
        } elseif ($conditions instanceof ExpressionInterface) {
            $this->assertMongoExpression($conditions);
        }

        if (is_string($conditions)) {
            $conditions = [$conditions];
        }

        if (is_array($conditions)) {
            $conditions = $this->castConditions($conditions, $types);
            $parsed = $this->expressionBuilder->parse($conditions);
            $this->having = $overwrite ? $parsed : array_merge_recursive($this->having, $parsed);
        }

        return $this;
    }

    /**
     * Add additional post-`$group` filter conditions using `$and`.
     *
     * @param \Cake\Database\ExpressionInterface|\Closure|array<string, mixed>|string $conditions The conditions to add
     * @param array<int|string, string>                                               $types      Field => type map used to cast values
     * @return $this
     * @throws \InvalidArgumentException When a non-Mongo expression is passed.
     */
    public function andHaving(ExpressionInterface|Closure|array|string $conditions, array $types = [])
    {
        if ($conditions instanceof Closure) {
            $conditions = $conditions($this->expressionBuilder);
        }

        if ($conditions instanceof MongoExpressionInterface) {
            $conditions = $conditions->getConditions();
        } elseif ($conditions instanceof ExpressionInterface) {
            $this->assertMongoExpression($conditions);
        }

        if (is_array($conditions)) {
            $conditions = $this->castConditions($conditions, $types);
        }

        if ($this->having === []) {
            return $this->having($conditions);
        }

        $existing = $this->having;
        $this->having = [
            '$and' => [
                $existing,
                $this->expressionBuilder->parse(is_array($conditions) ? $conditions : [$conditions]),
            ],
        ];

        return $this;
    }

    /**
     * Set limit.
     *
     * @param int|null $limit Number of results to return
     * @return $this
     */
    public function limit(?int $limit)
    {
        $this->limit = $limit;

        return $this;
    }

    /**
     * Set skip (offset).
     *
     * @param int|null $skip Number of results to skip
     * @return $this
     */
    public function skip(?int $skip)
    {
        $this->skip = $skip;

        return $this;
    }

    /**
     * Add aggregation pipeline stage(s).
     *
     * @param array<int, array<int|string, mixed>> $stages Pipeline stages to add
     * @return $this
     */
    public function pipeline(array $stages)
    {
        if (isset($stages[0])) {
            $this->pipeline = array_merge($this->pipeline, $stages);
        } else {
            $this->pipeline[] = $stages;
        }

        return $this;
    }

    /**
     * Set MongoDB options.
     *
     * @param array<string, mixed> $options Options to set
     * @return $this
     */
    public function options(array $options)
    {
        $this->options = array_merge($this->options, $options);

        return $this;
    }

    /**
     * Compile the query to MongoDB executable format.
     *
     * Returns an array with either:
     * - 'type' => 'find' with 'filter' and 'options'
     * - 'type' => 'aggregate' with 'pipeline' and 'options'
     *
     * @return array<string, mixed> Compiled query
     */
    public function compile(): array
    {
        if ($this->pipeline !== [] || $this->group !== [] || $this->having !== []) {
            return $this->compileAggregate();
        }

        return $this->compileFind();
    }

    /**
     * Compile as find() query.
     *
     * @return array<string, mixed>
     */
    protected function compileFind(): array
    {
        $query = [
            'type' => 'find',
            'filter' => $this->filter,
            'options' => $this->options,
        ];

        if ($this->projection !== []) {
            $query['options']['projection'] = $this->projection;
        }

        if ($this->limit !== null) {
            $query['options']['limit'] = $this->limit;
        }

        if ($this->skip !== null) {
            $query['options']['skip'] = $this->skip;
        }

        if ($this->sort !== []) {
            $query['options']['sort'] = $this->sort;
        }

        $query['options']['typeMap'] = [
            'root' => 'array',
            'document' => 'array',
            'array' => 'array',
        ];

        return $query;
    }

    /**
     * Compile as aggregate() query.
     *
     * @return array<string, mixed>
     */
    protected function compileAggregate(): array
    {
        $pipeline = [];

        $headIsFixed = isset($this->pipeline[0]['$search'])
            || isset($this->pipeline[0]['$geoNear'])
            || isset($this->pipeline[0]['$indexStats']);

        if ($this->filter !== [] && !$headIsFixed) {
            $pipeline[] = ['$match' => $this->filter];
        }

        if ($this->group !== []) {
            $pipeline[] = ['$group' => $this->buildGroupStage()];
        }

        if ($this->having !== []) {
            $pipeline[] = ['$match' => $this->having];
        }

        foreach ($this->pipeline as $stage) {
            $pipeline[] = $stage;
        }

        if ($this->sort !== []) {
            $pipeline[] = ['$sort' => $this->sort];
        }

        if ($this->projection !== []) {
            $pipeline[] = ['$project' => $this->projection];
        }

        if ($this->skip !== null) {
            $pipeline[] = ['$skip' => $this->skip];
        }

        if ($this->limit !== null) {
            $pipeline[] = ['$limit' => $this->limit];
        }

        return [
            'type' => 'aggregate',
            'pipeline' => $pipeline,
            'options' => $this->options,
        ];
    }

    /**
     * Builds the `$group` stage body from the configured group fields.
     *
     * @return array<string, mixed>
     */
    protected function buildGroupStage(): array
    {
        if (count($this->group) === 1) {
            return ['_id' => '$' . ltrim($this->group[0], '$')];
        }

        $id = [];
        foreach ($this->group as $field) {
            $id[$field] = '$' . ltrim($field, '$');
        }

        return ['_id' => $id];
    }

    /**
     * Casts condition values per the given field => type map.
     *
     * Only fields with an entry in `$types` are cast; nested condition lists and
     * AND/OR/NOT groups are recursed into.
     *
     * @param array<int|string, mixed> $conditions The raw conditions.
     * @param array<int|string, string> $types     Field => type map used to cast values.
     * @return array<int|string, mixed>
     */
    protected function castConditions(array $conditions, array $types): array
    {
        if ($types === []) {
            return $conditions;
        }

        foreach ($conditions as $key => $value) {
            if (is_string($key) && (str_starts_with($key, '$') || in_array(strtoupper($key), ['AND', 'OR', 'NOT'], true))) {
                $conditions[$key] = is_array($value) ? $this->castConditions($value, $types) : $value;
                continue;
            }

            $field = is_string($key) && str_contains($key, ' ') ? explode(' ', $key)[0] : $key;
            if (is_string($field) && isset($types[$field])) {
                $conditions[$key] = $this->castValue($value, $types[$field]);
            } elseif (is_array($value)) {
                $conditions[$key] = $this->castConditions($value, $types);
            }
        }

        return $conditions;
    }

    /**
     * Casts a single value to its database representation.
     *
     * @param mixed $value The value to cast.
     * @param string $type The type name.
     * @return mixed The cast value.
     */
    protected function castValue(mixed $value, string $type): mixed
    {
        if (!$this->driver instanceof MongoDriver) {
            return $value;
        }

        if ($value === null) {
            return null;
        }

        if ($value instanceof MongoExpressionInterface) {
            return $value->getConditions();
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(fn(mixed $item): mixed => $this->castValue($item, $type), $value);
            }

            return $value;
        }

        return TypeFactory::build($type)->toDatabase($value, $this->driver);
    }

    /**
     * Rejects non-Mongo expressions with a clear exception.
     *
     * @param \Cake\Database\ExpressionInterface $expression The expression to check.
     * @return \Crustum\Mongo\Database\Expression\MongoExpressionInterface
     * @throws \InvalidArgumentException When the expression cannot be compiled to a MongoDB query.
     */
    protected function assertMongoExpression(ExpressionInterface $expression): MongoExpressionInterface
    {
        if (!$expression instanceof MongoExpressionInterface) {
            throw new InvalidArgumentException(sprintf(
                'Expression of type `%s` is not compilable to a MongoDB query. ' .
                'Use an expression implementing `%s` instead.',
                $expression::class,
                MongoExpressionInterface::class,
            ));
        }

        return $expression;
    }

    /**
     * Deep-clones the compiler so the clone owns an independent expression
     * builder and condition arrays.
     */
    public function __clone()
    {
        $this->expressionBuilder = clone $this->expressionBuilder;
    }

    /**
     * Get the expression builder instance.
     *
     * @return \Crustum\Mongo\Database\QueryBuilder
     */
    public function expr(): ExpressionBuilder
    {
        return $this->expressionBuilder;
    }

    /**
     * Get the filter conditions
     *
     * @return array<string, mixed>
     */
    public function getFilter(): array
    {
        return $this->filter;
    }

    /**
     * Get the projection fields
     *
     * @return array<string, mixed>
     */
    public function getProjection(): array
    {
        return $this->projection;
    }

    /**
     * Get the current limit, if any.
     *
     * @return int|null
     */
    public function getLimit(): ?int
    {
        return $this->limit;
    }

    /**
     * Get the current skip, if any.
     *
     * @return int|null
     */
    public function getSkip(): ?int
    {
        return $this->skip;
    }

    /**
     * Get the current sort order.
     *
     * @return array<string, int>
     */
    public function getSort(): array
    {
        return $this->sort;
    }

    /**
     * Get the aggregation pipeline stages.
     *
     * @return array<int, array<int|string, mixed>>
     */
    public function getPipeline(): array
    {
        return $this->pipeline;
    }

    /**
     * Get the additional MongoDB options.
     *
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Get the group fields.
     *
     * @return list<string>
     */
    public function getGroup(): array
    {
        return $this->group;
    }

    /**
     * Get the post-`$group` filter conditions.
     *
     * @return array<string, mixed>
     */
    public function getHaving(): array
    {
        return $this->having;
    }

    /**
     * Reset the builder to initial state.
     *
     * @return $this
     */
    public function reset()
    {
        $this->filter = [];
        $this->projection = [];
        $this->sort = [];
        $this->limit = null;
        $this->skip = null;
        $this->pipeline = [];
        $this->options = [];
        $this->group = [];
        $this->having = [];

        return $this;
    }
}

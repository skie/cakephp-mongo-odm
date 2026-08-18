<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Query;

use Cake\Database\ExpressionInterface;
use Closure;
use Crustum\Mongo\Database\Aggregation\AggregationBuilder;
use Crustum\Mongo\Database\Driver\MongoDriver;
use Crustum\Mongo\Database\Expression\MongoExpressionInterface;
use Crustum\Mongo\Database\Expression\OrderByExpression;
use Crustum\Mongo\Database\Expression\OrderClauseExpression;
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
     * Collection schema type map (field => type) used for value casting.
     *
     * @var array<string, string>
     */
    protected array $typeMap = [];

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
     * Distinct fields, deduplicating results (compiled to `$group` + `$replaceRoot`).
     *
     * @var list<string>
     */
    protected array $distinct = [];

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
    public function setFieldResolver(?Closure $resolver): static
    {
        $this->expressionBuilder->setFieldResolver($resolver);

        return $this;
    }

    /**
     * Sets the collection schema type map used to cast condition values.
     *
     * Fields with a `objectid` type have their 24-hex string values (scalar or
     * inside `IN` arrays) converted to `ObjectId` before compilation, matching
     * ObjectId foreign keys in the database.
     *
     * @param array<string, string> $typeMap Field => type map.
     * @return $this
     */
    public function setTypeMap(array $typeMap): static
    {
        $this->typeMap = $typeMap;

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
            if ($overwrite) {
                $this->filter = [];
            }

            foreach ($parsed as $field => $condition) {
                $this->filter[(string)$field] = $condition;
            }
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

        if (is_int($fields) || is_float($fields) || is_bool($fields)) {
            $fields = ['_c' => ['$literal' => $fields]];
        }

        if ($fields instanceof ExpressionInterface) {
            $this->assertMongoExpression($fields);
            $fields = ['_c' => $fields];
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
                if (is_array($value)) {
                    $projection['_c' . $key] = $this->resolveFieldRefs($value);
                } elseif (is_int($value) || is_float($value) || is_bool($value)) {
                    $projection['_c' . $key] = ['$literal' => $value];
                } else {
                    $projection[$this->resolveField((string)$value)] = 1;
                }
            } else {
                $resolvedValue = is_string($value) && !str_starts_with($value, '$')
                    ? $this->resolveField($value)
                    : $value;
                if (is_array($resolvedValue)) {
                    $resolvedValue = $this->resolveFieldRefs($resolvedValue);
                }

                $projection[$this->resolveField($key)] = is_string($resolvedValue) && preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $resolvedValue)
                    ? '$' . $resolvedValue
                    : $resolvedValue;
            }
        }

        $this->projection = $overwrite ? $projection : array_replace($this->projection, $projection);

        return $this;
    }

    /**
     * Resolves `$Alias.field` references inside function-expression args.
     *
     * Function operators (`$strLenCP`, `$concat`, ...) reference source fields
     * with a leading `$`; those paths still carry the repository alias. The
     * field resolver strips it so `$Authors.name` becomes `$name`.
     *
     * @param mixed $value The expression value.
     * @return mixed
     */
    protected function resolveFieldRefs(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = $this->resolveFieldRefs($v);
            }

            return $value;
        }

        if (is_string($value) && str_starts_with($value, '$') && !str_starts_with($value, '$$')) {
            return '$' . $this->resolveField(substr($value, 1));
        }

        return $value;
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

        if ($fields instanceof OrderByExpression || $fields instanceof OrderClauseExpression) {
            $fields = $fields->getConditions();
        } elseif ($fields instanceof MongoExpressionInterface) {
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
            if (is_int($field)) {
                $field = $direction;
                $direction = 1;
            } elseif (is_string($direction)) {
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
            $this->resolveField(...),
            array_map(strval(...), $fields),
        )));

        return $this;
    }

    /**
     * Set distinct fields, deduplicating result documents by them.
     *
     * When set, results are grouped by the given fields and the first document
     * of each group is emitted, mirroring SQL `DISTINCT`. An empty array
     * disables deduplication.
     *
     * @param array<string>|string $fields Fields to deduplicate by.
     * @param bool $overwrite Whether to replace previously configured fields.
     * @return $this
     */
    public function distinct(array|string $fields = [], bool $overwrite = false)
    {
        if ($overwrite) {
            $this->distinct = [];
        }

        if (is_string($fields)) {
            $fields = [$fields];
        }

        $this->distinct = array_merge($this->distinct, array_values(array_map(
            $this->resolveField(...),
            array_map(strval(...), $fields),
        )));

        return $this;
    }

    /**
     * Get the distinct fields.
     *
     * @return list<string>
     */
    public function getDistinct(): array
    {
        return $this->distinct;
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
            $conditions = $this->flattenExpressions($conditions);
            $conditions = $this->castConditions($conditions, $types);
            $parsed = $this->expressionBuilder->parse($conditions);
            if ($overwrite) {
                $this->having = [];
            }

            foreach ($parsed as $field => $condition) {
                $this->having[(string)$field] = $condition;
            }
        }

        return $this;
    }

    /**
     * Expands Mongo expression instances nested inside a condition array.
     *
     * cake accepts `having([$exp->gte('col', 1)])` — a list wrapping a single
     * expression — alongside the plain array form. Each nested expression is
     * replaced by its compiled condition array so downstream parsing sees only
     * plain Mongo conditions.
     *
     * @param array<int|string, mixed> $conditions The raw conditions.
     * @return array<int|string, mixed>
     */
    protected function flattenExpressions(array $conditions): array
    {
        foreach ($conditions as $key => $value) {
            if ($value instanceof MongoExpressionInterface) {
                $conditions[$key] = $value->getConditions();
            } elseif (is_array($value)) {
                $conditions[$key] = $this->flattenExpressions($value);
            }
        }

        return $conditions;
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
     * @param array<int, array<int|string, mixed>>|array<int|string, mixed> $stages Pipeline stages to add
     * @return $this
     */
    public function pipeline(array $stages): static
    {
        if ($stages === []) {
            return $this;
        }

        if (isset($stages[0]) && is_array($stages[0])) {
            foreach ($stages as $stage) {
                $this->pipeline[] = $stage;
            }
        } else {
            $this->pipeline[] = $stages;
        }

        return $this;
    }

    /**
     * Removes previously attached aggregation pipeline stages.
     *
     * @param array<int, array<int|string, mixed>> $stages Stages to remove.
     * @return $this
     */
    public function removePipelineStages(array $stages): static
    {
        if ($stages === []) {
            return $this;
        }

        $this->pipeline = array_values(array_filter(
            $this->pipeline,
            static fn(array $stage): bool => !in_array($stage, $stages, true),
        ));

        return $this;
    }

    /**
     * Appends a `$count` stage returning the number of documents so far.
     *
     * Renders as `['$count' => $field]` via the aggregation `Count` stage so the
     * caller never embeds a raw pipeline array.
     *
     * @param string $field The output field name for the count.
     * @return static
     */
    public function count(string $field): static
    {
        $builder = new AggregationBuilder();
        $stage = $builder->count($field)->getExpression();

        return $this->pipeline([$stage]);
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
        if ($this->pipeline !== [] || $this->group !== [] || $this->having !== [] || $this->distinct !== [] || $this->hasComputedProjection()) {
            return $this->compileAggregate();
        }

        return $this->compileFind();
    }

    /**
     * Whether the projection contains computed (field-path) values.
     *
     * A projection value of the form `$field` (e.g. `select(['extra' => '_id'])`)
     * or an aggregation operator document (e.g. `select(['two' => $q->func()->add(1, 1)])`)
     * cannot be expressed in a find() projection, so the query must compile as an
     * aggregation `$project` stage.
     *
     * @return bool
     */
    protected function hasComputedProjection(): bool
    {
        foreach ($this->projection as $value) {
            if (is_string($value) && str_starts_with($value, '$')) {
                return true;
            }

            if (is_array($value)) {
                $operator = array_key_first($value);
                if ($operator === '$literal') {
                    continue;
                }

                return true;
            }
        }

        return false;
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

        // Split ad-hoc stages: joinWith/matching (`$lookup`/`$unwind`/`$match`
        // prefix) must run before `$group` (SQL JOIN → GROUP BY), while stages
        // like `$count` (performCount) must run after the grouped row set.
        [$preGroup, $postGroup] = $headIsFixed
            ? [[], $this->pipeline]
            : $this->splitPipelineAroundGroup($this->pipeline);

        foreach ($preGroup as $stage) {
            $pipeline[] = $stage;
        }

        if ($this->group !== []) {
            $pipeline[] = ['$group' => $this->buildGroupStage()];
        }

        if ($this->having !== []) {
            $pipeline[] = ['$match' => $this->having];
        }

        foreach ($postGroup as $stage) {
            $pipeline[] = $stage;
        }

        if ($this->distinct !== []) {
            $pipeline[] = ['$group' => $this->buildDistinctStage()];
            $pipeline[] = ['$replaceRoot' => ['newRoot' => '$_doc']];
        }

        if ($this->sort !== []) {
            $pipeline[] = ['$sort' => $this->sort];
        }

        if ($this->projection !== []) {
            $projection = $this->group !== []
                ? $this->buildGroupProjection()
                : $this->projection;
            $pipeline[] = ['$project' => $projection];
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
     * Splits stored pipeline stages into pre-`$group` joins and post-`$group` work.
     *
     * A leading run of `$lookup` / `$unwind` / `$match` is treated as the
     * joinWith/matching prefix (must precede GROUP BY). Everything after that
     * prefix — notably `$count` from `performCount()` — stays after `$group`.
     *
     * @param array<int, array<int|string, mixed>> $stages The stored pipeline stages.
     * @return array{0: array<int, array<int|string, mixed>>, 1: array<int, array<int|string, mixed>>}
     */
    protected function splitPipelineAroundGroup(array $stages): array
    {
        $preGroup = [];
        $postGroup = [];
        $inJoinPrefix = true;

        foreach ($stages as $stage) {
            $key = array_key_first($stage);
            if ($inJoinPrefix && in_array($key, ['$lookup', '$unwind', '$match'], true)) {
                $preGroup[] = $stage;
                continue;
            }

            $inJoinPrefix = false;
            $postGroup[] = $stage;
        }

        return [$preGroup, $postGroup];
    }

    /**
     * Builds the `$group` stage body from the configured group fields.
     *
     * @return array<string, mixed>
     */
    protected function buildGroupStage(): array
    {
        if (count($this->group) === 1) {
            $id = '$' . ltrim($this->group[0], '$');
        } else {
            $id = [];
            foreach ($this->group as $field) {
                $id[$field] = '$' . ltrim($field, '$');
            }
        }

        $group = ['_id' => $id];

        foreach ($this->projection as $field => $value) {
            if (in_array($field, $this->group, true)) {
                continue;
            }

            if (!is_array($value)) {
                // A plain field (functionally dependent on the group key) has
                // no accumulator; keep the group's representative via `$first`.
                if ($field !== '_id') {
                    $group[$field] = ['$first' => '$' . $field];
                }

                continue;
            }

            $operator = key($value);
            if (!is_string($operator)) {
                continue;
            }

            if (!str_starts_with($operator, '$')) {
                continue;
            }

            // `COUNT(*)` has no `$group` accumulator; the Mongo analog is `$sum: 1`.
            if ($operator === '$count' && empty((array)$value['$count'])) {
                $value = ['$sum' => 1];
                $group[$field] = $value;

                continue;
            }

            if (in_array($operator, self::GROUP_ACCUMULATORS, true)) {
                $group[$field] = $value;

                continue;
            }

            // A computed (non-aggregate) projection field has no accumulator;
            // keep the group's representative value via `$first` (cake subquery
            // semantics: a per-row expression over the group key).
            $group[$field] = ['$first' => $value];
        }

        return $group;
    }

    /**
     * Operators valid as `$group` accumulators.
     *
     * @var list<string>
     */
    protected const GROUP_ACCUMULATORS = [
        '$sum', '$avg', '$min', '$max', '$push', '$addToSet', '$first', '$last',
        '$mergeObjects', '$stdDevPop', '$stdDevSamp',
    ];

    /**
     * Builds the `$project` stage for a `$group` pipeline.
     *
     * After `$group` the source fields live under `_id` and aggregate
     * expressions are already materialized as accumulator fields, so the raw
     * projection cannot be applied verbatim. Plain group fields are restored
     * from `_id`; accumulator fields are passed through; `_id` is dropped.
     *
     * @return array<string, mixed>
     */
    protected function buildGroupProjection(): array
    {
        $projection = [];
        foreach ($this->projection as $field => $value) {
            if ($field === '_id') {
                continue;
            }

            if (in_array($field, $this->group, true)) {
                $projection[$field] = count($this->group) === 1 ? '$_id' : '$_id.' . $field;

                continue;
            }

            $isAggregate = is_array($value)
                && ($operator = key($value)) !== null
                && is_string($operator)
                && str_starts_with($operator, '$');

            // Aggregate accumulators and plain `$first` fields are materialized
            // by `$group`; project them through as-is.
            if ($isAggregate || !is_array($value)) {
                $projection[$field] = 1;
            }
        }

        // Eager loaders (HasMany select/subquery) match on a scalar source `_id`.
        // A multi-field `$group` stores the key as `_id: { _id: …, name: … }`;
        // projecting `_id: 1` would keep that document and break FK collection.
        if (count($this->group) > 1 && in_array('_id', $this->group, true)) {
            $projection['_id'] = '$_id._id';
        } elseif (count($this->group) === 1 && $this->group[0] === '_id') {
            $projection['_id'] = '$_id';
        } else {
            $projection['_id'] = (int)($this->projection['_id'] ?? 0) === 1 ? 1 : 0;
        }

        return $projection;
    }

    /**
     * Builds the `$group` stage body for distinct deduplication.
     *
     * Groups by the distinct fields and keeps the first full document per
     * group under `_doc` so the following `$replaceRoot` can restore it.
     *
     * @return array<string, mixed>
     */
    protected function buildDistinctStage(): array
    {
        if (count($this->distinct) === 1) {
            $id = '$' . ltrim($this->distinct[0], '$');
        } else {
            $id = [];
            foreach ($this->distinct as $field) {
                $id[$field] = '$' . ltrim($field, '$');
            }
        }

        return ['_id' => $id, '_doc' => ['$first' => '$$ROOT']];
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
        foreach ($conditions as $key => $value) {
            if (is_string($key) && (str_starts_with($key, '$') || in_array(strtoupper($key), ['AND', 'OR', 'NOT'], true))) {
                $conditions[$key] = is_array($value) ? $this->castConditions($value, $types) : $value;
                continue;
            }

            $field = is_string($key) && str_contains($key, ' ') ? explode(' ', $key)[0] : $key;
            $bareField = is_string($field) ? $this->resolveField($field) : $field;
            if (is_string($field) && isset($types[$field])) {
                $conditions[$key] = $this->castValue($value, $types[$field]);
            } elseif (is_string($bareField) && isset($this->typeMap[$bareField])) {
                $conditions[$key] = $this->castValue($value, $this->typeMap[$bareField]);
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

        if ($type === 'array') {
            return $value;
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(fn(mixed $item): mixed => $this->castValue($item, $type), $value);
            }

            foreach ($value as $operator => $operand) {
                if (is_string($operator) && str_starts_with($operator, '$')) {
                    $value[$operator] = $this->castValue($operand, $type);
                }
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
        $this->distinct = [];
        $this->having = [];

        return $this;
    }
}

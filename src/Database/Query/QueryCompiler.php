<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Query;

use Closure;
use Crustum\Mongo\Database\Expression\MongoExpressionInterface;
use Crustum\Mongo\Database\QueryBuilder as ExpressionBuilder;

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
     * @var array
     */
    protected array $filter = [];

    /**
     * Field projection (which fields to return)
     *
     * @var array
     */
    protected array $projection = [];

    /**
     * Sort order
     *
     * @var array
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
     * @var array
     */
    protected array $pipeline = [];

    /**
     * Additional MongoDB options
     *
     * @var array
     */
    protected array $options = [];

    /**
     * Expression builder for complex conditions
     *
     * @var \Crustum\Mongo\Database\QueryBuilder
     */
    protected ExpressionBuilder $expressionBuilder;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->expressionBuilder = new ExpressionBuilder();
    }

    /**
     * Add filter conditions to the query.
     *
     * @param \Closure|array|string|null $conditions The conditions to add
     * @param bool                       $overwrite  Whether to overwrite existing conditions
     * @return $this
     */
    public function where(array|string|Closure|null $conditions, bool $overwrite = false)
    {
        if ($conditions === null) {
            return $this;
        }

        if ($conditions instanceof Closure) {
            $conditions = $conditions($this->expressionBuilder);
        }

        if ($conditions instanceof MongoExpressionInterface) {
            $conditions = $conditions->getConditions();
        }

        if (is_string($conditions)) {
            $conditions = [$conditions];
        }

        if (is_array($conditions)) {
            $parsed = $this->expressionBuilder->parse($conditions);
            $this->filter = $overwrite ? $parsed : array_merge_recursive($this->filter, $parsed);
        }

        return $this;
    }

    /**
     * Add additional conditions using $and operator.
     *
     * @param \Closure|array|string $conditions The conditions to add
     * @return $this
     */
    public function andWhere(array|string|Closure $conditions)
    {
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
     * @param array|string $fields    Fields to include/exclude
     * @param bool         $overwrite Whether to overwrite existing projection
     * @return $this
     */
    public function select(array|string $fields, bool $overwrite = false)
    {
        if (is_string($fields)) {
            $fields = [$fields];
        }

        $projection = [];
        foreach ($fields as $key => $value) {
            if (is_numeric($key)) {
                $projection[$value] = 1;
            } else {
                $projection[$key] = $value;
            }
        }

        $this->projection = $overwrite ? $projection : array_merge($this->projection, $projection);

        return $this;
    }

    /**
     * Set sort order.
     *
     * @param array|string $fields    Fields to sort by
     * @param bool         $overwrite Whether to overwrite existing sort
     * @return $this
     */
    public function orderBy(array|string $fields, bool $overwrite = false)
    {
        if (is_string($fields)) {
            $fields = [$fields => 1];
        }

        $normalized = [];
        foreach ($fields as $field => $direction) {
            if (is_string($direction)) {
                $direction = strtolower($direction) === 'desc' ? -1 : 1;
            }

            $normalized[$field] = $direction;
        }

        $this->sort = $overwrite ? $normalized : array_merge($this->sort, $normalized);

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
     * @param array $stages Pipeline stages to add
     * @return $this
     */
    public function pipeline(array $stages)
    {
        if (isset($stages[0]) && is_array($stages[0])) {
            $this->pipeline = array_merge($this->pipeline, $stages);
        } else {
            $this->pipeline[] = $stages;
        }

        return $this;
    }

    /**
     * Set MongoDB options.
     *
     * @param array $options Options to set
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
     * @return array Compiled query
     */
    public function compile(): array
    {
        if ($this->pipeline !== []) {
            return $this->compileAggregate();
        }

        return $this->compileFind();
    }

    /**
     * Compile as find() query.
     *
     * @return array
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
     * @return array
     */
    protected function compileAggregate(): array
    {
        $pipeline = $this->pipeline;

        if ($this->filter !== []) {
            array_unshift($pipeline, ['$match' => $this->filter]);
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
     * @return array
     */
    public function getFilter(): array
    {
        return $this->filter;
    }

    /**
     * Get the projection fields
     *
     * @return array
     */
    public function getProjection(): array
    {
        return $this->projection;
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

        return $this;
    }
}

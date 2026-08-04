<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Aggregation\Stage;

use Crustum\Mongo\Database\Aggregation\AggregationBuilder;

/**
 * $setWindowFields aggregation stage
 *
 * Performs operations on a specified span of documents in a collection
 */
class SetWindowFields extends Stage
{
    /**
     * The partition by expression
     *
     * @var array<string, mixed>|string|null
     */
    protected array|string|null $partitionBy = null;

    /**
     * The sort by specification
     *
     * @var array<string, int>|null
     */
    protected ?array $sortBy = null;

    /**
     * The output specification
     *
     * @var array<string, mixed>
     */
    protected array $output = [];

    /**
     * Constructor
     *
     * @param \Crustum\Mongo\Database\Aggregation\AggregationBuilder $builder The aggregation builder
     */
    public function __construct(AggregationBuilder $builder)
    {
        parent::__construct($builder);
    }

    /**
     * Set partition by expression
     *
     * @param array<string, mixed>|string $expression The partition expression
     * @return $this
     */
    public function partitionBy(array|string $expression)
    {
        $this->partitionBy = $expression;

        return $this;
    }

    /**
     * Set sort by specification
     *
     * @param array<string, int> $sortBy The sort specification
     * @return $this
     */
    public function sortBy(array $sortBy)
    {
        $this->sortBy = $sortBy;

        return $this;
    }

    /**
     * Add output field with window function
     *
     * @param string                      $field      The field name
     * @param array<string, mixed>|string $expression The window function expression
     * @return $this
     */
    public function output(string $field, array|string $expression)
    {
        $this->output[$field] = $expression;

        return $this;
    }

    /**
     * Get the MongoDB aggregation stage expression
     *
     * @return array<string, mixed> The $setWindowFields stage expression
     */
    public function getExpression(): array
    {
        $setWindowFields = ['output' => $this->output];

        if ($this->partitionBy !== null) {
            $setWindowFields['partitionBy'] = $this->partitionBy;
        }

        if ($this->sortBy !== null) {
            $setWindowFields['sortBy'] = $this->sortBy;
        }

        return ['$setWindowFields' => $setWindowFields];
    }
}

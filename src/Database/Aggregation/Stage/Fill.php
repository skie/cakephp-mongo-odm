<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Aggregation\Stage;

use Crustum\Mongo\Database\Aggregation\AggregationBuilder;

/**
 * $fill aggregation stage
 *
 * Populates null and missing field values within documents
 *
 * @inspired-by \Doctrine\ODM\MongoDB\Aggregation\Stage\Fill
 */
class Fill extends Stage
{
    /**
     * The partition by fields
     *
     * @var array<string>|null
     */
    protected ?array $partitionBy = null;

    /**
     * The partition by expression
     *
     * @var array<string, mixed>|null
     */
    protected array|string|null $partitionByExpr = null;

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
     * Set partition by fields
     *
     * @param array<string> $fields The fields to partition by
     * @return $this
     */
    public function partitionBy(array $fields)
    {
        $this->partitionBy = $fields;

        return $this;
    }

    /**
     * Set partition by expression
     *
     * @param array<string, mixed>|string $expression The partition expression
     * @return $this
     */
    public function partitionByExpr(array|string $expression)
    {
        $this->partitionByExpr = $expression;

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
     * Add output field
     *
     * @param string                      $field      The field name
     * @param array<string, mixed>|string $value      The fill value or method
     * @return $this
     */
    public function output(string $field, array|string $value)
    {
        $this->output[$field] = $value;

        return $this;
    }

    /**
     * Get the MongoDB aggregation stage expression
     *
     * @return array<string, mixed> The $fill stage expression
     */
    public function getExpression(): array
    {
        $fill = ['output' => $this->output];

        if ($this->partitionBy !== null) {
            $fill['partitionBy'] = $this->partitionBy;
        }

        if ($this->partitionByExpr !== null) {
            $fill['partitionBy'] = $this->partitionByExpr;
        }

        if ($this->sortBy !== null) {
            $fill['sortBy'] = $this->sortBy;
        }

        return ['$fill' => $fill];
    }
}

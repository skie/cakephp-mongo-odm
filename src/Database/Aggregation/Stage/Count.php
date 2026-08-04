<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Aggregation\Stage;

use Crustum\Mongo\Database\Aggregation\AggregationBuilder;

/**
 * $count aggregation stage
 *
 * Returns a count of the number of documents at this stage of the aggregation pipeline
 */
class Count extends Stage
{
    /**
     * The output field name for the count
     *
     * @var string
     */
    protected string $field;

    /**
     * Constructor
     *
     * @param \Crustum\Mongo\Database\Aggregation\AggregationBuilder $builder The aggregation builder
     * @param string                                            $field   The output field name for the count
     */
    public function __construct(AggregationBuilder $builder, string $field)
    {
        parent::__construct($builder);
        $this->field = $field;
    }

    /**
     * Get the MongoDB aggregation stage expression
     *
     * @return array<string, mixed> The $count stage expression
     */
    public function getExpression(): array
    {
        return ['$count' => $this->field];
    }
}

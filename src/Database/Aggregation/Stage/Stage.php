<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Aggregation\Stage;

use Crustum\Mongo\Database\Aggregation\AggregationBuilder;

/**
 * Base class for aggregation pipeline stages
 *
 * Provides common functionality for all aggregation stages
 */
abstract class Stage
{
    /**
     * The aggregation builder this stage belongs to
     *
     * @var \Crustum\Mongo\Database\Aggregation\AggregationBuilder
     */
    protected AggregationBuilder $builder;

    /**
     * Constructor
     *
     * @param \Crustum\Mongo\Database\Aggregation\AggregationBuilder $builder The aggregation builder
     */
    public function __construct(AggregationBuilder $builder)
    {
        $this->builder = $builder;
    }

    /**
     * Get the aggregation builder
     *
     * @return \Crustum\Mongo\Database\Aggregation\AggregationBuilder
     */
    public function getBuilder(): AggregationBuilder
    {
        return $this->builder;
    }

    /**
     * Get the MongoDB aggregation stage expression
     *
     * @return array<string, mixed> The stage expression as an array
     */
    abstract public function getExpression(): array;
}

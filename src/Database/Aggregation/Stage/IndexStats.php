<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Aggregation\Stage;

use Crustum\Mongo\Database\Aggregation\AggregationBuilder;

/**
 * $indexStats aggregation stage
 *
 * Returns statistics regarding the use of each index for a collection
 */
class IndexStats extends Stage
{
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
     * Get the MongoDB aggregation stage expression
     *
     * @return array<string, mixed> The $indexStats stage expression
     */
    public function getExpression(): array
    {
        return ['$indexStats' => (object)[]];
    }
}

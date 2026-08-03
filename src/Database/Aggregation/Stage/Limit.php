<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Aggregation\Stage;

use Crustum\Mongo\Database\Aggregation\AggregationBuilder;

/**
 * $limit aggregation stage
 *
 * Limits the number of documents passed to the next stage
 */
class Limit extends Stage
{
    /**
     * The limit value
     *
     * @var int
     */
    protected int $limit;

    /**
     * Constructor
     *
     * @param \Crustum\Mongo\Database\Aggregation\AggregationBuilder $builder The aggregation builder
     * @param int                                               $limit   The number of documents to limit
     */
    public function __construct(AggregationBuilder $builder, int $limit)
    {
        parent::__construct($builder);
        $this->limit = $limit;
    }

    /**
     * Get the MongoDB aggregation stage expression
     *
     * @return array The $limit stage expression
     */
    public function getExpression(): array
    {
        return ['$limit' => $this->limit];
    }
}

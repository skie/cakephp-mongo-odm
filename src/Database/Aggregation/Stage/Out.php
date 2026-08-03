<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Aggregation\Stage;

use Crustum\Mongo\Database\Aggregation\AggregationBuilder;

/**
 * $out aggregation stage
 *
 * Writes the results of the aggregation pipeline to a collection
 */
class Out extends Stage
{
    /**
     * The target collection
     *
     * @var string
     */
    protected string $collection;

    /**
     * Constructor
     *
     * @param \Crustum\Mongo\Database\Aggregation\AggregationBuilder $builder     The aggregation builder
     * @param string                                            $collection  The target collection name
     */
    public function __construct(AggregationBuilder $builder, string $collection)
    {
        parent::__construct($builder);
        $this->collection = $collection;
    }

    /**
     * Get the MongoDB aggregation stage expression
     *
     * @return array The $out stage expression
     */
    public function getExpression(): array
    {
        return ['$out' => $this->collection];
    }
}

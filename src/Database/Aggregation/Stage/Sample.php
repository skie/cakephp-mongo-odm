<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Aggregation\Stage;

use Crustum\Mongo\Database\Aggregation\AggregationBuilder;

/**
 * $sample aggregation stage
 *
 * Randomly selects the specified number of documents
 */
class Sample extends Stage
{
    /**
     * The sample size
     *
     * @var int
     */
    protected int $size;

    /**
     * Constructor
     *
     * @param \Crustum\Mongo\Database\Aggregation\AggregationBuilder $builder The aggregation builder
     * @param int                                               $size    The sample size
     */
    public function __construct(AggregationBuilder $builder, int $size)
    {
        parent::__construct($builder);
        $this->size = $size;
    }

    /**
     * Get the MongoDB aggregation stage expression
     *
     * @return array The $sample stage expression
     */
    public function getExpression(): array
    {
        return ['$sample' => ['size' => $this->size]];
    }
}

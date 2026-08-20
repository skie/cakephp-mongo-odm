<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Aggregation\Stage;

use Crustum\Mongo\Database\Aggregation\AggregationBuilder;

/**
 * $skip aggregation stage
 *
 * Skips a specified number of documents and passes the remaining documents to the next stage
 *
 * @ported-from \Doctrine\ODM\MongoDB\Aggregation\Stage\Skip
 */
class Skip extends Stage
{
    /**
     * The skip value
     *
     * @var int
     */
    protected int $skip;

    /**
     * Constructor
     *
     * @param \Crustum\Mongo\Database\Aggregation\AggregationBuilder $builder The aggregation builder
     * @param int                                               $skip    The number of documents to skip
     */
    public function __construct(AggregationBuilder $builder, int $skip)
    {
        parent::__construct($builder);
        $this->skip = $skip;
    }

    /**
     * Get the MongoDB aggregation stage expression
     *
     * @return array<string, mixed> The $skip stage expression
     */
    public function getExpression(): array
    {
        return ['$skip' => $this->skip];
    }
}

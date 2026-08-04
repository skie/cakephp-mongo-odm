<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Aggregation\Stage;

use Crustum\Mongo\Database\Aggregation\AggregationBuilder;

/**
 * $sort aggregation stage
 *
 * Reorders documents by a specified sort specification.
 *
 * @see mongodb-odm Aggregation/Stage/Sort.php
 */
class Sort extends Stage
{
    /**
     * The sort specification.
     *
     * @var array<string, int|string>
     */
    protected array $sort;

    /**
     * Constructor
     *
     * @param \Crustum\Mongo\Database\Aggregation\AggregationBuilder $builder The aggregation builder
     * @param array<string, int|string> $sort The sort specification
     */
    public function __construct(AggregationBuilder $builder, array $sort = [])
    {
        parent::__construct($builder);
        $this->sort = $sort;
    }

    /**
     * Merge additional sort keys into the specification.
     *
     * @param array<string, int|string> $sort The sort keys to add
     * @return $this
     */
    public function add(array $sort): static
    {
        $this->sort = $sort + $this->sort;

        return $this;
    }

    /**
     * Get the MongoDB aggregation stage expression
     *
     * @return array<string, mixed> $sort The sort stage expression
     */
    public function getExpression(): array
    {
        return ['$sort' => $this->sort];
    }
}

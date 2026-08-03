<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Aggregation\Stage;

use Crustum\Mongo\Database\Aggregation\AggregationBuilder;

/**
 * $sortByCount aggregation stage
 *
 * Groups incoming documents and counts them, then sorts by count in descending order
 */
class SortByCount extends Stage
{
    /**
     * The expression to group by
     *
     * @var array<string, mixed>|string
     */
    protected array|string $expression;

    /**
     * Constructor
     *
     * @param \Crustum\Mongo\Database\Aggregation\AggregationBuilder $builder    The aggregation builder
     * @param array<string, mixed>|string                        $expression The expression to group by
     */
    public function __construct(AggregationBuilder $builder, array|string $expression)
    {
        parent::__construct($builder);
        $this->expression = $expression;
    }

    /**
     * Get the MongoDB aggregation stage expression
     *
     * @return array The $sortByCount stage expression
     */
    public function getExpression(): array
    {
        return ['$sortByCount' => $this->expression];
    }
}

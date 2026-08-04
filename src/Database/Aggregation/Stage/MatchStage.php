<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Aggregation\Stage;

use Crustum\Mongo\Database\Aggregation\AggregationBuilder;

/**
 * $match aggregation stage
 *
 * Filters documents to pass only those matching the given conditions to the
 * next stage.
 *
 * @see mongodb-odm Aggregation/Stage/MatchStage.php
 */
class MatchStage extends Stage
{
    /**
     * The match conditions.
     *
     * @var array<string, mixed>
     */
    protected array $criteria;

    /**
     * Constructor
     *
     * @param \Crustum\Mongo\Database\Aggregation\AggregationBuilder $builder The aggregation builder
     * @param array<string, mixed> $criteria The match conditions
     */
    public function __construct(AggregationBuilder $builder, array $criteria = [])
    {
        parent::__construct($builder);
        $this->criteria = $criteria;
    }

    /**
     * Merge additional conditions into the $match query.
     *
     * Existing conditions are combined with the given ones using `$and` so
     * nothing is overwritten.
     *
     * @param array<string, mixed> $conditions Additional match conditions
     * @return $this
     */
    public function add(array $conditions): static
    {
        if ($conditions === []) {
            return $this;
        }

        if ($this->criteria === []) {
            $this->criteria = $conditions;

            return $this;
        }

        $this->criteria = [
            '$and' => [
                $this->criteria,
                $conditions,
            ],
        ];

        return $this;
    }

    /**
     * Get the MongoDB aggregation stage expression
     *
     * @return array<string, mixed> $match The match stage expression
     */
    public function getExpression(): array
    {
        return ['$match' => $this->criteria];
    }
}

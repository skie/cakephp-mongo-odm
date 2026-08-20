<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Aggregation\Stage;

use Crustum\Mongo\Database\Aggregation\AggregationBuilder;

/**
 * $group aggregation stage
 *
 * Groups input documents by the `_id` expression and applies accumulator
 * expressions to produce output documents.
 *
 * @inspired-by \Doctrine\ODM\MongoDB\Aggregation\Stage\Group
 */
class Group extends Stage
{
    /**
     * The grouping specification.
     *
     * @var array<string, mixed>
     */
    protected array $group;

    /**
     * Constructor
     *
     * @param \Crustum\Mongo\Database\Aggregation\AggregationBuilder $builder The aggregation builder
     * @param array<string, mixed> $group The grouping specification
     */
    public function __construct(AggregationBuilder $builder, array $group = [])
    {
        parent::__construct($builder);
        $this->group = $group;
    }

    /**
     * Merge accumulator groups into the specification.
     *
     * @param array<string, mixed> $group The fields to add
     * @return $this
     */
    public function add(array $group): static
    {
        $this->group = $group + $this->group;

        return $this;
    }

    /**
     * Get the MongoDB aggregation stage expression
     *
     * @return array<string, mixed> $group The group stage expression
     */
    public function getExpression(): array
    {
        return ['$group' => $this->group];
    }
}

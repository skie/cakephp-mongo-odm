<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Aggregation\Stage;

use Crustum\Mongo\Database\Aggregation\AggregationBuilder;

/**
 * $unset aggregation stage
 *
 * Removes/excludes fields from documents
 */
class UnsetStage extends Stage
{
    /**
     * The fields to remove
     *
     * @var array<string>|string
     */
    protected array|string $fields;

    /**
     * Constructor
     *
     * @param \Crustum\Mongo\Database\Aggregation\AggregationBuilder $builder The aggregation builder
     * @param array<string>|string                              $fields  The field(s) to remove
     */
    public function __construct(AggregationBuilder $builder, array|string $fields)
    {
        parent::__construct($builder);
        $this->fields = $fields;
    }

    /**
     * Get the MongoDB aggregation stage expression
     *
     * @return array The $unset stage expression
     */
    public function getExpression(): array
    {
        return ['$unset' => $this->fields];
    }
}

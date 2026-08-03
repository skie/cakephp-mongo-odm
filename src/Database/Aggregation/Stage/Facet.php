<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Aggregation\Stage;

use Crustum\Mongo\Database\Aggregation\AggregationBuilder;

/**
 * $facet aggregation stage
 *
 * Processes multiple aggregation pipelines within a single stage
 */
class Facet extends Stage
{
    /**
     * The facet pipelines
     *
     * @var array<string, array<array<string, mixed>>>
     */
    protected array $facets = [];

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
     * Add a facet pipeline
     *
     * @param string                      $name    The facet name
     * @param array<array<string, mixed>> $pipeline The pipeline stages
     * @return $this
     */
    public function facet(string $name, array $pipeline)
    {
        $this->facets[$name] = $pipeline;

        return $this;
    }

    /**
     * Get the MongoDB aggregation stage expression
     *
     * @return array The $facet stage expression
     */
    public function getExpression(): array
    {
        return ['$facet' => $this->facets];
    }
}

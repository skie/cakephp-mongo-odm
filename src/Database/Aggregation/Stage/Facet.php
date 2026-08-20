<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Aggregation\Stage;

use Closure;
use Crustum\Mongo\Database\Aggregation\AggregationBuilder;
use Crustum\Mongo\Database\Aggregation\Pipeline;

/**
 * $facet aggregation stage
 *
 * Processes multiple aggregation pipelines within a single stage
 *
 * @inspired-by \Doctrine\ODM\MongoDB\Aggregation\Stage\Facet
 */
class Facet extends Stage
{
    /**
     * The facet pipelines
     *
     * @var array<string, \Crustum\Mongo\Database\Aggregation\Pipeline>
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
     * @param string                                                     $name    The facet name
     * @param \Crustum\Mongo\Database\Aggregation\Pipeline|\Closure|array<array<string, mixed>> $pipeline The pipeline stages
     * @return $this
     */
    public function addFacet(string $name, Pipeline|array|Closure $pipeline)
    {
        $this->facets[$name] = $this->buildSubPipeline($pipeline);

        return $this;
    }

    /**
     * Get the MongoDB aggregation stage expression
     *
     * @return array<string, mixed> The $facet stage expression
     */
    public function getExpression(): array
    {
        $facets = [];
        foreach ($this->facets as $name => $pipeline) {
            $facets[$name] = $pipeline->compile();
        }

        return ['$facet' => $facets];
    }
}

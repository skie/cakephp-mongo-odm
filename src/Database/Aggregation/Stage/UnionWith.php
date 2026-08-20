<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Aggregation\Stage;

use Closure;
use Crustum\Mongo\Database\Aggregation\AggregationBuilder;
use Crustum\Mongo\Database\Aggregation\Pipeline;
use InvalidArgumentException;

/**
 * $unionWith aggregation stage
 *
 * Combines documents from multiple collections into a single result set
 *
 * @inspired-by \Doctrine\ODM\MongoDB\Aggregation\Stage\UnionWith
 */
class UnionWith extends Stage
{
    /**
     * The collection to union with
     *
     * @var string
     */
    protected string $coll;

    /**
     * The pipeline to apply
     *
     * @var \Crustum\Mongo\Database\Aggregation\Pipeline|null
     */
    protected ?Pipeline $pipeline = null;

    /**
     * Constructor
     *
     * @param \Crustum\Mongo\Database\Aggregation\AggregationBuilder $builder The aggregation builder
     * @param string                                            $coll    The collection name
     */
    public function __construct(AggregationBuilder $builder, string $coll)
    {
        parent::__construct($builder);
        $this->coll = $coll;
    }

    /**
     * Set the pipeline to apply
     *
     * @param \Crustum\Mongo\Database\Aggregation\Pipeline|\Closure|array<array<string, mixed>> $pipeline The pipeline stages
     * @return $this
     * @throws \InvalidArgumentException When the pipeline references the top-level pipeline itself
     */
    public function pipeline(Pipeline|array|Closure $pipeline)
    {
        $this->pipeline = $this->buildSubPipeline($pipeline);

        if ($this->pipeline === $this->getBuilder()->getPipelineInstance()) {
            throw new InvalidArgumentException('Cannot reference the pipeline itself as a sub-pipeline.');
        }

        return $this;
    }

    /**
     * Get the MongoDB aggregation stage expression
     *
     * @return array<string, mixed> The $unionWith stage expression
     */
    public function getExpression(): array
    {
        $unionWith = ['coll' => $this->coll];

        if ($this->pipeline instanceof Pipeline) {
            $unionWith['pipeline'] = $this->pipeline->compile();
        }

        return ['$unionWith' => $unionWith];
    }
}

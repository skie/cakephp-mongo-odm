<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Aggregation\Stage;

use Crustum\Mongo\Database\Aggregation\AggregationBuilder;

/**
 * $unionWith aggregation stage
 *
 * Combines documents from multiple collections into a single result set
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
     * @var array<array<string, mixed>>|null
     */
    protected ?array $pipeline = null;

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
     * @param array<array<string, mixed>> $pipeline The pipeline stages
     * @return $this
     */
    public function pipeline(array $pipeline)
    {
        $this->pipeline = $pipeline;

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

        if ($this->pipeline !== null) {
            $unionWith['pipeline'] = $this->pipeline;
        }

        return ['$unionWith' => $unionWith];
    }
}

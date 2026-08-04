<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Aggregation\Stage;

use Closure;
use Crustum\Mongo\Database\Aggregation\AggregationBuilder;
use Crustum\Mongo\Database\Aggregation\Pipeline;
use InvalidArgumentException;

/**
 * $lookup aggregation stage
 *
 * Performs a left outer join to another collection
 */
class Lookup extends Stage
{
    /**
     * The collection to join with
     *
     * @var string
     */
    protected string $from;

    /**
     * The local field for the join
     *
     * @var string|null
     */
    protected ?string $localField = null;

    /**
     * The foreign field for the join
     *
     * @var string|null
     */
    protected ?string $foreignField = null;

    /**
     * The alias for the joined results
     *
     * @var string|null
     */
    protected ?string $as = null;

    /**
     * Variables to use in the pipeline
     *
     * @var array<string, mixed>|null
     */
    protected ?array $let = null;

    /**
     * Pipeline to apply to the joined collection
     *
     * @var \Crustum\Mongo\Database\Aggregation\Pipeline|null
     */
    protected ?Pipeline $pipeline = null;

    /**
     * Constructor
     *
     * @param \Crustum\Mongo\Database\Aggregation\AggregationBuilder $builder The aggregation builder
     * @param string                                           $from    The collection name to join with
     */
    public function __construct(AggregationBuilder $builder, string $from)
    {
        parent::__construct($builder);
        $this->from = $from;
    }

    /**
     * Set the local field for the join
     *
     * @param string $localField The local field name
     * @return $this
     */
    public function localField(string $localField)
    {
        $this->localField = $localField;

        return $this;
    }

    /**
     * Set the foreign field for the join
     *
     * @param string $foreignField The foreign field name
     * @return $this
     */
    public function foreignField(string $foreignField)
    {
        $this->foreignField = $foreignField;

        return $this;
    }

    /**
     * Set the alias for the joined results
     *
     * @param string $as The alias name
     * @return $this
     */
    public function alias(string $as)
    {
        $this->as = $as;

        return $this;
    }

    /**
     * Set variables to use in the pipeline
     *
     * @param array<string, mixed> $let Variables array
     * @return $this
     */
    public function let(array $let)
    {
        $this->let = $let;

        return $this;
    }

    /**
     * Set the pipeline to apply to the joined collection
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
     * @return array<string, mixed> The $lookup stage expression
     */
    public function getExpression(): array
    {
        $lookup = ['from' => $this->from];

        if ($this->as !== null) {
            $lookup['as'] = $this->as;
        }

        if ($this->localField !== null) {
            $lookup['localField'] = $this->localField;
        }

        if ($this->foreignField !== null) {
            $lookup['foreignField'] = $this->foreignField;
        }

        if ($this->let !== null) {
            $lookup['let'] = $this->let;
        }

        if ($this->pipeline instanceof Pipeline) {
            $lookup['pipeline'] = $this->pipeline->compile();
        }

        return ['$lookup' => $lookup];
    }
}

<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Aggregation\Stage;

use Crustum\Mongo\Database\Aggregation\AggregationBuilder;

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
     * @var array<array<string, mixed>>|null
     */
    protected ?array $pipeline = null;

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
     * @return array The $lookup stage expression
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

        if ($this->pipeline !== null) {
            $lookup['pipeline'] = $this->pipeline;
        }

        return ['$lookup' => $lookup];
    }
}

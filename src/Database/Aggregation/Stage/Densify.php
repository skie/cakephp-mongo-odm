<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Aggregation\Stage;

use Crustum\Mongo\Database\Aggregation\AggregationBuilder;

/**
 * $densify aggregation stage
 *
 * Creates new documents in a sequence of documents where certain values are missing
 *
 * @inspired-by \Doctrine\ODM\MongoDB\Aggregation\Stage\Densify
 */
class Densify extends Stage
{
    /**
     * The field to densify
     *
     * @var string
     */
    protected string $field;

    /**
     * The partition by fields
     *
     * @var array<string>|null
     */
    protected ?array $partitionByFields = null;

    /**
     * The range specification
     *
     * @var array<string, mixed>|null
     */
    protected ?array $range = null;

    /**
     * Constructor
     *
     * @param \Crustum\Mongo\Database\Aggregation\AggregationBuilder $builder The aggregation builder
     * @param string                                            $field   The field to densify
     * @param array<string, mixed>|null                          $range   The range specification (optional)
     */
    public function __construct(AggregationBuilder $builder, string $field, ?array $range = null)
    {
        parent::__construct($builder);
        $this->field = $field;
        if ($range !== null) {
            $this->range = $range;
        }
    }

    /**
     * Set the range specification
     *
     * @param array<string, mixed> $range The range specification
     * @return $this
     */
    public function range(array $range)
    {
        $this->range = $range;

        return $this;
    }

    /**
     * Set partition by fields
     *
     * @param array<string> $fields The fields to partition by
     * @return $this
     */
    public function partitionByFields(array $fields)
    {
        $this->partitionByFields = $fields;

        return $this;
    }

    /**
     * Get the MongoDB aggregation stage expression
     *
     * @return array<string, mixed> The $densify stage expression
     */
    public function getExpression(): array
    {
        $densify = ['field' => $this->field];

        if ($this->range !== null) {
            $densify['range'] = $this->range;
        }

        if ($this->partitionByFields !== null) {
            $densify['partitionByFields'] = $this->partitionByFields;
        }

        return ['$densify' => $densify];
    }
}

<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Aggregation\Stage;

use Crustum\Mongo\Database\Aggregation\AggregationBuilder;

/**
 * $project aggregation stage
 *
 * Passes along documents with only the specified fields, or computes new
 * fields, depending on the projection specification.
 *
 * @inspired-by \Doctrine\ODM\MongoDB\Aggregation\Stage\Project
 */
class Project extends Stage
{
    /**
     * The aggregation operator name.
     *
     * @var string
     */
    public const string OPERATOR = '$project';

    /**
     * The projection specification.
     *
     * @var array<string, mixed>
     */
    protected array $projection;

    /**
     * Constructor
     *
     * @param \Crustum\Mongo\Database\Aggregation\AggregationBuilder $builder The aggregation builder
     * @param array<string, mixed> $projection The projection specification
     */
    public function __construct(AggregationBuilder $builder, array $projection = [])
    {
        parent::__construct($builder);
        $this->projection = $projection;
    }

    /**
     * Merge additional fields into the projection.
     *
     * @param array<string, mixed> $projection The fields to add
     * @return $this
     */
    public function add(array $projection): static
    {
        $this->projection = $projection + $this->projection;

        return $this;
    }

    /**
     * Get the MongoDB aggregation stage expression
     *
     * @return array<string, mixed> $project The project stage expression
     */
    public function getExpression(): array
    {
        return [self::OPERATOR => $this->projection];
    }
}

<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Aggregation\Stage;

use Crustum\Mongo\Database\Aggregation\AggregationBuilder;

/**
 * $bucketAuto aggregation stage
 *
 * Automatically categorizes documents into a specified number of buckets
 */
class BucketAuto extends Stage
{
    /**
     * The expression to group by
     *
     * @var array<string, mixed>|string
     */
    protected array|string $groupBy;

    /**
     * The number of buckets
     *
     * @var int
     */
    protected int $buckets;

    /**
     * The output specification
     *
     * @var array<string, mixed>|null
     */
    protected ?array $output = null;

    /**
     * The granularity value
     *
     * @var string|null
     */
    protected ?string $granularity = null;

    /**
     * Constructor
     *
     * @param \Crustum\Mongo\Database\Aggregation\AggregationBuilder $builder The aggregation builder
     * @param array<string, mixed>|string                      $groupBy The expression to group by
     * @param int                                               $buckets The number of buckets
     */
    public function __construct(AggregationBuilder $builder, array|string $groupBy, int $buckets)
    {
        parent::__construct($builder);
        $this->groupBy = $groupBy;
        $this->buckets = $buckets;
    }

    /**
     * Set the output specification
     *
     * @param array<string, mixed> $output The output specification
     * @return $this
     */
    public function output(array $output)
    {
        $this->output = $output;

        return $this;
    }

    /**
     * Set the granularity
     *
     * @param string $granularity The granularity value (R5, R10, R20, R40, R80, 1-2-5, E6, E12, E24, E48, E96, E192, POWERSOF2)
     * @return $this
     */
    public function granularity(string $granularity)
    {
        $this->granularity = $granularity;

        return $this;
    }

    /**
     * Get the MongoDB aggregation stage expression
     *
     * @return array<string, mixed> The $bucketAuto stage expression
     */
    public function getExpression(): array
    {
        $bucketAuto = [
            'groupBy' => $this->groupBy,
            'buckets' => $this->buckets,
        ];

        if ($this->output !== null) {
            $bucketAuto['output'] = $this->output;
        }

        if ($this->granularity !== null) {
            $bucketAuto['granularity'] = $this->granularity;
        }

        return ['$bucketAuto' => $bucketAuto];
    }
}

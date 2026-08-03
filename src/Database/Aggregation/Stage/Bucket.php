<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Aggregation\Stage;

use Crustum\Mongo\Database\Aggregation\AggregationBuilder;

/**
 * $bucket aggregation stage
 *
 * Categorizes documents into buckets based on specified boundaries
 */
class Bucket extends Stage
{
    /**
     * The expression to group by
     *
     * @var array<string, mixed>|string
     */
    protected array|string $groupBy;

    /**
     * The boundaries array
     *
     * @var array<int|float>
     */
    protected array $boundaries;

    /**
     * The default bucket value
     *
     * @var mixed
     */
    protected mixed $default = null;

    /**
     * The output specification
     *
     * @var array<string, mixed>|null
     */
    protected ?array $output = null;

    /**
     * Constructor
     *
     * @param \Crustum\Mongo\Database\Aggregation\AggregationBuilder $builder    The aggregation builder
     * @param array<string, mixed>|string                       $groupBy    The expression to group by
     * @param array<int|float>                                  $boundaries The boundaries array
     */
    public function __construct(AggregationBuilder $builder, array|string $groupBy, array $boundaries)
    {
        parent::__construct($builder);
        $this->groupBy = $groupBy;
        $this->boundaries = $boundaries;
    }

    /**
     * Set the default bucket value
     *
     * @param mixed $default The default value
     * @return $this
     */
    public function default(mixed $default)
    {
        $this->default = $default;

        return $this;
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
     * Get the MongoDB aggregation stage expression
     *
     * @return array The $bucket stage expression
     */
    public function getExpression(): array
    {
        $bucket = [
            'groupBy' => $this->groupBy,
            'boundaries' => $this->boundaries,
        ];

        if ($this->default !== null) {
            $bucket['default'] = $this->default;
        }

        if ($this->output !== null) {
            $bucket['output'] = $this->output;
        }

        return ['$bucket' => $bucket];
    }
}

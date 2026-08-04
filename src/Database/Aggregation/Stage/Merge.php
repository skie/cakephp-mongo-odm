<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Aggregation\Stage;

use Crustum\Mongo\Database\Aggregation\AggregationBuilder;

/**
 * $merge aggregation stage
 *
 * Writes the results of the aggregation pipeline to a collection
 */
class Merge extends Stage
{
    /**
     * The target collection
     *
     * @var array<string, mixed>|string
     */
    protected string|array $into;

    /**
     * The merge strategy
     *
     * @var array<int, string>|string|null
     */
    protected string|array|null $on = null;

    /**
     * When matched action
     *
     * @var array<string, mixed>|string|null
     */
    protected string|array|null $whenMatched = null;

    /**
     * When not matched action
     *
     * @var array<string, mixed>|string|null
     */
    protected string|array|null $whenNotMatched = null;

    /**
     * Let variables
     *
     * @var array<string, mixed>|null
     */
    protected ?array $let = null;

    /**
     * Constructor
     *
     * @param \Crustum\Mongo\Database\Aggregation\AggregationBuilder $builder The aggregation builder
     * @param array<string, mixed>|string $into The target collection or database
     */
    public function __construct(AggregationBuilder $builder, string|array $into)
    {
        parent::__construct($builder);
        $this->into = $into;
    }

    /**
     * Set the merge key fields
     *
     * @param array<string>|string $on The field(s) to match on
     * @return $this
     */
    public function on(array|string $on)
    {
        $this->on = is_array($on) ? $on : [$on];

        return $this;
    }

    /**
     * Set the when matched action
     *
     * @param array<string, mixed>|string $action The action (replace, keepExisting, merge, fail, pipeline)
     * @return $this
     */
    public function whenMatched(string|array $action)
    {
        $this->whenMatched = $action;

        return $this;
    }

    /**
     * Set the when not matched action
     *
     * @param array<string, mixed>|string $action The action (insert, discard, fail)
     * @return $this
     */
    public function whenNotMatched(string|array $action)
    {
        $this->whenNotMatched = $action;

        return $this;
    }

    /**
     * Set let variables
     *
     * @param array<string, mixed> $let The variables
     * @return $this
     */
    public function let(array $let)
    {
        $this->let = $let;

        return $this;
    }

    /**
     * Get the MongoDB aggregation stage expression
     *
     * @return array<string, mixed> The $merge stage expression
     */
    public function getExpression(): array
    {
        $merge = ['into' => $this->into];

        if ($this->on !== null) {
            $merge['on'] = $this->on;
        }

        if ($this->whenMatched !== null) {
            $merge['whenMatched'] = $this->whenMatched;
        }

        if ($this->whenNotMatched !== null) {
            $merge['whenNotMatched'] = $this->whenNotMatched;
        }

        if ($this->let !== null) {
            $merge['let'] = $this->let;
        }

        return ['$merge' => $merge];
    }
}

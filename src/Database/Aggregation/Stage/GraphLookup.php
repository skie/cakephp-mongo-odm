<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Aggregation\Stage;

use Crustum\Mongo\Database\Aggregation\AggregationBuilder;

/**
 * $graphLookup aggregation stage
 *
 * Performs recursive document traversal for hierarchical data
 */
class GraphLookup extends Stage
{
    /**
     * The collection to search
     *
     * @var string
     */
    protected string $from;

    /**
     * The expression to start the search
     *
     * @var array<string, mixed>|string
     */
    protected array|string $startWith;

    /**
     * The field to connect from
     *
     * @var string
     */
    protected string $connectFromField;

    /**
     * The field to connect to
     *
     * @var string
     */
    protected string $connectToField;

    /**
     * The alias for the results
     *
     * @var string
     */
    protected string $as;

    /**
     * Maximum recursion depth
     *
     * @var int|null
     */
    protected ?int $maxDepth = null;

    /**
     * Depth field name
     *
     * @var string|null
     */
    protected ?string $depthField = null;

    /**
     * Restrictive match conditions
     *
     * @var array<string, mixed>|null
     */
    protected ?array $restrictSearchWithMatch = null;

    /**
     * Constructor
     *
     * @param \Crustum\Mongo\Database\Aggregation\AggregationBuilder $builder          The aggregation builder
     * @param string                                            $from             The collection to search
     * @param array<string, mixed>|string                        $startWith        The expression to start the search
     * @param string                                            $connectFromField The field to connect from
     * @param string                                            $connectToField   The field to connect to
     * @param string                                            $as               The alias for the results
     */
    public function __construct(
        AggregationBuilder $builder,
        string $from,
        array|string $startWith,
        string $connectFromField,
        string $connectToField,
        string $as,
    ) {
        parent::__construct($builder);
        $this->from = $from;
        $this->startWith = $startWith;
        $this->connectFromField = $connectFromField;
        $this->connectToField = $connectToField;
        $this->as = $as;
    }

    /**
     * Set the maximum recursion depth
     *
     * @param int $maxDepth The maximum depth
     * @return $this
     */
    public function maxDepth(int $maxDepth)
    {
        $this->maxDepth = $maxDepth;

        return $this;
    }

    /**
     * Set the depth field name
     *
     * @param string $depthField The depth field name
     * @return $this
     */
    public function depthField(string $depthField)
    {
        $this->depthField = $depthField;

        return $this;
    }

    /**
     * Set restrictive match conditions
     *
     * @param array<string, mixed> $match The match conditions
     * @return $this
     */
    public function restrictSearchWithMatch(array $match)
    {
        $this->restrictSearchWithMatch = $match;

        return $this;
    }

    /**
     * Get the MongoDB aggregation stage expression
     *
     * @return array<string, mixed> The $graphLookup stage expression
     */
    public function getExpression(): array
    {
        $graphLookup = [
            'from' => $this->from,
            'startWith' => $this->startWith,
            'connectFromField' => $this->connectFromField,
            'connectToField' => $this->connectToField,
            'as' => $this->as,
        ];

        if ($this->maxDepth !== null) {
            $graphLookup['maxDepth'] = $this->maxDepth;
        }

        if ($this->depthField !== null) {
            $graphLookup['depthField'] = $this->depthField;
        }

        if ($this->restrictSearchWithMatch !== null) {
            $graphLookup['restrictSearchWithMatch'] = $this->restrictSearchWithMatch;
        }

        return ['$graphLookup' => $graphLookup];
    }
}

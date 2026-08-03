<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Aggregation\Stage;

use Crustum\Mongo\Database\Aggregation\AggregationBuilder;

/**
 * $search aggregation stage
 *
 * Performs full-text search using Atlas Search
 */
class Search extends Stage
{
    /**
     * The search specification
     *
     * @var array<string, mixed>
     */
    protected array $search;

    /**
     * The index name
     *
     * @var string|null
     */
    protected ?string $index = null;

    /**
     * Constructor
     *
     * @param \Crustum\Mongo\Database\Aggregation\AggregationBuilder $builder The aggregation builder
     * @param array<string, mixed>                              $search  The search specification
     */
    public function __construct(AggregationBuilder $builder, array $search)
    {
        parent::__construct($builder);
        $this->search = $search;
    }

    /**
     * Set the index name
     *
     * @param string $index The index name
     * @return $this
     */
    public function index(string $index)
    {
        $this->index = $index;

        return $this;
    }

    /**
     * Get the MongoDB aggregation stage expression
     *
     * @return array The $search stage expression
     */
    public function getExpression(): array
    {
        $searchStage = ['search' => $this->search];

        if ($this->index !== null) {
            $searchStage['index'] = $this->index;
        }

        return ['$search' => $searchStage];
    }
}

<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Aggregation\Stage;

use Crustum\Mongo\Database\Aggregation\AggregationBuilder;

/**
 * $vectorSearch aggregation stage
 *
 * Performs a semantic search over a vector field using MongoDB 7.0+ / Atlas
 * Vector Search. The `queryVector` is typically the packed binary or float32
 * representation of the embedding.
 *
 * @see mongodb-odm Aggregation/Stage/VectorSearch.php
 */
class VectorSearch extends Stage
{
    /**
     * The query vector.
     *
     * @var object|array<float>
     */
    protected array|object $queryVector;

    /**
     * The field path to search over.
     *
     * @var string
     */
    protected string $path;

    /**
     * The number of candidates to consider.
     *
     * @var int|null
     */
    protected ?int $numCandidates = null;

    /**
     * The index name.
     *
     * @var string|null
     */
    protected ?string $index = null;

    /**
     * The additional filter.
     *
     * @var array<string, mixed>|null
     */
    protected ?array $filter = null;

    /**
     * Constructor
     *
     * @param \Crustum\Mongo\Database\Aggregation\AggregationBuilder $builder The aggregation builder
     * @param object|array<float> $queryVector The query vector
     * @param string $path The field path to search over
     * @param int|null $numCandidates The number of candidates to consider
     */
    public function __construct(AggregationBuilder $builder, array|object $queryVector, string $path, ?int $numCandidates = null)
    {
        parent::__construct($builder);
        $this->queryVector = $queryVector;
        $this->path = $path;
        $this->numCandidates = $numCandidates;
    }

    /**
     * Set the index name.
     *
     * @param string $index The index name
     * @return $this
     */
    public function index(string $index): static
    {
        $this->index = $index;

        return $this;
    }

    /**
     * Set the number of candidates to consider.
     *
     * @param int $numCandidates The number of candidates
     * @return $this
     */
    public function numCandidates(int $numCandidates): static
    {
        $this->numCandidates = $numCandidates;

        return $this;
    }

    /**
     * Set an additional filter to narrow the search.
     *
     * @param array<string, mixed> $filter The filter specification
     * @return $this
     */
    public function filter(array $filter): static
    {
        $this->filter = $filter;

        return $this;
    }

    /**
     * Get the MongoDB aggregation stage expression
     *
     * @return array<string, mixed> $vectorSearch The vectorSearch stage expression
     */
    public function getExpression(): array
    {
        $expression = [
            'queryVector' => $this->queryVector,
            'path' => $this->path,
        ];

        if ($this->numCandidates !== null) {
            $expression['numCandidates'] = $this->numCandidates;
        }

        if ($this->index !== null) {
            $expression['index'] = $this->index;
        }

        if ($this->filter !== null) {
            $expression['filter'] = $this->filter;
        }

        return ['$vectorSearch' => $expression];
    }
}

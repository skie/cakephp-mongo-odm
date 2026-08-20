<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Aggregation\Stage;

use Crustum\Mongo\Database\Aggregation\AggregationBuilder;

/**
 * $unwind aggregation stage
 *
 * Deconstructs an array field from the input documents to output one document
 * per element.
 *
 * @inspired-by \Doctrine\ODM\MongoDB\Aggregation\Stage\Unwind
 */
class Unwind extends Stage
{
    /**
     * The field path to unwind.
     *
     * @var string
     */
    protected string $path;

    /**
     * The unwind options.
     *
     * @var array<string, mixed>
     */
    protected array $options;

    /**
     * Constructor
     *
     * @param \Crustum\Mongo\Database\Aggregation\AggregationBuilder $builder The aggregation builder
     * @param string $path The field path to unwind
     * @param array<string, mixed> $options Additional options (preserveNullAndEmptyArrays, includeArrayIndex, outputPath)
     */
    public function __construct(AggregationBuilder $builder, string $path, array $options = [])
    {
        parent::__construct($builder);
        $this->path = $path;
        $this->options = $options;
    }

    /**
     * Set whether null or missing array values should not break the pipeline.
     *
     * @param bool $preserve Whether to preserve null/empty arrays
     * @return $this
     */
    public function preserveNullAndEmptyArrays(bool $preserve = true): static
    {
        $this->options['preserveNullAndEmptyArrays'] = $preserve;

        return $this;
    }

    /**
     * Set the name of the new field to hold the array index of the element.
     *
     * @param string $field The array index field name
     * @return $this
     */
    public function includeArrayIndex(string $field): static
    {
        $this->options['includeArrayIndex'] = $field;

        return $this;
    }

    /**
     * Set the name of the new field to hold the array element.
     *
     * @param string $field The output path field name
     * @return $this
     */
    public function outputPath(string $field): static
    {
        $this->options['outputPath'] = $field;

        return $this;
    }

    /**
     * Get the MongoDB aggregation stage expression
     *
     * @return array<string, mixed> $unwind The unwind stage expression
     */
    public function getExpression(): array
    {
        return ['$unwind' => ['path' => $this->path] + $this->options];
    }
}

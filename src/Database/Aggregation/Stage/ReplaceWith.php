<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Aggregation\Stage;

use Crustum\Mongo\Database\Aggregation\AggregationBuilder;

/**
 * $replaceWith aggregation stage
 *
 * Alias for $replaceRoot - replaces the input document with the specified document
 */
class ReplaceWith extends Stage
{
    /**
     * The replacement document or expression
     *
     * @var array<string, mixed>|string
     */
    protected array|string $replacement;

    /**
     * Constructor
     *
     * @param \Crustum\Mongo\Database\Aggregation\AggregationBuilder $builder     The aggregation builder
     * @param array<string, mixed>|string                      $replacement The replacement document or expression
     */
    public function __construct(AggregationBuilder $builder, array|string $replacement)
    {
        parent::__construct($builder);
        $this->replacement = $replacement;
    }

    /**
     * Get the MongoDB aggregation stage expression
     *
     * @return array<string, mixed> The $replaceWith stage expression
     */
    public function getExpression(): array
    {
        return ['$replaceWith' => $this->replacement];
    }
}

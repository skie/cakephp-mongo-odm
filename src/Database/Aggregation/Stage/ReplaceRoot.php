<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Aggregation\Stage;

use Crustum\Mongo\Database\Aggregation\AggregationBuilder;

/**
 * $replaceRoot aggregation stage
 *
 * Replaces the input document with the specified document
 *
 * @inspired-by \Doctrine\ODM\MongoDB\Aggregation\Stage\ReplaceRoot
 */
class ReplaceRoot extends Stage
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
     * @return array<string, mixed> The $replaceRoot stage expression
     */
    public function getExpression(): array
    {
        return ['$replaceRoot' => ['newRoot' => $this->replacement]];
    }
}

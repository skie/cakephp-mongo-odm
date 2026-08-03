<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Aggregation\Stage;

use Crustum\Mongo\Database\Aggregation\AggregationBuilder;

/**
 * $redact aggregation stage
 *
 * Conditionally excludes or includes fields at all document levels
 */
class Redact extends Stage
{
    /**
     * The redact expression
     *
     * @var array<string, mixed>|string
     */
    protected array|string $expression;

    /**
     * Constructor
     *
     * @param \Crustum\Mongo\Database\Aggregation\AggregationBuilder $builder    The aggregation builder
     * @param array<string, mixed>|string                        $expression The redact expression
     */
    public function __construct(AggregationBuilder $builder, array|string $expression)
    {
        parent::__construct($builder);
        $this->expression = $expression;
    }

    /**
     * Get the MongoDB aggregation stage expression
     *
     * @return array The $redact stage expression
     */
    public function getExpression(): array
    {
        return ['$redact' => $this->expression];
    }
}

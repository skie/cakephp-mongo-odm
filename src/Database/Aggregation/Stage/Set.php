<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Aggregation\Stage;

use Crustum\Mongo\Database\Aggregation\AggregationBuilder;
use Crustum\Mongo\Database\Aggregation\Expr;

/**
 * $set aggregation stage
 *
 * Alias for $addFields - adds new fields to documents
 */
class Set extends Stage
{
    /**
     * Expression builder for field expressions
     *
     * @var \Crustum\Mongo\Database\Aggregation\Expr
     */
    protected Expr $expr;

    /**
     * Constructor
     *
     * @param \Crustum\Mongo\Database\Aggregation\AggregationBuilder $builder The aggregation builder
     */
    public function __construct(AggregationBuilder $builder)
    {
        parent::__construct($builder);
        $this->expr = new Expr();
    }

    /**
     * Add a field with an expression
     *
     * @param string $fieldName  The field name to add
     * @param mixed  $expression The expression value (can be array, string, number, etc.)
     * @return $this
     */
    public function field(string $fieldName, mixed $expression)
    {
        $this->expr->addField($fieldName, $expression);

        return $this;
    }

    /**
     * Get the MongoDB aggregation stage expression
     *
     * @return array The $set stage expression
     */
    public function getExpression(): array
    {
        return ['$set' => $this->expr->getExpression()];
    }
}

<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Aggregation\Stage;

use Crustum\Mongo\Database\Aggregation\AggregationBuilder;
use Crustum\Mongo\Database\Expression\MongoExpressionInterface;

/**
 * $set aggregation stage
 *
 * Alias for $addFields - adds new fields to documents
 *
 * @inspired-by \Doctrine\ODM\MongoDB\Aggregation\Stage\Set
 */
class Set extends Stage
{
    /**
     * The fields to set, keyed by field name
     *
     * @var array<string, mixed>
     */
    protected array $fields = [];

    /**
     * Constructor
     *
     * @param \Crustum\Mongo\Database\Aggregation\AggregationBuilder $builder The aggregation builder
     */
    public function __construct(AggregationBuilder $builder)
    {
        parent::__construct($builder);
    }

    /**
     * Add a field with an expression
     *
     * @param string $fieldName  The field name to add
     * @param mixed  $expression The expression value (can be a `FunctionExpression`,
     *  array, string, number, etc.)
     * @return $this
     */
    public function field(string $fieldName, mixed $expression): static
    {
        $this->fields[$fieldName] = $expression;

        return $this;
    }

    /**
     * Get the MongoDB aggregation stage expression
     *
     * @return array<string, mixed> The $set stage expression
     */
    public function getExpression(): array
    {
        return ['$set' => $this->renderFields()];
    }

    /**
     * Render the field expressions, resolving expression objects to arrays
     *
     * @return array<string, mixed>
     */
    protected function renderFields(): array
    {
        $result = [];
        foreach ($this->fields as $fieldName => $expression) {
            if ($expression instanceof MongoExpressionInterface) {
                $result[$fieldName] = $expression->getConditions();
            } else {
                $result[$fieldName] = $expression;
            }
        }

        return $result;
    }
}

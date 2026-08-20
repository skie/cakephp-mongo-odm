<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Aggregation\Stage;

use Crustum\Mongo\Database\Aggregation\AggregationBuilder;
use Crustum\Mongo\Database\Expression\MongoExpressionInterface;

/**
 * $addFields aggregation stage
 *
 * Adds new fields to documents without removing existing fields
 *
 * @rewritten-from \Doctrine\ODM\MongoDB\Aggregation\Stage\AddFields
 */
class AddFields extends Stage
{
    /**
     * The fields to add, keyed by field name
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
     * Sets a field when a path is present; otherwise null.
     *
     * After a LEFT `$unwind`, optional associations are missing or null on the
     * document. A root `$project` would drop parent columns, so matching uses
     * `$addFields` with this helper to replace only the association property.
     *
     * @param string $fieldName The output field name.
     * @param string $path The field path to test (`$author`, `$articles`, …).
     * @param mixed $value The expression when the path is present.
     * @return $this
     */
    public function fieldWhenPresent(string $fieldName, string $path, mixed $value): static
    {
        return $this->field(
            $fieldName,
            $this->getBuilder()->func()->condWhenPresent($path, $value),
        );
    }

    /**
     * Get the MongoDB aggregation stage expression
     *
     * @return array<string, mixed> The $addFields stage expression
     */
    public function getExpression(): array
    {
        return ['$addFields' => $this->renderFields()];
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

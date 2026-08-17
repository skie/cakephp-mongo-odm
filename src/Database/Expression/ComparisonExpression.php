<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Expression;

use Cake\Database\ExpressionInterface;
use Cake\Database\ValueBinder;
use Closure;

class ComparisonExpression extends AbstractExpression
{
    /**
     * Mapping of SQL-style operators to MongoDB operators
     *
     * @var array<string, string>
     */
    protected const array OPERATORS = [
        '=' => '$eq',
        '!=' => '$ne',
        '>' => '$gt',
        '>=' => '$gte',
        '<' => '$lt',
        '<=' => '$lte',
    ];

    /**
     * The field name, or an identifier reference for field-to-field comparisons.
     *
     * @var \Cake\Database\ExpressionInterface|\Crustum\Mongo\Database\Expression\IdentifierExpression|string
     */
    protected ExpressionInterface|string|IdentifierExpression $field;

    /**
     * The value to compare
     *
     * @var mixed
     */
    protected mixed $value;

    /**
     * The Mongo operator
     *
     * @var string
     */
    protected string $operator;

    /**
     * Constructor
     *
     * @param \Cake\Database\ExpressionInterface|\Crustum\Mongo\Database\Expression\IdentifierExpression|string $field Field name or identifier reference.
     * @param mixed $value Value to compare.
     * @param string $operator Comparison operator.
     */
    public function __construct(ExpressionInterface|string|IdentifierExpression $field, mixed $value, string $operator)
    {
        $this->field = $field;
        $this->value = $value;
        $this->operator = self::OPERATORS[$operator] ?? $operator;
    }

    /**
     * Gets the compared field.
     *
     * @return \Cake\Database\ExpressionInterface|\Crustum\Mongo\Database\Expression\IdentifierExpression|string
     */
    public function getField(): ExpressionInterface|string|IdentifierExpression
    {
        return $this->field;
    }

    /**
     * Sets the compared field.
     *
     * @param \Cake\Database\ExpressionInterface|\Crustum\Mongo\Database\Expression\IdentifierExpression|string $field The field name or identifier reference.
     * @return $this
     */
    public function setField(ExpressionInterface|string|IdentifierExpression $field): static
    {
        $this->field = $field;

        return $this;
    }

    /**
     * Traverse the expression tree
     *
     * @param \Closure $callback Callback function
     * @return $this
     */
    public function traverse(Closure $callback): static
    {
        $callback($this);

        if ($this->value instanceof MongoExpressionInterface) {
            $this->value->traverse($callback);
        }

        return $this;
    }

    /**
     * Compile the expression to MongoDB query format
     *
     * A comparison between two identifier references (field-to-field) compiles
     * to an `$expr` document (`{$expr: {$op: ['$left', '$right']}}`); a
     * field-to-value comparison compiles to the regular `{field: value}` form.
     *
     * @return array<string, mixed>
     */
    protected function compile(): array
    {
        $value = $this->value;
        if ($value instanceof MongoExpressionInterface && !$value instanceof IdentifierExpression) {
            $value = $value->getConditions();
        }

        $field = $this->field;
        if ($field instanceof IdentifierExpression) {
            $fieldPath = $this->exprPath($field->getIdentifier());

            return [
                '$expr' => [$this->operator => [$fieldPath, $this->exprValue($value)]],
            ];
        }

        if ($field instanceof ExpressionInterface) {
            $field = $field->sql(new ValueBinder());
        }

        if ($value instanceof IdentifierExpression) {
            $valuePath = $this->exprPath($value->getIdentifier());

            return [
                '$expr' => [$this->operator => ['$' . $field, $valuePath]],
            ];
        }

        if ($this->operator === '$eq') {
            return [$field => $value];
        }

        return [
            $field => [$this->operator => $value],
        ];
    }

    /**
     * Normalizes an identifier-wrapped operand to its `$field` path.
     *
     * @param mixed $value The operand.
     * @return mixed The `$field` path or the raw value.
     */
    protected function exprValue(mixed $value): mixed
    {
        return $value instanceof IdentifierExpression
            ? $this->exprPath($value->getIdentifier())
            : $value;
    }

    /**
     * Prefixes a field name with `$` unless it already carries one.
     *
     * Let variables (`$$name`) keep their double prefix.
     *
     * @param string $field The field name.
     * @return string The `$field` path.
     */
    protected function exprPath(string $field): string
    {
        return str_starts_with($field, '$') ? $field : '$' . $field;
    }

    /**
     * Get the compiled conditions
     *
     * @return array<string, mixed>
     */
    public function getConditions(): array
    {
        return $this->compile();
    }
}

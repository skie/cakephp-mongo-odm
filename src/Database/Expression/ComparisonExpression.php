<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Expression;

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
     * @var string|\Crustum\Mongo\Database\Expression\IdentifierExpression
     */
    protected string|IdentifierExpression $field;

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
     * @param string|\Crustum\Mongo\Database\Expression\IdentifierExpression $field Field name or identifier reference.
     * @param mixed $value Value to compare.
     * @param string $operator Comparison operator.
     */
    public function __construct(string|IdentifierExpression $field, mixed $value, string $operator)
    {
        $this->field = $field;
        $this->value = $value;
        $this->operator = self::OPERATORS[$operator] ?? $operator;
    }

    /**
     * Gets the compared field.
     *
     * @return string|\Crustum\Mongo\Database\Expression\IdentifierExpression
     */
    public function getField(): string|IdentifierExpression
    {
        return $this->field;
    }

    /**
     * Sets the compared field.
     *
     * @param string|\Crustum\Mongo\Database\Expression\IdentifierExpression $field The field name or identifier reference.
     * @return $this
     */
    public function setField(string|IdentifierExpression $field): static
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

        if ($this->field instanceof IdentifierExpression) {
            $fieldPath = $this->exprPath($this->field->getIdentifier());

            return [
                '$expr' => [$this->operator => [$fieldPath, $this->exprValue($value)]],
            ];
        }

        if ($value instanceof IdentifierExpression) {
            $valuePath = $this->exprPath($value->getIdentifier());

            return [
                '$expr' => [$this->operator => ['$' . (string)$this->field, $valuePath]],
            ];
        }

        if ($this->operator === '$eq') {
            return [$this->field => $value];
        }

        return [
            $this->field => [$this->operator => $value],
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

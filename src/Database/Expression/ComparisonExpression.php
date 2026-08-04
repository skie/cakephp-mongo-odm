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
     * The field name
     *
     * @var string
     */
    protected string $field;

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
     * @param string $field Field name
     * @param mixed $value Value to compare
     * @param string $operator Comparison operator
     */
    public function __construct(string $field, mixed $value, string $operator)
    {
        $this->field = $field;
        $this->value = $value;
        $this->operator = self::OPERATORS[$operator] ?? $operator;
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
     * @return array<string, mixed>
     */
    protected function compile(): array
    {
        $value = $this->value;
        if ($value instanceof MongoExpressionInterface) {
            $value = $value->getConditions();
        }

        if ($this->operator === '$eq') {
            return [$this->field => $value];
        }

        return [
            $this->field => [$this->operator => $value],
        ];
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

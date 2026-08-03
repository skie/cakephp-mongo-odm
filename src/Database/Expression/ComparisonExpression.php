<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Expression;

use Closure;

class ComparisonExpression extends AbstractExpression
{
    protected string $_field;

    protected mixed $_value;

    protected string $_operator;

    /**
     * Constructor
     *
     * @param string $field Field name
     * @param mixed $value Value to compare
     * @param string $operator Comparison operator
     */
    public function __construct(string $field, mixed $value, string $operator)
    {
        $this->_field = $field;
        $this->_value = $value;
        $this->_operator = $operator;

        $mongoOperators = [
            '!=' => '$ne',
            '>' => '$gt',
            '>=' => '$gte',
            '<' => '$lt',
            '<=' => '$lte',
        ];

        $operator = $mongoOperators[$operator] ?? $operator;

        if ($operator === '$eq') {
            $this->_conditions = [
                $field => $value,
            ];

            return;
        }

        $this->_conditions = [
            $field => [$operator => $value],
        ];
    }

    /**
     * Traverse the expression tree
     *
     * @param \Closure $callback Callback function
     * @return $this
     */
    public function traverse(Closure $callback)
    {
        $callback($this);

        if ($this->_value instanceof MongoExpressionInterface) {
            $this->_value->traverse($callback);
        }

        return $this;
    }

    /**
     * Compile the expression to MongoDB query format
     *
     * @return array
     */
    protected function _compile(): array
    {
        $value = $this->_value;
        if ($value instanceof MongoExpressionInterface) {
            $value = $value->getConditions();
        }

        if ($this->_operator === '$eq') {
            return [$this->_field => $value];
        }

        return [
            $this->_field => [$this->_operator => $value],
        ];
    }

    /**
     * Get the compiled conditions
     *
     * @return array
     */
    public function getConditions(): array
    {
        return $this->_compile();
    }
}

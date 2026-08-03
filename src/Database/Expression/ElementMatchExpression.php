<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Expression;

use Closure;

class ElementMatchExpression extends AbstractExpression
{
    protected string $_field;

    /**
     * Constructor
     *
     * @param string $field Field name
     * @param array $conditions Conditions array
     */
    public function __construct(string $field, array $conditions)
    {
        $this->_field = $field;
        $this->_conditions = $conditions;
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

        foreach ($this->_conditions as $condition) {
            if ($condition instanceof MongoExpressionInterface) {
                $condition->traverse($callback);
            } else {
                $callback($condition);
            }
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
        $result = [];
        $conditions = $this->_conditions;

        if (isset($conditions[0]) && is_array($conditions[0])) {
            $flattened = [];
            foreach ($conditions as $condition) {
                foreach ($condition as $field => $value) {
                    $flattened[$field] = $value;
                }
            }

            $conditions = $flattened;
        }

        foreach ($conditions as $field => $condition) {
            if ($condition instanceof MongoExpressionInterface) {
                $result[$field] = $condition->getConditions();
            } else {
                $result[$field] = $condition;
            }
        }

        return [
            $this->_field => [
                '$elemMatch' => $result,
            ],
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

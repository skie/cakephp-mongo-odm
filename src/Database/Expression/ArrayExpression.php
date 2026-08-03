<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Expression;

class ArrayExpression extends AbstractExpression
{
    protected string $_field;

    protected string $_operator;

    /**
     * Constructor
     *
     * @param string $field Field name
     * @param array $conditions Conditions array
     * @param string $operator Operator
     */
    public function __construct(string $field, array $conditions, string $operator)
    {
        $this->_field = $field;
        $this->_operator = $operator;
        $this->_conditions = $conditions;
    }

    /**
     * Compile the expression to MongoDB query format
     *
     * @return array
     */
    protected function _compile(): array
    {
        $result = [];
        foreach ($this->_conditions as $condition) {
            if ($condition instanceof MongoExpressionInterface) {
                $result[] = $condition->getConditions();
            } else {
                $result[] = $condition;
            }
        }

        return [
            $this->_field => [$this->_operator => $result],
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

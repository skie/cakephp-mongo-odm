<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Expression;

use Closure;

class InExpression extends AbstractExpression
{
    protected string $_field;

    protected array $_values;

    /**
     * Constructor
     *
     * @param string $field Field name
     * @param array $values Array of values
     */
    public function __construct(string $field, array $values)
    {
        $this->_field = $field;
        $this->_values = $values;
        $this->_compile();
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

        foreach ($this->_values as $value) {
            if ($value instanceof MongoExpressionInterface) {
                $value->traverse($callback);
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
        $values = array_map(
            function ($value) {
                if ($value instanceof MongoExpressionInterface) {
                    return $value->getConditions();
                }

                return $value;
            },
            $this->_values,
        );

        $this->_conditions = [
            $this->_field => [
                '$in' => $values,
            ],
        ];

        return $this->_conditions;
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

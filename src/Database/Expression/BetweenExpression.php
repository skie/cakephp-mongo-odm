<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Expression;

use Closure;

class BetweenExpression extends AbstractExpression
{
    protected string $_field;

    protected mixed $_from;

    protected mixed $_to;

    /**
     * Constructor
     *
     * @param string $field Field name
     * @param mixed $from Lower bound
     * @param mixed $to Upper bound
     */
    public function __construct(string $field, mixed $from, mixed $to)
    {
        $this->_field = $field;
        $this->_from = $from;
        $this->_to = $to;
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

        foreach ([$this->_from, $this->_to] as $value) {
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
        $from = $this->_from instanceof MongoExpressionInterface ?
            $this->_from->getConditions() : $this->_from;
        $to = $this->_to instanceof MongoExpressionInterface ?
            $this->_to->getConditions() : $this->_to;

        $this->_conditions = [
            $this->_field => [
                '$gte' => $from,
                '$lte' => $to,
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

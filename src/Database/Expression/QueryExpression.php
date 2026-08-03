<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Expression;

use Closure;

class QueryExpression extends AbstractExpression
{
    protected string $conjunction = '$and';

    /**
     * Constructor
     *
     * @param array $conditions Initial conditions
     * @param string $conjunction Conjunction operator ('$and' or '$or')
     */
    public function __construct(array $conditions = [], string $conjunction = '$and')
    {
        $this->conjunction = $conjunction;
        $this->_conditions = [
            $this->conjunction => [],
        ];

        if ($conditions !== []) {
            $this->add($conditions);
        }
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

        foreach ($this->_conditions[$this->conjunction] as $condition) {
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
        foreach ($this->_conditions[$this->conjunction] as $condition) {
            if ($condition instanceof MongoExpressionInterface) {
                $result[] = $condition->getConditions();
            } else {
                $result[] = $condition;
            }
        }

        return $result;
    }

    /**
     * Get the compiled conditions
     *
     * @return array
     */
    public function getConditions(): array
    {
        $result = $this->_compile();

        if ($result === []) {
            return [];
        }

        if ($this->conjunction === '$or') {
            return ['$or' => $result];
        }

        if (count($result) === 1) {
            return reset($result);
        }

        return array_merge(...$result);
    }

    /**
     * Add conditions to the expression
     *
     * @param \Crustum\Mongo\Database\Expression\MongoExpressionInterface|array $conditions Conditions to add
     * @param string $conjunction Conjunction operator
     * @return $this
     */
    public function add(array|MongoExpressionInterface $conditions, string $conjunction = '$and')
    {
        if ($conditions instanceof MongoExpressionInterface) {
            $this->_conditions[$this->conjunction][] = $conditions;

            return $this;
        }

        if (array_is_list($conditions)) {
            foreach ($conditions as $condition) {
                $this->add($condition);
            }

            return $this;
        }

        $this->_conditions[$this->conjunction][] = $conditions;

        return $this;
    }

    /**
     * Create an OR expression
     *
     * @param \Crustum\Mongo\Database\Expression\MongoExpressionInterface|array $conditions Conditions
     * @return self
     */
    public function or(array|MongoExpressionInterface $conditions): self
    {
        return new self($conditions, '$or');
    }

    /**
     * Add conditions with AND conjunction
     *
     * @param \Crustum\Mongo\Database\Expression\MongoExpressionInterface|array $conditions Conditions
     * @return $this
     */
    public function and(array|MongoExpressionInterface $conditions): self
    {
        return $this->add($conditions);
    }
}

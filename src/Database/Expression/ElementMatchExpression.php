<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Expression;

use Closure;

class ElementMatchExpression extends AbstractExpression
{
    /**
     * The field name
     *
     * @var string
     */
    protected string $field;

    /**
     * Constructor
     *
     * @param string $field Field name
     * @param array<string, mixed> $conditions Conditions array
     */
    public function __construct(string $field, array $conditions)
    {
        $this->field = $field;
        $this->conditions = $conditions;
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

        foreach ($this->conditions as $condition) {
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
     * @return array<string, mixed>
     */
    protected function compile(): array
    {
        $result = [];
        $conditions = $this->conditions;

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
            $this->field => [
                '$elemMatch' => $result,
            ],
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

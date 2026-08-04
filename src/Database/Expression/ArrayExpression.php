<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Expression;

class ArrayExpression extends AbstractExpression
{
    /**
     * The field name
     *
     * @var string
     */
    protected string $field;

    /**
     * The Mongo array operator (`$all`, `$in`, …)
     *
     * @var string
     */
    protected string $operator;

    /**
     * Constructor
     *
     * @param string $field Field name
     * @param array<string, mixed> $conditions Conditions array
     * @param string $operator Operator
     */
    public function __construct(string $field, array $conditions, string $operator)
    {
        $this->field = $field;
        $this->operator = $operator;
        $this->conditions = $conditions;
    }

    /**
     * Compile the expression to MongoDB query format
     *
     * @return array<string, mixed>
     */
    protected function compile(): array
    {
        $result = [];
        foreach ($this->conditions as $condition) {
            if ($condition instanceof MongoExpressionInterface) {
                $result[] = $condition->getConditions();
            } else {
                $result[] = $condition;
            }
        }

        return [
            $this->field => [$this->operator => $result],
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

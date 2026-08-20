<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Expression;

use Closure;
use Override;

class InExpression extends AbstractExpression
{
    /**
     * The field name
     *
     * @var string
     */
    protected string $field;

    /**
     * The values to compare against
     *
     * @var array<int, mixed>
     */
    protected array $values;

    /**
     * The Mongo operator (`$in` or `$nin`)
     *
     * @var string
     */
    protected string $operator;

    /**
     * Constructor
     *
     * @param string $field Field name
     * @param array<int, mixed> $values Array of values
     * @param string $operator The Mongo operator (`$in` or `$nin`)
     */
    public function __construct(string $field, array $values, string $operator = '$in')
    {
        $this->field = $field;
        $this->values = $values;
        $this->operator = $operator;
    }

    /**
     * Traverse the expression tree
     *
     * @param \Closure $callback Callback function
     * @return $this
     */
    #[Override]
    public function traverse(Closure $callback): static
    {
        $callback($this);

        foreach ($this->values as $value) {
            if ($value instanceof MongoExpressionInterface) {
                $value->traverse($callback);
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
        $values = array_map(
            function (mixed $value): mixed {
                if ($value instanceof MongoExpressionInterface) {
                    return $value->getConditions();
                }

                return $value;
            },
            $this->values,
        );

        $this->conditions = [
            $this->field => [
                $this->operator => $values,
            ],
        ];

        return $this->conditions;
    }

    /**
     * Get the compiled conditions
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function getConditions(): array
    {
        return $this->compile();
    }
}

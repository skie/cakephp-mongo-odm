<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Expression;

use Closure;
use Override;

class BetweenExpression extends AbstractExpression
{
    /**
     * The field name
     *
     * @var string
     */
    protected string $field;

    /**
     * The lower bound
     *
     * @var mixed
     */
    protected mixed $from;

    /**
     * The upper bound
     *
     * @var mixed
     */
    protected mixed $to;

    /**
     * Whether the range is negated
     *
     * @var bool
     */
    protected bool $not;

    /**
     * Constructor
     *
     * @param string $field Field name
     * @param mixed $from Lower bound
     * @param mixed $to Upper bound
     * @param bool $not Whether to negate the range
     */
    public function __construct(string $field, mixed $from, mixed $to, bool $not = false)
    {
        $this->field = $field;
        $this->from = $from;
        $this->to = $to;
        $this->not = $not;
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

        foreach ([$this->from, $this->to] as $value) {
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
        $from = $this->from instanceof MongoExpressionInterface ?
            $this->from->getConditions() : $this->from;
        $to = $this->to instanceof MongoExpressionInterface ?
            $this->to->getConditions() : $this->to;

        $range = [
            '$gte' => $from,
            '$lte' => $to,
        ];

        $this->conditions = [
            $this->field => $this->not ? ['$not' => $range] : $range,
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

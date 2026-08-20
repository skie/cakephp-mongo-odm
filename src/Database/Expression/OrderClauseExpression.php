<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Expression;

use Cake\Database\ExpressionInterface;
use Cake\Database\ValueBinder;
use Closure;

/**
 * A single `$sort` field/direction pair for MongoDB.
 *
 * Mirrors `Cake\Database\Expression\OrderClauseExpression` (a field + sort
 * direction), but compiles to the Mongo `$sort` shape `{field: 1|-1}`.
 *
 * @ported-from \Cake\Database\Expression\OrderClauseExpression
 */
class OrderClauseExpression implements MongoExpressionInterface
{
    /**
     * The field to order on.
     *
     * @var \Cake\Database\ExpressionInterface|string
     */
    protected ExpressionInterface|string $field;

    /**
     * The direction of sorting (`1` for asc, `-1` for desc).
     *
     * @var int
     */
    protected int $direction;

    /**
     * Constructor
     *
     * @param \Cake\Database\ExpressionInterface|string $field The field to order on.
     * @param string|int $direction The direction to sort on (`asc`/`desc` or `1`/`-1`).
     */
    public function __construct(ExpressionInterface|string $field, string|int $direction)
    {
        $this->field = $field;
        $this->direction = is_int($direction) ? $direction : (strtolower($direction) === 'asc' ? 1 : -1);
    }

    /**
     * @inheritDoc
     */
    public function sql(ValueBinder $binder): string
    {
        return json_encode($this->getConditions()) ?: '{}';
    }

    /**
     * @inheritDoc
     */
    public function getConditions(): array
    {
        if ($this->field instanceof MongoExpressionInterface) {
            $field = (string)json_encode($this->field->getConditions());
        } elseif ($this->field instanceof ExpressionInterface) {
            $field = $this->field->sql(new ValueBinder());
        } else {
            $field = $this->field;
        }

        return [$field => $this->direction];
    }

    /**
     * @inheritDoc
     */
    public function traverse(Closure $callback): static
    {
        $callback($this);
        if ($this->field instanceof ExpressionInterface) {
            $this->field->traverse($callback);
        }

        return $this;
    }

    /**
     * Deep-clones the field expression.
     */
    public function __clone()
    {
        if ($this->field instanceof ExpressionInterface) {
            $this->field = clone $this->field;
        }
    }
}

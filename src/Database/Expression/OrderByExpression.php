<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Expression;

use Cake\Database\ExpressionInterface;
use Cake\Database\ValueBinder;
use Closure;

/**
 * An ORDER BY clause for MongoDB.
 *
 * Mirrors `Cake\Database\Expression\OrderByExpression` (a list of
 * `OrderClauseExpression`), but compiles to the Mongo `$sort` object
 * `{field: 1|-1, ...}`.
 *
 * @inspired-by \Cake\Database\Expression\OrderByExpression
 */
class OrderByExpression implements MongoExpressionInterface
{
    /**
     * The order clauses.
     *
     * @var list<\Crustum\Mongo\Database\Expression\OrderClauseExpression>
     */
    protected array $clauses = [];

    /**
     * Constructor
     *
     * @param \Cake\Database\ExpressionInterface|array<int|string, mixed>|string $conditions The sort columns.
     */
    public function __construct(ExpressionInterface|array|string $conditions = [])
    {
        if ($conditions !== []) {
            $this->add($conditions);
        }
    }

    /**
     * Adds an order clause or list of order columns.
     *
     * @param \Cake\Database\ExpressionInterface|array<int|string, mixed>|string $conditions The sort columns.
     * @return $this
     */
    public function add(ExpressionInterface|array|string $conditions): static
    {
        if ($conditions instanceof OrderClauseExpression) {
            $this->clauses[] = $conditions;

            return $this;
        }

        if ($conditions instanceof ExpressionInterface) {
            $this->clauses[] = new OrderClauseExpression($conditions, 'ASC');

            return $this;
        }

        if (is_string($conditions)) {
            $parts = explode(' ', trim($conditions), 2);
            $field = $parts[0];
            $direction = isset($parts[1]) ? strtolower($parts[1]) : 'asc';
            $this->clauses[] = new OrderClauseExpression($field, $direction);

            return $this;
        }

        foreach ($conditions as $key => $value) {
            if (is_int($key)) {
                $this->clauses[] = new OrderClauseExpression($value, 'ASC');
                continue;
            }

            if ($value instanceof OrderClauseExpression) {
                $this->clauses[] = $value;
                continue;
            }

            $this->clauses[] = new OrderClauseExpression($key, $value);
        }

        return $this;
    }

    /**
     * Returns the sort clauses.
     *
     * @return list<\Crustum\Mongo\Database\Expression\OrderClauseExpression>
     */
    public function getClauses(): array
    {
        return $this->clauses;
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
        $conditions = [];
        foreach ($this->clauses as $clause) {
            $conditions = array_merge($conditions, $clause->getConditions());
        }

        return $conditions;
    }

    /**
     * @inheritDoc
     */
    public function traverse(Closure $callback): static
    {
        $callback($this);
        foreach ($this->clauses as $clause) {
            $clause->traverse($callback);
        }

        return $this;
    }
}

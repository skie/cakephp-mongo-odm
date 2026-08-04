<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Expression;

use Closure;
use Countable;

/**
 * Represents a MongoDB query expression. Internally it stores a tree of
 * conditions that compile to a MongoDB query document. Conditions are joined
 * with a conjunction (`$and` or `$or`).
 *
 * The public surface mirrors `Cake\Database\Expression\QueryExpression` so
 * callers get the same fluent API (`eq`, `gt`, `in`, `between`, …) while each
 * method builds a Mongo expression object.
 */
class QueryExpression extends AbstractExpression implements Countable
{
    /**
     * The conjunction used to join conditions (`$and` or `$or`)
     *
     * @var string
     */
    protected string $conjunction = '$and';

    /**
     * Constructor
     *
     * @param \Crustum\Mongo\Database\Expression\MongoExpressionInterface|array $conditions Initial conditions
     * @param string $conjunction Conjunction operator ('$and' or '$or')
     */
    public function __construct(array|MongoExpressionInterface $conditions = [], string $conjunction = '$and')
    {
        $this->conjunction = $conjunction;
        $this->conditions = [
            $this->conjunction => [],
        ];

        if ($conditions !== []) {
            $this->add($conditions);
        }
    }

    /**
     * Changes the conjunction for the conditions at this level of the expression tree.
     *
     * @param string $conjunction Value to be used for joining conditions
     * @return $this
     */
    public function setConjunction(string $conjunction): static
    {
        $this->conjunction = $conjunction;

        return $this;
    }

    /**
     * Gets the currently configured conjunction for the conditions at this level of the expression tree.
     *
     * @return string
     */
    public function getConjunction(): string
    {
        return $this->conjunction;
    }

    /**
     * Add conditions to the expression
     *
     * @param \Crustum\Mongo\Database\Expression\MongoExpressionInterface|array $conditions Conditions to add
     * @return $this
     */
    public function add(array|MongoExpressionInterface $conditions): static
    {
        if ($conditions instanceof MongoExpressionInterface) {
            $this->conditions[$this->conjunction][] = $conditions;

            return $this;
        }

        if (array_is_list($conditions)) {
            foreach ($conditions as $condition) {
                $this->add($condition);
            }

            return $this;
        }

        $this->conditions[$this->conjunction][] = $conditions;

        return $this;
    }

    /**
     * Adds a new condition in the form "field = value".
     *
     * @param string $field Field to compare
     * @param mixed $value The value to compare against
     * @return $this
     */
    public function eq(string $field, mixed $value): static
    {
        return $this->add(new ComparisonExpression($field, $value, '$eq'));
    }

    /**
     * Adds a new condition in the form "field != value".
     *
     * @param string $field Field to compare
     * @param mixed $value The value to compare against
     * @return $this
     */
    public function notEq(string $field, mixed $value): static
    {
        return $this->add(new ComparisonExpression($field, $value, '$ne'));
    }

    /**
     * Adds a new condition in the form "field > value".
     *
     * @param string $field Field to compare
     * @param mixed $value The value to compare against
     * @return $this
     */
    public function gt(string $field, mixed $value): static
    {
        return $this->add(new ComparisonExpression($field, $value, '$gt'));
    }

    /**
     * Adds a new condition in the form "field >= value".
     *
     * @param string $field Field to compare
     * @param mixed $value The value to compare against
     * @return $this
     */
    public function gte(string $field, mixed $value): static
    {
        return $this->add(new ComparisonExpression($field, $value, '$gte'));
    }

    /**
     * Adds a new condition in the form "field < value".
     *
     * @param string $field Field to compare
     * @param mixed $value The value to compare against
     * @return $this
     */
    public function lt(string $field, mixed $value): static
    {
        return $this->add(new ComparisonExpression($field, $value, '$lt'));
    }

    /**
     * Adds a new condition in the form "field <= value".
     *
     * @param string $field Field to compare
     * @param mixed $value The value to compare against
     * @return $this
     */
    public function lte(string $field, mixed $value): static
    {
        return $this->add(new ComparisonExpression($field, $value, '$lte'));
    }

    /**
     * Adds a new condition in the form "field IS NULL".
     *
     * @param string $field Field to test for null
     * @return $this
     */
    public function isNull(string $field): static
    {
        return $this->add(new ComparisonExpression($field, null, '$eq'));
    }

    /**
     * Adds a new condition in the form "field IS NOT NULL".
     *
     * @param string $field Field to test for not null
     * @return $this
     */
    public function isNotNull(string $field): static
    {
        return $this->add(new ComparisonExpression($field, null, '$ne'));
    }

    /**
     * Adds a new condition in the form "field LIKE value".
     *
     * @param string $field Field to compare
     * @param string $pattern The regex pattern
     * @param string $options Regex options
     * @return $this
     */
    public function like(string $field, string $pattern, string $options = ''): static
    {
        return $this->add(new RegexExpression($field, $pattern, $options));
    }

    /**
     * Adds a new condition in the form "field NOT LIKE value".
     *
     * @param string $field Field to compare
     * @param string $pattern The regex pattern
     * @param string $options Regex options
     * @return $this
     */
    public function notLike(string $field, string $pattern, string $options = ''): static
    {
        $regex = new RegexExpression($field, $pattern, $options);
        $compiled = $regex->getConditions();

        return $this->add([$field => ['$not' => $compiled[$field]]]);
    }

    /**
     * Adds a new condition in the form "field IN (value1, value2)".
     *
     * @param string $field Field to compare
     * @param array<int, mixed> $values The values to compare against
     * @return $this
     */
    public function in(string $field, array $values): static
    {
        return $this->add(new InExpression($field, $values, '$in'));
    }

    /**
     * Adds a new condition in the form "field NOT IN (value1, value2)".
     *
     * @param string $field Field to compare
     * @param array<int, mixed> $values The values to compare against
     * @return $this
     */
    public function notIn(string $field, array $values): static
    {
        return $this->add(new InExpression($field, $values, '$nin'));
    }

    /**
     * Adds a new condition in the form "field BETWEEN from AND to".
     *
     * @param string $field Field to compare
     * @param mixed $from The initial value of the range
     * @param mixed $to The ending value of the range
     * @return $this
     */
    public function between(string $field, mixed $from, mixed $to): static
    {
        return $this->add(new BetweenExpression($field, $from, $to));
    }

    /**
     * Adds a new condition in the form "field NOT BETWEEN from AND to".
     *
     * @param string $field Field to compare
     * @param mixed $from The initial value of the range
     * @param mixed $to The ending value of the range
     * @return $this
     */
    public function notBetween(string $field, mixed $from, mixed $to): static
    {
        return $this->add(new BetweenExpression($field, $from, $to, true));
    }

    /**
     * Adds a new condition in the form "field EXISTS".
     *
     * @param string $field Field to test for existence
     * @return $this
     */
    public function exists(string $field): static
    {
        return $this->add(new ExistsExpression($field, true));
    }

    /**
     * Adds a new condition in the form "field NOT EXISTS".
     *
     * @param string $field Field to test for existence
     * @return $this
     */
    public function notExists(string $field): static
    {
        return $this->add(new ExistsExpression($field, false));
    }

    /**
     * Returns a new QueryExpression object containing all the conditions passed
     * and set up the conjunction to be "$and"
     *
     * @param \Crustum\Mongo\Database\Expression\MongoExpressionInterface|array $conditions Conditions to be joined with AND
     * @return static
     */
    public function and(array|MongoExpressionInterface $conditions): static
    {
        return new static($conditions, '$and');
    }

    /**
     * Returns a new QueryExpression object containing all the conditions passed
     * and set up the conjunction to be "$or"
     *
     * @param \Crustum\Mongo\Database\Expression\MongoExpressionInterface|array $conditions Conditions to be joined with OR
     * @return static
     */
    public function or(array|MongoExpressionInterface $conditions): static
    {
        return new static($conditions, '$or');
    }

    /**
     * Adds a new set of conditions to this level of the tree and negates the
     * final result by wrapping them in `$nor`.
     *
     * @param \Crustum\Mongo\Database\Expression\MongoExpressionInterface|array<string, mixed> $conditions Conditions to be added and negated
     * @return $this
     */
    public function not(array|MongoExpressionInterface $conditions): static
    {
        if ($conditions instanceof MongoExpressionInterface) {
            $conditions = $conditions->getConditions();
        }

        return $this->add(['$nor' => [$conditions]]);
    }

    /**
     * Returns the number of internal conditions that are stored in this expression.
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->conditions[$this->conjunction]);
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

        foreach ($this->conditions[$this->conjunction] as $condition) {
            if ($condition instanceof MongoExpressionInterface) {
                $condition->traverse($callback);
            } else {
                $callback($condition);
            }
        }

        return $this;
    }

    /**
     * Executes a callback for each of the parts that form this expression.
     *
     * The callback is required to return a value with which the currently
     * visited part will be replaced. If the callback returns null then
     * the part will be discarded completely from this expression.
     *
     * @param \Closure $callback The callback to run for each part
     * @return $this
     */
    public function iterateParts(Closure $callback): static
    {
        $parts = [];
        foreach ($this->conditions[$this->conjunction] as $k => $c) {
            $key = &$k;
            $part = $callback($c, $key);
            if ($part !== null) {
                $parts[$key] = $part;
            }
        }

        $this->conditions[$this->conjunction] = $parts;

        return $this;
    }

    /**
     * Returns true if this expression contains any other nested
     * MongoExpressionInterface objects
     *
     * @return bool
     */
    public function hasNestedExpression(): bool
    {
        foreach ($this->conditions[$this->conjunction] as $c) {
            if ($c instanceof MongoExpressionInterface) {
                return true;
            }
        }

        return false;
    }

    /**
     * Compile the conditions to MongoDB query format
     *
     * @return array<int, array<string, mixed>>
     */
    protected function compile(): array
    {
        $result = [];
        foreach ($this->conditions[$this->conjunction] as $condition) {
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
     * @return array<string, mixed>
     */
    public function getConditions(): array
    {
        $result = $this->compile();

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
     * Clone this object and its subtree of expressions.
     */
    public function __clone()
    {
        foreach ($this->conditions as $conjunction => $conditions) {
            foreach ($conditions as $i => $condition) {
                if ($condition instanceof MongoExpressionInterface) {
                    $this->conditions[$conjunction][$i] = clone $condition;
                }
            }
        }
    }
}

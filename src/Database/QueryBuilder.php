<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database;

use Cake\Database\ValueBinder;
use Closure;
use Crustum\Mongo\Database\Expression\ArrayExpression;
use Crustum\Mongo\Database\Expression\BetweenExpression;
use Crustum\Mongo\Database\Expression\ComparisonExpression;
use Crustum\Mongo\Database\Expression\ElementMatchExpression;
use Crustum\Mongo\Database\Expression\ExistsExpression;
use Crustum\Mongo\Database\Expression\Expression;
use Crustum\Mongo\Database\Expression\GeospatialExpression;
use Crustum\Mongo\Database\Expression\InExpression;
use Crustum\Mongo\Database\Expression\MongoExpressionInterface;
use Crustum\Mongo\Database\Expression\QueryExpression;
use Crustum\Mongo\Database\Expression\RegexExpression;
use InvalidArgumentException;
use MongoDB\BSON\ObjectId;

class QueryBuilder
{
    /**
     * The current conditions being built.
     *
     * @var array<int|string, mixed>
     */
    protected array $_conditions = [];

    /**
     * Optional field resolver applied to condition field names.
     *
     * The Database layer is alias-agnostic; when set (by the ODM layer), every
     * condition field is passed through this callable so repository aliases
     * (`Alias.field`) become bare Mongo fields before compilation.
     *
     * @var \Closure|null
     */
    protected ?Closure $fieldResolver = null;

    /**
     * Sets the field resolver applied to condition field names.
     *
     * @param \Closure|null $resolver Callable receiving a field name and returning the Mongo field.
     * @return $this
     */
    public function setFieldResolver(?Closure $resolver): static
    {
        $this->fieldResolver = $resolver;

        return $this;
    }

    /**
     * Resolves a condition field name through the configured resolver.
     *
     * @param string $field The raw field name.
     * @return string The resolved Mongo field name.
     */
    public function resolveField(string $field): string
    {
        return $this->fieldResolver instanceof Closure
            ? ($this->fieldResolver)($field)
            : $field;
    }

    /**
     * Add conditions to the query
     *
     * @param \Crustum\Mongo\Database\Expression\Expression|array<string, mixed>|string $conditions The conditions to add
     * @param array<string, mixed>                                                     $values     Array of values to be bound to placeholders
     * @return $this
     */
    public function where(array|string|Expression $conditions, array $values = [])
    {
        if ($conditions instanceof Expression) {
            $this->_conditions = array_merge(
                $this->_conditions,
                json_decode($conditions->sql(new ValueBinder()), true),
            );

            return $this;
        }

        if (is_string($conditions)) {
            $conditions = [$conditions];
        }

        foreach ($conditions as $field => $value) {
            if (is_array($value)) {
                $value = $this->convertIdValues($value);
            } elseif (is_string($value) && strlen($value) === 24 && ctype_xdigit($value)) {
                $value = new ObjectId($value);
            }

            $this->_conditions[$field] = $value;
        }

        return $this;
    }

    /**
     * Recursively converts 24-hex string values to ObjectId inside arrays.
     *
     * `IN`/`$in` conditions carry arrays of identifier values; each string that
     * looks like an ObjectId hex is converted so the query matches ObjectId
     * foreign keys in the database.
     *
     * @param array<int|string, mixed> $values The values to convert.
     * @return array<int|string, mixed>
     */
    protected function convertIdValues(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = $this->convertIdValues($value);
            } elseif (is_string($value) && strlen($value) === 24 && ctype_xdigit($value)) {
                $values[$key] = new ObjectId($value);
            }
        }

        return $values;
    }

    /**
     * Returns a new QueryExpression object.
     *
     * @param array<string, mixed> $conditions  Conditions to add to the expression
     * @param string $conjunction The conjunction to use (AND/OR)
     * @return \Crustum\Mongo\Database\Expression\QueryExpression
     */
    public function newExpr(array $conditions = [], string $conjunction = '$and'): QueryExpression
    {
        return new QueryExpression($conditions, $conjunction);
    }

    /**
     * Creates a comparison expression.
     *
     * @param string $field    The field name to compare
     * @param mixed  $value    The value to compare against
     * @param string $operator The comparison operator
     * @return \Crustum\Mongo\Database\Expression\ComparisonExpression
     */
    public function comparison(
        string $field,
        mixed $value,
        string $operator,
    ): ComparisonExpression {
        return new ComparisonExpression($field, $value, $operator);
    }

    /**
     * Creates an IN expression.
     *
     * @param string $field  The field name
     * @param array<int, mixed> $values The values to compare against
     * @return \Crustum\Mongo\Database\Expression\InExpression
     */
    public function in(string $field, array $values): InExpression
    {
        return new InExpression($field, $values);
    }

    /**
     * Creates a BETWEEN expression.
     *
     * @param string $field The field name
     * @param mixed  $from  From value
     * @param mixed  $to    To value
     * @return \Crustum\Mongo\Database\Expression\BetweenExpression
     */
    public function between(string $field, mixed $from, mixed $to): BetweenExpression
    {
        return new BetweenExpression($field, $from, $to);
    }

    /**
     * Creates a regex expression
     *
     * @param string $field   Field name
     * @param string $pattern Regex pattern
     * @param string $options Regex options
     * @return \Crustum\Mongo\Database\Expression\RegexExpression
     */
    public function regex(string $field, string $pattern, string $options = ''): RegexExpression
    {
        return new RegexExpression($field, $pattern, $options);
    }

    /**
     * Creates an exists expression
     *
     * @param string $field  Field name
     * @param bool   $exists Whether the field should exist
     * @return \Crustum\Mongo\Database\Expression\ExistsExpression
     */
    public function exists(string $field, bool $exists = true): ExistsExpression
    {
        return new ExistsExpression($field, $exists);
    }

    /**
     * Creates an elemMatch expression
     *
     * @param string                                             $field      Field name
     * @param \Crustum\Mongo\Database\Expression\QueryExpression|array<string, mixed> $conditions Conditions
     * @return \Crustum\Mongo\Database\Expression\ElementMatchExpression
     */
    public function elemMatch(string $field, array|QueryExpression $conditions): ElementMatchExpression
    {
        if ($conditions instanceof QueryExpression) {
            $conditions = $conditions->getConditions();
        }

        return new ElementMatchExpression($field, $conditions);
    }

    /**
     * Creates an all expression
     *
     * @param string $field  Field name
     * @param array<string, mixed> $values Values that must all match
     * @return \Crustum\Mongo\Database\Expression\ArrayExpression
     */
    public function all(string $field, array $values): ArrayExpression
    {
        return new ArrayExpression($field, $values, '$all');
    }

    /**
     * Creates a near expression
     *
     * @param string $field     Field name
     * @param float  $longitude Longitude
     * @param float  $latitude  Latitude
     * @param array<string, mixed> $options   Additional options (maxDistance, minDistance)
     * @return \Crustum\Mongo\Database\Expression\GeospatialExpression
     */
    public function near(
        string $field,
        float $longitude,
        float $latitude,
        array $options = [],
    ): GeospatialExpression {
        $geometry = [
            'type' => 'Point',
            'coordinates' => [$longitude, $latitude],
        ];

        return new GeospatialExpression($field, $geometry, '$near', $options);
    }

    /**
     * Creates a geoWithin expression
     *
     * @param string $field   Field name
     * @param array<int, mixed> $polygon Array of [longitude, latitude] points
     * @return \Crustum\Mongo\Database\Expression\GeospatialExpression
     */
    public function geoWithin(string $field, array $polygon): GeospatialExpression
    {
        $geometry = [
            'type' => 'Polygon',
            'coordinates' => [$polygon],
        ];

        return new GeospatialExpression($field, $geometry, '$geoWithin');
    }

    /**
     * Returns an AND query combining multiple conditions
     *
     * @param \Crustum\Mongo\Database\Expression\Expression|array<int, mixed> ...$expressions The expressions to combine
     * @return \Crustum\Mongo\Database\Expression\QueryExpression
     */
    public function and(array|Expression ...$expressions): QueryExpression
    {
        return new QueryExpression($expressions, '$and');
    }

    /**
     * Returns an OR query combining multiple conditions
     *
     * @param \Crustum\Mongo\Database\Expression\Expression|array<int, mixed> ...$expressions The expressions to combine
     * @return \Crustum\Mongo\Database\Expression\QueryExpression
     */
    public function or(array|Expression ...$expressions): QueryExpression
    {
        return new QueryExpression($expressions, '$or');
    }

    /**
     * Creates a greater than comparison
     *
     * @param string $field The field to compare
     * @param mixed  $value The value to compare against
     * @return \Crustum\Mongo\Database\Expression\ComparisonExpression
     */
    public function gt(string $field, mixed $value): ComparisonExpression
    {
        return new ComparisonExpression($field, $value, '$gt');
    }

    /**
     * Creates a less than comparison
     *
     * @param string $field The field to compare
     * @param mixed  $value The value to compare against
     * @return \Crustum\Mongo\Database\Expression\ComparisonExpression
     */
    public function lt(string $field, mixed $value): ComparisonExpression
    {
        return new ComparisonExpression($field, $value, '$lt');
    }

    /**
     * Creates a greater than or equal comparison
     *
     * @param string $field The field to compare
     * @param mixed  $value The value to compare against
     * @return \Crustum\Mongo\Database\Expression\ComparisonExpression
     */
    public function gte(string $field, mixed $value): ComparisonExpression
    {
        return new ComparisonExpression($field, $value, '$gte');
    }

    /**
     * Creates a less than or equal comparison
     *
     * @param string $field The field to compare
     * @param mixed  $value The value to compare against
     * @return \Crustum\Mongo\Database\Expression\ComparisonExpression
     */
    public function lte(string $field, mixed $value): ComparisonExpression
    {
        return new ComparisonExpression($field, $value, '$lte');
    }

    /**
     * Creates an equals comparison
     *
     * @param string $field The field to compare
     * @param mixed  $value The value to compare against
     * @return \Crustum\Mongo\Database\Expression\ComparisonExpression
     */
    public function eq(string $field, mixed $value): ComparisonExpression
    {
        return new ComparisonExpression($field, $value, '$eq');
    }

    /**
     * Creates a NOT expression
     *
     * @param \Crustum\Mongo\Database\Expression\MongoExpressionInterface|array<string, mixed> $expression The expression to negate
     * @return \Crustum\Mongo\Database\Expression\MongoExpressionInterface
     */
    public function not(array|MongoExpressionInterface $expression): MongoExpressionInterface
    {
        if ($expression instanceof MongoExpressionInterface) {
            $conditions = $expression->getConditions();
        } else {
            $conditions = $expression;
        }

        if (count($conditions) === 1) {
            $field = key($conditions);
            $value = current($conditions);

            return new ComparisonExpression($field, $value, '$ne');
        }

        return new ComparisonExpression('$nor', [$conditions], '$nor');
    }

    /**
     * Parse array conditions into MongoDB query expressions
     *
     * @param array<int|string, mixed> $conditions The conditions to parse
     * @return array<int|string, mixed>
     */
    public function parse(array $conditions): array
    {
        $result = [];
        foreach ($conditions as $key => $value) {
            if (is_string($key) && in_array(strtoupper($key), ['AND', 'OR', 'NOT'], true)) {
                $operator = '$' . strtolower($key);
                if ($operator === '$not') {
                    $result['$nor'] = [is_array($value) ? $this->parse($value) : $value];
                } else {
                    $parsed = [];
                    foreach ((array)$value as $k => $v) {
                        if (is_string($k) && in_array(strtoupper($k), ['AND', 'OR', 'NOT'], true)) {
                            $nestedOp = '$' . strtolower($k);
                            $nestedConditions = [];
                            foreach ((array)$v as $nk => $nv) {
                                if (is_string($nk)) {
                                    $field = $nk;
                                    if (str_contains($nk, ' ')) {
                                        $field = explode(' ', $nk)[0];
                                    }

                                    $nestedConditions[$this->resolveField($field)] = $this->parseCondition($nk, $nv);
                                }
                            }

                            if ($nestedConditions !== []) {
                                if ($nestedOp === '$not') {
                                    $parsed[] = ['$nor' => [$nestedConditions]];
                                } else {
                                    $parsed[] = [$nestedOp => array_map(
                                        static fn(string $field, mixed $condition): array => [$field => $condition],
                                        array_keys($nestedConditions),
                                        $nestedConditions,
                                    )];
                                }
                            }

                            continue;
                        }

                        if (is_string($k)) {
                            $field = $k;
                            if (str_contains($k, ' ')) {
                                $field = explode(' ', $k)[0];
                            }

                            $parsed[] = [$this->resolveField($field) => $this->parseCondition($k, $v)];
                        } elseif (is_array($v)) {
                            $parsed[] = $this->parse($v);
                        }
                    }

                    $result[$operator] = $parsed;
                }

                continue;
            }

            if (is_string($key) && str_starts_with($key, '$')) {
                if (is_array($value)) {
                    if (in_array(strtoupper($key), ['$OR', '$AND', '$NOR'], true)) {
                        $result[$key] = array_map(fn(mixed $c): mixed => is_array($c) ? $this->parse($c) : $c, $value);
                    } else {
                        $result[$key] = $this->parseExprValue($value);
                    }
                } else {
                    $result[$key] = $value;
                }

                continue;
            }

            if (is_numeric($key)) {
                if (is_array($value)) {
                    $parsed = $this->parse($value);
                    if ($parsed !== []) {
                        $result = array_merge($result, $parsed);
                    }
                } else {
                    $result[] = $value;
                }

                continue;
            }

            $field = $key;
            if (str_contains($key, ' ')) {
                $field = explode(' ', $key)[0];
            }

            $resolvedField = $this->resolveField($field);
            $parsedCondition = $this->parseCondition($key, $value);
            if (isset($result[$field]) && is_array($result[$field]) && is_array($parsedCondition)) {
                $operator = key($parsedCondition);
                $result[$resolvedField] = isset($result[$field][$operator]) ? $parsedCondition : array_merge($result[$field], $parsedCondition);
            } else {
                $result[$resolvedField] = $parsedCondition;
            }
        }

        return $result;
    }

    /**
     * Parses an `$expr`-style value, resolving field-path operands.
     *
     * `$expr` operands are `$field` strings (e.g. `$Author.user_id`). Array
     * values recurse; `$field` strings that carry the repository alias prefix
     * are resolved to bare fields (`$Author.user_id` → `$user_id`).
     *
     * @param mixed $value The `$expr` operand value.
     * @return mixed
     */
    protected function parseExprValue(mixed $value): mixed
    {
        if (is_array($value)) {
            $resolved = [];
            foreach ($value as $k => $v) {
                $resolved[$k] = is_string($k) && str_starts_with($k, '$') && in_array(strtoupper($k), ['$AND', '$OR', '$NOR'], true)
                    ? $this->parseExprValue($v)
                    : $this->parseExprValue($v);
            }

            return $resolved;
        }

        if (is_string($value) && str_starts_with($value, '$')) {
            return '$' . $this->resolveField(substr($value, 1));
        }

        return $value;
    }

    /**
     * Parses a single condition key/value into a Mongo filter fragment.
     *
     * Supports operator suffixes in the key (e.g. `name LIKE`, `age >`, `id IN`).
     *
     * @param string $key The condition key, optionally with an operator.
     * @param mixed $value The condition value.
     * @return mixed The compiled Mongo filter fragment.
     */
    protected function parseCondition(string $key, mixed $value): mixed
    {
        $operator = '=';
        $parts = explode(' ', trim($key), 2);
        if (count($parts) > 1) {
            [, $operator] = $parts;
        }

        if (is_array($value) && is_string(key($value)) && str_starts_with(key($value), '$')) {
            return $value;
        }

        $operator = strtolower(trim($operator));

        switch ($operator) {
            case '=':
            case 'eq':
                if (is_array($value) && $value !== [] && !array_is_list($value)) {
                    return ['$elemMatch' => $value];
                }

                return $value;
            case 'is':
            default:
                return $value ?? ['$exists' => false];
            case '>':
                return ['$gt' => $value];
            case '>=':
                return ['$gte' => $value];
            case '<':
                return ['$lt' => $value];
            case '<=':
                return ['$lte' => $value];
            case 'between':
                if (!is_array($value) || count($value) !== 2) {
                    throw new InvalidArgumentException('BETWEEN requires an array with exactly 2 values');
                }

                return [
                    '$gte' => $value[0],
                    '$lte' => $value[1],
                ];
            case 'in':
                return ['$in' => (array)$value];
            case 'not in':
                return ['$nin' => (array)$value];
            case 'like':
                return ['$regex' => $this->likeToRegex($value)];
            case 'not like':
                return ['$not' => ['$regex' => $this->likeToRegex($value)]];
            case 'is not':
                return $value === null ? ['$exists' => true] : ['$ne' => $value];
            case '!=':
            case '<>':
                return ['$ne' => $value];
        }
    }

    /**
     * Convert SQL LIKE pattern to MongoDB regex
     *
     * @param string $like The LIKE pattern
     * @return string
     */
    protected function likeToRegex(string $like): string
    {
        $pattern = preg_quote($like, '/');
        $pattern = str_replace(['%', '_'], ['.*', '.'], $pattern);

        return "^{$pattern}$";
    }

    /**
     * Converts the expression tree into a plain array
     *
     * @return array<string, mixed> The conditions as a plain array
     */
    public function getConditions(): array
    {
        return $this->traverse($this->_conditions);
    }

    /**
     * Recursively traverses the expression tree and converts to arrays
     *
     * @param mixed $conditions The conditions to traverse
     * @return array<string, mixed> The converted conditions
     */
    private function traverse(mixed $conditions): array
    {
        if ($conditions instanceof MongoExpressionInterface) {
            return $conditions->getConditions();
        }

        if (is_array($conditions)) {
            $result = [];
            foreach ($conditions as $key => $value) {
                if ($value instanceof MongoExpressionInterface) {
                    $result[$key] = $value->getConditions();
                } elseif (is_array($value)) {
                    $result[$key] = $this->traverse($value);
                } else {
                    $result[$key] = $value;
                }
            }

            return $result;
        }

        return (array)$conditions;
    }

    /**
     * Creates a limit expression
     *
     * @param int $value The limit value
     * @return array<string, int> The limit expression
     */
    public function limit(int $value): array
    {
        return ['$limit' => $value];
    }
}

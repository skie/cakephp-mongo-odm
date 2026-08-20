<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Expression;

use Cake\Database\ValueBinder;

/**
 * MongoDB aggregation operator expression.
 *
 * The Mongo analog of `Cake\Database\Expression\FunctionExpression`: a named
 * aggregation operator (`$sum`, `$concat`, `$dateAdd`, …) with its arguments.
 * A Mongo "function" is an operator document, so `getConditions()` /
 * `getExpression()` render `['$name' => arguments]` and `sql()` encodes it to
 * JSON — consistent with the rest of the expression family.
 *
 * Arguments may be nested `FunctionExpression` instances, which are resolved
 * recursively so operators compose the same way Cake functions do.
 *
 * @inspired-by \Cake\Database\Expression\FunctionExpression
 */
class FunctionExpression extends Expression implements MongoExpressionInterface
{
    /**
     * The operator name, including the leading `$`.
     *
     * @var string
     */
    protected string $name;

    /**
     * The operator arguments.
     *
     * @var array<int|string, mixed>
     */
    protected array $params;

    /**
     * Constructor
     *
     * @param string $name The operator name (e.g. `$sum` or `sum`)
     * @param array<string|int, mixed> $params The operator arguments
     */
    public function __construct(string $name, array $params = [])
    {
        $this->name = ltrim($name, '$') === $name ? '$' . $name : $name;
        $this->params = $params;
    }

    /**
     * Returns the operator name (including the leading `$`).
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Replaces the operator name.
     *
     * @param string $name The operator name
     * @return $this
     */
    public function setName(string $name): static
    {
        $this->name = ltrim($name, '$') === $name ? '$' . $name : $name;

        return $this;
    }

    /**
     * Appends an operator argument.
     *
     * @param mixed $param The argument to append
     * @return $this
     */
    public function add(mixed $param): static
    {
        $this->params[] = $param;

        return $this;
    }

    /**
     * Returns the number of arguments.
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->params);
    }

    /**
     * Returns the rendered operator document.
     *
     * @return array<string, mixed>
     */
    public function getConditions(): array
    {
        return [$this->name => $this->normalize($this->params)];
    }

    /**
     * Alias of `getConditions()` for use inside aggregation stages.
     *
     * @return array<string, mixed>
     */
    public function getExpression(): array
    {
        return $this->getConditions();
    }

    /**
     * @inheritDoc
     */
    public function sql(ValueBinder $binder): string
    {
        return json_encode($this->getConditions()) ?: '{}';
    }

    /**
     * Renders the operator arguments.
     *
     * A single argument is passed as-is, multiple arguments become an array and
     * an empty argument list becomes an empty document (operators such as
     * `$rand` and `$rowNumber` require `{}`).
     *
     * @param array<int|string, mixed> $params The arguments
     * @return mixed The rendered arguments
     */
    protected function normalize(array $params): mixed
    {
        $count = count($params);
        if ($count === 0) {
            return (object)[];
        }

        if ($count === 1) {
            return $this->normalizeParam(current($params));
        }

        return array_map(
            $this->normalizeParam(...),
            $this->normalizePairs($params),
        );
    }

    /**
     * Rewrites assoc params into a list of single-element assoc entries so
     * `normalizeParam` can distinguish `[field => 'literal']` identifiers from
     * plain values while iterating.
     *
     * @param array<int|string, mixed> $params The arguments
     * @return list<mixed>
     */
    protected function normalizePairs(array $params): array
    {
        $out = [];
        foreach ($params as $key => $value) {
            $out[] = is_string($key) ? [$key => $value] : $value;
        }

        return $out;
    }

    /**
     * Renders a single argument, recursing into nested expressions and arrays.
     *
     * A `[field => 'literal']` entry marks an identifier argument (cake
     * `FunctionExpression` type syntax): the key is a field path that becomes
     * a `$field` operand, the `'literal'` value is only a type marker.
     *
     * @param mixed $param The argument
     * @return mixed The rendered argument
     */
    protected function normalizeParam(mixed $param): mixed
    {
        if ($param instanceof self) {
            return $param->getConditions();
        }

        if (is_array($param) && $param !== [] && array_is_list($param)) {
            return array_map($this->normalizeParam(...), $param);
        }

        if (is_array($param) && $param !== [] && count($param) === 1) {
            $key = array_key_first($param);
            $value = $param[$key];
            if (is_string($key) && in_array($value, ['literal', 'identifier'], true)) {
                return '$' . $key;
            }
        }

        if (is_array($param)) {
            $out = [];
            foreach ($param as $key => $value) {
                if (is_string($key) && in_array($value, ['literal', 'identifier'], true)) {
                    $out[] = '$' . $key;
                } else {
                    $out[$key] = $this->normalizeParam($value);
                }
            }

            return $out;
        }

        return $param;
    }
}

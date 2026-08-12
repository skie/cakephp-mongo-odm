<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database;

use Crustum\Mongo\Database\Expression\FunctionExpression;

/**
 * MongoDB aggregation "functions" builder.
 *
 * The Mongo analog of `Cake\Database\FunctionsBuilder`: a factory that returns
 * `FunctionExpression` objects for aggregation operators. Every method renders
 * to a MongoDB operator document (`['$name' => arguments]`) via
 * `FunctionExpression::getExpression()`. Unknown operators fall back to
 * `__call()`.
 *
 * Access it through `AggregationBuilder::func()` (mirrors `$query->func()`).
 */
class FunctionsBuilder
{
    /**
     * Returns a random value between 0 and 1.
     *
     * @return \Crustum\Mongo\Database\Expression\FunctionExpression
     */
    public function rand(): FunctionExpression
    {
        return new FunctionExpression('$rand');
    }

    /**
     * Returns the sum of the given expression(s).
     *
     * @param mixed $expression The expression
     * @return \Crustum\Mongo\Database\Expression\FunctionExpression
     */
    public function sum(mixed $expression): FunctionExpression
    {
        return new FunctionExpression('$sum', [$expression]);
    }

    /**
     * Returns the average of the given expression(s).
     *
     * @param mixed $expression The expression
     * @return \Crustum\Mongo\Database\Expression\FunctionExpression
     */
    public function avg(mixed $expression): FunctionExpression
    {
        return new FunctionExpression('$avg', [$expression]);
    }

    /**
     * Returns the maximum value of the given expression.
     *
     * @param mixed $expression The expression
     * @return \Crustum\Mongo\Database\Expression\FunctionExpression
     */
    public function max(mixed $expression): FunctionExpression
    {
        return new FunctionExpression('$max', [$expression]);
    }

    /**
     * Returns the minimum value of the given expression.
     *
     * @param mixed $expression The expression
     * @return \Crustum\Mongo\Database\Expression\FunctionExpression
     */
    public function min(mixed $expression): FunctionExpression
    {
        return new FunctionExpression('$min', [$expression]);
    }

    /**
     * Returns a count of documents/rows.
     *
     * Mongo's `$count` is a stage/window operator; for group counting use
     * `sum(1)`.
     *
     * @return \Crustum\Mongo\Database\Expression\FunctionExpression
     */
    public function count(): FunctionExpression
    {
        return new FunctionExpression('$count');
    }

    /**
     * Concatenates strings.
     *
     * @param list<mixed> $args The expressions to concatenate
     * @return \Crustum\Mongo\Database\Expression\FunctionExpression
     */
    public function concat(array $args): FunctionExpression
    {
        return new FunctionExpression('$concat', $args);
    }

    /**
     * Returns the first non-null expression.
     *
     * @param list<mixed> $args The expressions
     * @return \Crustum\Mongo\Database\Expression\FunctionExpression
     */
    public function coalesce(array $args): FunctionExpression
    {
        return new FunctionExpression('$ifNull', $args);
    }

    /**
     * Converts a value to a BSON type.
     *
     * @param mixed $field The input expression
     * @param string $type The target BSON type
     * @return \Crustum\Mongo\Database\Expression\FunctionExpression
     */
    public function cast(mixed $field, string $type): FunctionExpression
    {
        return new FunctionExpression('$convert', [['input' => $field, 'to' => $type]]);
    }

    /**
     * Returns the difference between two dates.
     *
     * @param mixed $startDate The start date
     * @param mixed $endDate The end date
     * @param string $unit The unit (millisecond, second, minute, hour, day, week, month, quarter, year)
     * @param mixed|null $timezone The timezone
     * @return \Crustum\Mongo\Database\Expression\FunctionExpression
     */
    public function dateDiff(mixed $startDate, mixed $endDate, string $unit, mixed $timezone = null): FunctionExpression
    {
        return new FunctionExpression('$dateDiff', [[
            'startDate' => $startDate,
            'endDate' => $endDate,
            'unit' => $unit,
            'timezone' => $timezone,
        ]]);
    }

    /**
     * Extracts a date part (`year`, `month`, `dayOfMonth`, `dayOfWeek`, …).
     *
     * @param string $part The date part
     * @param mixed $expression The date expression
     * @return \Crustum\Mongo\Database\Expression\FunctionExpression
     */
    public function extract(string $part, mixed $expression): FunctionExpression
    {
        return new FunctionExpression('$' . $part, [$expression]);
    }

    /**
     * Alias of `extract()`.
     *
     * @param string $part The date part
     * @param mixed $expression The date expression
     * @return \Crustum\Mongo\Database\Expression\FunctionExpression
     */
    public function datePart(string $part, mixed $expression): FunctionExpression
    {
        return $this->extract($part, $expression);
    }

    /**
     * Adds an amount to a date.
     *
     * @param mixed $startDate The start date
     * @param string $unit The unit
     * @param mixed $amount The amount
     * @return \Crustum\Mongo\Database\Expression\FunctionExpression
     */
    public function dateAdd(mixed $startDate, string $unit, mixed $amount): FunctionExpression
    {
        return new FunctionExpression('$dateAdd', [[
            'startDate' => $startDate,
            'unit' => $unit,
            'amount' => $amount,
        ]]);
    }

    /**
     * Returns the day of the week.
     *
     * @param mixed $expression The date expression
     * @return \Crustum\Mongo\Database\Expression\FunctionExpression
     */
    public function dayOfWeek(mixed $expression): FunctionExpression
    {
        return new FunctionExpression('$dayOfWeek', [$expression]);
    }

    /**
     * Alias of `dayOfWeek()`.
     *
     * @param mixed $expression The date expression
     * @return \Crustum\Mongo\Database\Expression\FunctionExpression
     */
    public function weekday(mixed $expression): FunctionExpression
    {
        return $this->dayOfWeek($expression);
    }

    /**
     * Returns the current date (`$$NOW` system variable).
     *
     * The value embeds as a bare system variable, so it is returned as a string
     * rather than an operator document.
     *
     * @return string
     */
    public function now(): string
    {
        return '$$NOW';
    }

    /**
     * Returns the row number within a `$setWindowFields` window.
     *
     * @return \Crustum\Mongo\Database\Expression\FunctionExpression
     */
    public function rowNumber(): FunctionExpression
    {
        return new FunctionExpression('$documentNumber');
    }

    /**
     * Returns the value from a preceding row (window `$shift`).
     *
     * @param mixed $output The output expression
     * @param int $by The positive offset
     * @param mixed|null $default The default value
     * @return \Crustum\Mongo\Database\Expression\FunctionExpression
     */
    public function lag(mixed $output, int $by, mixed $default = null): FunctionExpression
    {
        return $this->shift($output, $by, $default);
    }

    /**
     * Returns the value from a following row (window `$shift`).
     *
     * @param mixed $output The output expression
     * @param int $by The positive offset
     * @param mixed|null $default The default value
     * @return \Crustum\Mongo\Database\Expression\FunctionExpression
     */
    public function lead(mixed $output, int $by, mixed $default = null): FunctionExpression
    {
        return $this->shift($output, -$by, $default);
    }

    /**
     * Reads a field of a document (`$getField`).
     *
     * @param mixed $field The field name
     * @param mixed|null $input The input document expression
     * @return \Crustum\Mongo\Database\Expression\FunctionExpression
     */
    public function jsonValue(mixed $field, mixed $input = null): FunctionExpression
    {
        $args = ['field' => $field];
        if ($input !== null) {
            $args['input'] = $input;
        }

        return new FunctionExpression('$getField', [$args]);
    }

    /**
     * Builds a `$filter` array-filter expression.
     *
     * `$filter` iterates `input`, aliases each element as `as`, and keeps the
     * elements for which `cond` (an `$expr`-style condition, e.g. built with
     * `eq()`/`and()`/`or()`) evaluates truthy. Used to filter in-document
     * arrays of embedded/joined documents.
     *
     * @param mixed $input The input array expression (`$field` path or expression).
     * @param string $as The element variable name (without `$`).
     * @param mixed $cond The filter condition expression.
     * @return \Crustum\Mongo\Database\Expression\FunctionExpression
     */
    public function filter(mixed $input, string $as, mixed $cond): FunctionExpression
    {
        return new FunctionExpression('$filter', [[
            'input' => $input,
            'as' => $as,
            'cond' => $cond,
        ]]);
    }

    /**
     * Builds an arbitrary operator expression.
     *
     * @param string $name The operator name (with or without the leading `$`)
     * @param list<mixed> $params The operator arguments
     * @return \Crustum\Mongo\Database\Expression\FunctionExpression
     */
    public function aggregate(string $name, array $params = []): FunctionExpression
    {
        return new FunctionExpression($name, $params);
    }

    /**
     * Builds an expression for any other operator.
     *
     * @param string $name The operator name
     * @param list<mixed> $args The operator arguments
     * @return \Crustum\Mongo\Database\Expression\FunctionExpression
     */
    public function __call(string $name, array $args): FunctionExpression
    {
        return new FunctionExpression($name, $args);
    }

    /**
     * Renders a window `$shift`.
     *
     * @param mixed $output The output expression
     * @param int $by The offset (negative for following rows)
     * @param mixed|null $default The default value
     * @return \Crustum\Mongo\Database\Expression\FunctionExpression
     */
    protected function shift(mixed $output, int $by, mixed $default = null): FunctionExpression
    {
        $args = ['output' => $output, 'by' => $by];
        if ($default !== null) {
            $args['default'] = $default;
        }

        return new FunctionExpression('$shift', [$args]);
    }
}

<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Aggregation;

/**
 * Expression builder for aggregation pipeline expressions
 *
 * Provides methods to build complex expressions for use in aggregation stages
 * Supports operator categories: Arithmetic, Array, Boolean, Comparison, Conditional,
 * Date, String, Type, Window, and Accumulator operators
 */
class Expr
{
    /**
     * The expression data
     *
     * @var array<string, mixed>
     */
    protected array $expr = [];

    /**
     * The current field being built
     *
     * @var string|null
     */
    protected ?string $currentField = null;

    /**
     * Set the current field for building expressions
     *
     * @param string $fieldName The field name
     * @return $this
     */
    public function field(string $fieldName)
    {
        $this->currentField = $fieldName;

        return $this;
    }

    /**
     * Add a field with an expression value
     *
     * @param string $fieldName  The field name
     * @param mixed  $expression The expression value (can be array, string, number, etc.)
     * @return $this
     */
    public function addField(string $fieldName, mixed $expression)
    {
        $this->expr[$fieldName] = $expression;

        return $this;
    }

    /**
     * Get the expression array
     *
     * @return array<string, mixed>
     */
    public function getExpression(): array
    {
        return $this->expr;
    }

    /**
     * Reset the expression
     *
     * @return $this
     */
    public function reset()
    {
        $this->expr = [];
        $this->currentField = null;

        return $this;
    }

    /**
     * Arithmetic Operators
     */

    /**
     * Add numbers or dates
     *
     * @param mixed ...$expressions The expressions to add
     * @return array<string, mixed>
     */
    public function add(mixed ...$expressions): array
    {
        return ['$add' => $expressions];
    }

    /**
     * Subtract numbers or dates
     *
     * @param mixed $expression1 The first expression
     * @param mixed $expression2 The second expression
     * @return array<string, mixed>
     */
    public function subtract(mixed $expression1, mixed $expression2): array
    {
        return ['$subtract' => [$expression1, $expression2]];
    }

    /**
     * Multiply numbers
     *
     * @param mixed ...$expressions The expressions to multiply
     * @return array<string, mixed>
     */
    public function multiply(mixed ...$expressions): array
    {
        return ['$multiply' => $expressions];
    }

    /**
     * Divide numbers
     *
     * @param mixed $expression1 The dividend
     * @param mixed $expression2 The divisor
     * @return array<string, mixed>
     */
    public function divide(mixed $expression1, mixed $expression2): array
    {
        return ['$divide' => [$expression1, $expression2]];
    }

    /**
     * Modulo operation
     *
     * @param mixed $expression1 The dividend
     * @param mixed $expression2 The divisor
     * @return array<string, mixed>
     */
    public function mod(mixed $expression1, mixed $expression2): array
    {
        return ['$mod' => [$expression1, $expression2]];
    }

    /**
     * Raise a number to a power
     *
     * @param mixed $number The base
     * @param mixed $exponent The exponent
     * @return array<string, mixed>
     */
    public function pow(mixed $number, mixed $exponent): array
    {
        return ['$pow' => [$number, $exponent]];
    }

    /**
     * Square root
     *
     * @param mixed $expression The expression
     * @return array<string, mixed>
     */
    public function sqrt(mixed $expression): array
    {
        return ['$sqrt' => $expression];
    }

    /**
     * Absolute value
     *
     * @param mixed $expression The expression
     * @return array<string, mixed>
     */
    public function abs(mixed $expression): array
    {
        return ['$abs' => $expression];
    }

    /**
     * Ceiling
     *
     * @param mixed $expression The expression
     * @return array<string, mixed>
     */
    public function ceil(mixed $expression): array
    {
        return ['$ceil' => $expression];
    }

    /**
     * Floor
     *
     * @param mixed $expression The expression
     * @return array<string, mixed>
     */
    public function floor(mixed $expression): array
    {
        return ['$floor' => $expression];
    }

    /**
     * Round
     *
     * @param mixed $number The number to round
     * @param mixed $place  The place to round to (optional)
     * @return array<string, mixed>
     */
    public function round(mixed $number, mixed $place = null): array
    {
        if ($place !== null) {
            return ['$round' => [$number, $place]];
        }

        return ['$round' => $number];
    }

    /**
     * Truncate
     *
     * @param mixed $number The number to truncate
     * @param mixed $place  The place to truncate to (optional)
     * @return array<string, mixed>
     */
    public function trunc(mixed $number, mixed $place = null): array
    {
        if ($place !== null) {
            return ['$trunc' => [$number, $place]];
        }

        return ['$trunc' => $number];
    }

    /**
     * Array Operators
     */

    /**
     * Array element at index
     *
     * @param mixed $array The array expression
     * @param mixed $index The index
     * @return array<string, mixed>
     */
    public function arrayElemAt(mixed $array, mixed $index): array
    {
        return ['$arrayElemAt' => [$array, $index]];
    }

    /**
     * Concatenate arrays
     *
     * @param mixed ...$arrays The arrays to concatenate
     * @return array<string, mixed>
     */
    public function concatArrays(mixed ...$arrays): array
    {
        return ['$concatArrays' => $arrays];
    }

    /**
     * Filter array
     *
     * @param mixed $input The input array
     * @param mixed $as    The variable name
     * @param mixed $cond  The condition
     * @return array<string, mixed>
     */
    public function filter(mixed $input, mixed $as, mixed $cond): array
    {
        return ['$filter' => ['input' => $input, 'as' => $as, 'cond' => $cond]];
    }

    /**
     * Check if value is in array
     *
     * @param mixed $expression The expression to check
     * @param mixed $array      The array
     * @return array<string, mixed>
     */
    public function in(mixed $expression, mixed $array): array
    {
        return ['$in' => [$expression, $array]];
    }

    /**
     * Index of array element
     *
     * @param mixed $array    The array
     * @param mixed $value    The value to find
     * @param mixed $start    The start index (optional)
     * @param mixed $end      The end index (optional)
     * @return array<string, mixed>
     */
    public function indexOfArray(mixed $array, mixed $value, mixed $start = null, mixed $end = null): array
    {
        $args = [$array, $value];
        if ($start !== null) {
            $args[] = $start;
            if ($end !== null) {
                $args[] = $end;
            }
        }

        return ['$indexOfArray' => $args];
    }

    /**
     * Check if array is empty
     *
     * @param mixed $expression The expression
     * @return array<string, mixed>
     */
    public function isEmpty(mixed $expression): array
    {
        return ['$isEmpty' => $expression];
    }

    /**
     * Array size
     *
     * @param mixed $expression The expression
     * @return array<string, mixed>
     */
    public function size(mixed $expression): array
    {
        return ['$size' => $expression];
    }

    /**
     * Slice array
     *
     * @param mixed $array The array
     * @param mixed $n    The number of elements
     * @param mixed $pos  The position (optional)
     * @return array<string, mixed>
     */
    public function slice(mixed $array, mixed $n, mixed $pos = null): array
    {
        if ($pos !== null) {
            return ['$slice' => [$array, $pos, $n]];
        }

        return ['$slice' => [$array, $n]];
    }

    /**
     * Map array
     *
     * @param mixed $input The input array
     * @param mixed $as    The variable name
     * @param mixed $in    The expression
     * @return array<string, mixed>
     */
    public function map(mixed $input, mixed $as, mixed $in): array
    {
        return ['$map' => ['input' => $input, 'as' => $as, 'in' => $in]];
    }

    /**
     * Reduce array
     *
     * @param mixed $input    The input array
     * @param mixed $initialValue The initial value
     * @param mixed $in       The expression
     * @return array<string, mixed>
     */
    public function reduce(mixed $input, mixed $initialValue, mixed $in): array
    {
        return ['$reduce' => ['input' => $input, 'initialValue' => $initialValue, 'in' => $in]];
    }

    /**
     * Reverse array
     *
     * @param mixed $array The array
     * @return array<string, mixed>
     */
    public function reverseArray(mixed $array): array
    {
        return ['$reverseArray' => $array];
    }

    /**
     * Zip arrays
     *
     * @param mixed ...$arrays The arrays to zip
     * @return array<string, mixed>
     */
    public function zip(mixed ...$arrays): array
    {
        return ['$zip' => ['inputs' => $arrays]];
    }

    /**
     * Boolean Operators
     */

    /**
     * Logical AND
     *
     * @param mixed ...$expressions The expressions
     * @return array<string, mixed>
     */
    public function and(mixed ...$expressions): array
    {
        return ['$and' => $expressions];
    }

    /**
     * Logical OR
     *
     * @param mixed ...$expressions The expressions
     * @return array<string, mixed>
     */
    public function or(mixed ...$expressions): array
    {
        return ['$or' => $expressions];
    }

    /**
     * Logical NOT
     *
     * @param mixed $expression The expression
     * @return array<string, mixed>
     */
    public function not(mixed $expression): array
    {
        return ['$not' => $expression];
    }

    /**
     * Comparison Operators
     */

    /**
     * Equal
     *
     * @param mixed $expression1 The first expression
     * @param mixed $expression2 The second expression
     * @return array<string, mixed>
     */
    public function eq(mixed $expression1, mixed $expression2): array
    {
        return ['$eq' => [$expression1, $expression2]];
    }

    /**
     * Greater than
     *
     * @param mixed $expression1 The first expression
     * @param mixed $expression2 The second expression
     * @return array<string, mixed>
     */
    public function gt(mixed $expression1, mixed $expression2): array
    {
        return ['$gt' => [$expression1, $expression2]];
    }

    /**
     * Greater than or equal
     *
     * @param mixed $expression1 The first expression
     * @param mixed $expression2 The second expression
     * @return array<string, mixed>
     */
    public function gte(mixed $expression1, mixed $expression2): array
    {
        return ['$gte' => [$expression1, $expression2]];
    }

    /**
     * Less than
     *
     * @param mixed $expression1 The first expression
     * @param mixed $expression2 The second expression
     * @return array<string, mixed>
     */
    public function lt(mixed $expression1, mixed $expression2): array
    {
        return ['$lt' => [$expression1, $expression2]];
    }

    /**
     * Less than or equal
     *
     * @param mixed $expression1 The first expression
     * @param mixed $expression2 The second expression
     * @return array<string, mixed>
     */
    public function lte(mixed $expression1, mixed $expression2): array
    {
        return ['$lte' => [$expression1, $expression2]];
    }

    /**
     * Not equal
     *
     * @param mixed $expression1 The first expression
     * @param mixed $expression2 The second expression
     * @return array<string, mixed>
     */
    public function ne(mixed $expression1, mixed $expression2): array
    {
        return ['$ne' => [$expression1, $expression2]];
    }

    /**
     * Conditional Operators
     */

    /**
     * Conditional expression (if-then-else)
     *
     * @param mixed $if   The condition
     * @param mixed $then The then expression
     * @param mixed $else The else expression
     * @return array<string, mixed>
     */
    public function cond(mixed $if, mixed $then, mixed $else): array
    {
        return ['$cond' => ['if' => $if, 'then' => $then, 'else' => $else]];
    }

    /**
     * Switch expression
     *
     * @param mixed $branches The branches array
     * @param mixed $default  The default expression
     * @return array<string, mixed>
     */
    public function switchExpr(mixed $branches, mixed $default): array
    {
        return ['$switch' => ['branches' => $branches, 'default' => $default]];
    }

    /**
     * If null
     *
     * @param mixed ...$expressions The expressions
     * @return array<string, mixed>
     */
    public function ifNull(mixed ...$expressions): array
    {
        return ['$ifNull' => $expressions];
    }

    /**
     * String Operators
     */

    /**
     * Concatenate strings
     *
     * @param mixed ...$strings The strings to concatenate
     * @return array<string, mixed>
     */
    public function concat(mixed ...$strings): array
    {
        return ['$concat' => $strings];
    }

    /**
     * Substring
     *
     * @param mixed $string The string
     * @param mixed $start  The start position
     * @param mixed $length The length (optional)
     * @return array<string, mixed>
     */
    public function substr(mixed $string, mixed $start, mixed $length = null): array
    {
        if ($length !== null) {
            return ['$substr' => [$string, $start, $length]];
        }

        return ['$substr' => [$string, $start]];
    }

    /**
     * Substring bytes
     *
     * @param mixed $string The string
     * @param mixed $start  The start position
     * @param mixed $length The length (optional)
     * @return array<string, mixed>
     */
    public function substrBytes(mixed $string, mixed $start, mixed $length = null): array
    {
        if ($length !== null) {
            return ['$substrBytes' => [$string, $start, $length]];
        }

        return ['$substrBytes' => [$string, $start]];
    }

    /**
     * Substring code points
     *
     * @param mixed $string The string
     * @param mixed $start  The start position
     * @param mixed $length The length (optional)
     * @return array<string, mixed>
     */
    public function substrCP(mixed $string, mixed $start, mixed $length = null): array
    {
        if ($length !== null) {
            return ['$substrCP' => [$string, $start, $length]];
        }

        return ['$substrCP' => [$string, $start]];
    }

    /**
     * String to lower case
     *
     * @param mixed $string The string
     * @return array<string, mixed>
     */
    public function toLower(mixed $string): array
    {
        return ['$toLower' => $string];
    }

    /**
     * String to upper case
     *
     * @param mixed $string The string
     * @return array<string, mixed>
     */
    public function toUpper(mixed $string): array
    {
        return ['$toUpper' => $string];
    }

    /**
     * String length
     *
     * @param mixed $string The string
     * @return array<string, mixed>
     */
    public function strLenBytes(mixed $string): array
    {
        return ['$strLenBytes' => $string];
    }

    /**
     * String length in code points
     *
     * @param mixed $string The string
     * @return array<string, mixed>
     */
    public function strLenCP(mixed $string): array
    {
        return ['$strLenCP' => $string];
    }

    /**
     * String case comparison
     *
     * @param mixed $string1 The first string
     * @param mixed $string2 The second string
     * @return array<string, mixed>
     */
    public function strcasecmp(mixed $string1, mixed $string2): array
    {
        return ['$strcasecmp' => [$string1, $string2]];
    }

    /**
     * Index of substring
     *
     * @param mixed $string The string
     * @param mixed $substring The substring
     * @param mixed $start The start index (optional)
     * @param mixed $end The end index (optional)
     * @return array<string, mixed>
     */
    public function indexOfBytes(mixed $string, mixed $substring, mixed $start = null, mixed $end = null): array
    {
        $args = [$string, $substring];
        if ($start !== null) {
            $args[] = $start;
            if ($end !== null) {
                $args[] = $end;
            }
        }

        return ['$indexOfBytes' => $args];
    }

    /**
     * Index of substring (code points)
     *
     * @param mixed $string The string
     * @param mixed $substring The substring
     * @param mixed $start The start index (optional)
     * @param mixed $end The end index (optional)
     * @return array<string, mixed>
     */
    public function indexOfCP(mixed $string, mixed $substring, mixed $start = null, mixed $end = null): array
    {
        $args = [$string, $substring];
        if ($start !== null) {
            $args[] = $start;
            if ($end !== null) {
                $args[] = $end;
            }
        }

        return ['$indexOfCP' => $args];
    }

    /**
     * Split string
     *
     * @param mixed $string The string
     * @param mixed $delimiter The delimiter
     * @return array<string, mixed>
     */
    public function split(mixed $string, mixed $delimiter): array
    {
        return ['$split' => [$string, $delimiter]];
    }

    /**
     * Regular expression match
     *
     * @param mixed $input The input string
     * @param mixed $regex The regular expression
     * @return array<string, mixed>
     */
    public function regexMatch(mixed $input, mixed $regex): array
    {
        return ['$regexMatch' => ['input' => $input, 'regex' => $regex]];
    }

    /**
     * Regular expression find all
     *
     * @param mixed $input The input string
     * @param mixed $regex The regular expression
     * @return array<string, mixed>
     */
    public function regexFindAll(mixed $input, mixed $regex): array
    {
        return ['$regexFindAll' => ['input' => $input, 'regex' => $regex]];
    }

    /**
     * Regular expression find
     *
     * @param mixed $input The input string
     * @param mixed $regex The regular expression
     * @return array<string, mixed>
     */
    public function regexFind(mixed $input, mixed $regex): array
    {
        return ['$regexFind' => ['input' => $input, 'regex' => $regex]];
    }

    /**
     * Replace one
     *
     * @param mixed $input The input string
     * @param mixed $find The string to find
     * @param mixed $replace The replacement string
     * @return array<string, mixed>
     */
    public function replaceOne(mixed $input, mixed $find, mixed $replace): array
    {
        return ['$replaceOne' => ['input' => $input, 'find' => $find, 'replacement' => $replace]];
    }

    /**
     * Replace all
     *
     * @param mixed $input The input string
     * @param mixed $find The string to find
     * @param mixed $replace The replacement string
     * @return array<string, mixed>
     */
    public function replaceAll(mixed $input, mixed $find, mixed $replace): array
    {
        return ['$replaceAll' => ['input' => $input, 'find' => $find, 'replacement' => $replace]];
    }

    /**
     * Trim string
     *
     * @param mixed $input The input string
     * @param mixed $chars The characters to trim (optional)
     * @return array<string, mixed>
     */
    public function trim(mixed $input, mixed $chars = null): array
    {
        if ($chars !== null) {
            return ['$trim' => ['input' => $input, 'chars' => $chars]];
        }

        return ['$trim' => ['input' => $input]];
    }

    /**
     * LTrim string
     *
     * @param mixed $input The input string
     * @param mixed $chars The characters to trim (optional)
     * @return array<string, mixed>
     */
    public function ltrim(mixed $input, mixed $chars = null): array
    {
        if ($chars !== null) {
            return ['$ltrim' => ['input' => $input, 'chars' => $chars]];
        }

        return ['$ltrim' => ['input' => $input]];
    }

    /**
     * RTrim string
     *
     * @param mixed $input The input string
     * @param mixed $chars The characters to trim (optional)
     * @return array<string, mixed>
     */
    public function rtrim(mixed $input, mixed $chars = null): array
    {
        if ($chars !== null) {
            return ['$rtrim' => ['input' => $input, 'chars' => $chars]];
        }

        return ['$rtrim' => ['input' => $input]];
    }

    /**
     * Date Operators
     */

    /**
     * Date from parts
     *
     * @param array<string, mixed> $parts The date parts
     * @return array<string, mixed>
     */
    public function dateFromParts(array $parts): array
    {
        return ['$dateFromParts' => $parts];
    }

    /**
     * Date from string
     *
     * @param mixed $dateString The date string
     * @param mixed $format The format (optional)
     * @param mixed $timezone The timezone (optional)
     * @param mixed $onError The on error value (optional)
     * @param mixed $onNull The on null value (optional)
     * @return array<string, mixed>
     */
    public function dateFromString(
        mixed $dateString,
        mixed $format = null,
        mixed $timezone = null,
        mixed $onError = null,
        mixed $onNull = null,
    ): array {
        $expr = ['dateString' => $dateString];
        if ($format !== null) {
            $expr['format'] = $format;
        }

        if ($timezone !== null) {
            $expr['timezone'] = $timezone;
        }

        if ($onError !== null) {
            $expr['onError'] = $onError;
        }

        if ($onNull !== null) {
            $expr['onNull'] = $onNull;
        }

        return ['$dateFromString' => $expr];
    }

    /**
     * Date to parts
     *
     * @param mixed $date The date
     * @param array<string, mixed> $parts The parts specification
     * @return array<string, mixed>
     */
    public function dateToParts(mixed $date, array $parts): array
    {
        return ['$dateToParts' => array_merge(['date' => $date], $parts)];
    }

    /**
     * Date to string
     *
     * @param mixed $date The date
     * @param mixed $format The format (optional)
     * @param mixed $timezone The timezone (optional)
     * @param mixed $onError The on error value (optional)
     * @param mixed $onNull The on null value (optional)
     * @return array<string, mixed>
     */
    public function dateToString(
        mixed $date,
        mixed $format = null,
        mixed $timezone = null,
        mixed $onError = null,
        mixed $onNull = null,
    ): array {
        $expr = ['date' => $date];
        if ($format !== null) {
            $expr['format'] = $format;
        }

        if ($timezone !== null) {
            $expr['timezone'] = $timezone;
        }

        if ($onError !== null) {
            $expr['onError'] = $onError;
        }

        if ($onNull !== null) {
            $expr['onNull'] = $onNull;
        }

        return ['$dateToString' => $expr];
    }

    /**
     * Day of month
     *
     * @param mixed $date The date
     * @param mixed $timezone The timezone (optional)
     * @return array<string, mixed>
     */
    public function dayOfMonth(mixed $date, mixed $timezone = null): array
    {
        if ($timezone !== null) {
            return ['$dayOfMonth' => ['date' => $date, 'timezone' => $timezone]];
        }

        return ['$dayOfMonth' => $date];
    }

    /**
     * Day of week
     *
     * @param mixed $date The date
     * @param mixed $timezone The timezone (optional)
     * @return array<string, mixed>
     */
    public function dayOfWeek(mixed $date, mixed $timezone = null): array
    {
        if ($timezone !== null) {
            return ['$dayOfWeek' => ['date' => $date, 'timezone' => $timezone]];
        }

        return ['$dayOfWeek' => $date];
    }

    /**
     * Day of year
     *
     * @param mixed $date The date
     * @param mixed $timezone The timezone (optional)
     * @return array<string, mixed>
     */
    public function dayOfYear(mixed $date, mixed $timezone = null): array
    {
        if ($timezone !== null) {
            return ['$dayOfYear' => ['date' => $date, 'timezone' => $timezone]];
        }

        return ['$dayOfYear' => $date];
    }

    /**
     * Year
     *
     * @param mixed $date The date
     * @param mixed $timezone The timezone (optional)
     * @return array<string, mixed>
     */
    public function year(mixed $date, mixed $timezone = null): array
    {
        if ($timezone !== null) {
            return ['$year' => ['date' => $date, 'timezone' => $timezone]];
        }

        return ['$year' => $date];
    }

    /**
     * Month
     *
     * @param mixed $date The date
     * @param mixed $timezone The timezone (optional)
     * @return array<string, mixed>
     */
    public function month(mixed $date, mixed $timezone = null): array
    {
        if ($timezone !== null) {
            return ['$month' => ['date' => $date, 'timezone' => $timezone]];
        }

        return ['$month' => $date];
    }

    /**
     * Week
     *
     * @param mixed $date The date
     * @param mixed $timezone The timezone (optional)
     * @return array<string, mixed>
     */
    public function week(mixed $date, mixed $timezone = null): array
    {
        if ($timezone !== null) {
            return ['$week' => ['date' => $date, 'timezone' => $timezone]];
        }

        return ['$week' => $date];
    }

    /**
     * Hour
     *
     * @param mixed $date The date
     * @param mixed $timezone The timezone (optional)
     * @return array<string, mixed>
     */
    public function hour(mixed $date, mixed $timezone = null): array
    {
        if ($timezone !== null) {
            return ['$hour' => ['date' => $date, 'timezone' => $timezone]];
        }

        return ['$hour' => $date];
    }

    /**
     * Minute
     *
     * @param mixed $date The date
     * @param mixed $timezone The timezone (optional)
     * @return array<string, mixed>
     */
    public function minute(mixed $date, mixed $timezone = null): array
    {
        if ($timezone !== null) {
            return ['$minute' => ['date' => $date, 'timezone' => $timezone]];
        }

        return ['$minute' => $date];
    }

    /**
     * Second
     *
     * @param mixed $date The date
     * @param mixed $timezone The timezone (optional)
     * @return array<string, mixed>
     */
    public function second(mixed $date, mixed $timezone = null): array
    {
        if ($timezone !== null) {
            return ['$second' => ['date' => $date, 'timezone' => $timezone]];
        }

        return ['$second' => $date];
    }

    /**
     * Millisecond
     *
     * @param mixed $date The date
     * @param mixed $timezone The timezone (optional)
     * @return array<string, mixed>
     */
    public function millisecond(mixed $date, mixed $timezone = null): array
    {
        if ($timezone !== null) {
            return ['$millisecond' => ['date' => $date, 'timezone' => $timezone]];
        }

        return ['$millisecond' => $date];
    }

    /**
     * Date difference
     *
     * @param mixed $startDate The start date
     * @param mixed $endDate The end date
     * @param mixed $unit The unit
     * @param mixed $timezone The timezone (optional)
     * @return array<string, mixed>
     */
    public function dateDiff(mixed $startDate, mixed $endDate, mixed $unit, mixed $timezone = null): array
    {
        $expr = ['startDate' => $startDate, 'endDate' => $endDate, 'unit' => $unit];
        if ($timezone !== null) {
            $expr['timezone'] = $timezone;
        }

        return ['$dateDiff' => $expr];
    }

    /**
     * Date add
     *
     * @param mixed $startDate The start date
     * @param mixed $amount The amount
     * @param mixed $unit The unit
     * @return array<string, mixed>
     */
    public function dateAdd(mixed $startDate, mixed $amount, mixed $unit): array
    {
        return ['$dateAdd' => ['startDate' => $startDate, 'amount' => $amount, 'unit' => $unit]];
    }

    /**
     * Date subtract
     *
     * @param mixed $startDate The start date
     * @param mixed $amount The amount
     * @param mixed $unit The unit
     * @return array<string, mixed>
     */
    public function dateSubtract(mixed $startDate, mixed $amount, mixed $unit): array
    {
        return ['$dateSubtract' => ['startDate' => $startDate, 'amount' => $amount, 'unit' => $unit]];
    }

    /**
     * ISO day of week
     *
     * @param mixed $date The date
     * @param mixed $timezone The timezone (optional)
     * @return array<string, mixed>
     */
    public function isoDayOfWeek(mixed $date, mixed $timezone = null): array
    {
        if ($timezone !== null) {
            return ['$isoDayOfWeek' => ['date' => $date, 'timezone' => $timezone]];
        }

        return ['$isoDayOfWeek' => $date];
    }

    /**
     * ISO week
     *
     * @param mixed $date The date
     * @param mixed $timezone The timezone (optional)
     * @return array<string, mixed>
     */
    public function isoWeek(mixed $date, mixed $timezone = null): array
    {
        if ($timezone !== null) {
            return ['$isoWeek' => ['date' => $date, 'timezone' => $timezone]];
        }

        return ['$isoWeek' => $date];
    }

    /**
     * ISO week year
     *
     * @param mixed $date The date
     * @param mixed $timezone The timezone (optional)
     * @return array<string, mixed>
     */
    public function isoWeekYear(mixed $date, mixed $timezone = null): array
    {
        if ($timezone !== null) {
            return ['$isoWeekYear' => ['date' => $date, 'timezone' => $timezone]];
        }

        return ['$isoWeekYear' => $date];
    }

    /**
     * Type Operators
     */

    /**
     * Convert to boolean
     *
     * @param mixed $input The input
     * @param mixed $onError The on error value (optional)
     * @param mixed $onNull The on null value (optional)
     * @return array<string, mixed>
     */
    public function toBool(mixed $input, mixed $onError = null, mixed $onNull = null): array
    {
        $expr = ['input' => $input];
        if ($onError !== null) {
            $expr['onError'] = $onError;
        }

        if ($onNull !== null) {
            $expr['onNull'] = $onNull;
        }

        return ['$toBool' => $expr];
    }

    /**
     * Convert to date
     *
     * @param mixed $input The input
     * @param mixed $onError The on error value (optional)
     * @param mixed $onNull The on null value (optional)
     * @return array<string, mixed>
     */
    public function toDate(mixed $input, mixed $onError = null, mixed $onNull = null): array
    {
        $expr = ['input' => $input];
        if ($onError !== null) {
            $expr['onError'] = $onError;
        }

        if ($onNull !== null) {
            $expr['onNull'] = $onNull;
        }

        return ['$toDate' => $expr];
    }

    /**
     * Convert to decimal
     *
     * @param mixed $input The input
     * @param mixed $onError The on error value (optional)
     * @param mixed $onNull The on null value (optional)
     * @return array<string, mixed>
     */
    public function toDecimal(mixed $input, mixed $onError = null, mixed $onNull = null): array
    {
        $expr = ['input' => $input];
        if ($onError !== null) {
            $expr['onError'] = $onError;
        }

        if ($onNull !== null) {
            $expr['onNull'] = $onNull;
        }

        return ['$toDecimal' => $expr];
    }

    /**
     * Convert to double
     *
     * @param mixed $input The input
     * @param mixed $onError The on error value (optional)
     * @param mixed $onNull The on null value (optional)
     * @return array<string, mixed>
     */
    public function toDouble(mixed $input, mixed $onError = null, mixed $onNull = null): array
    {
        $expr = ['input' => $input];
        if ($onError !== null) {
            $expr['onError'] = $onError;
        }

        if ($onNull !== null) {
            $expr['onNull'] = $onNull;
        }

        return ['$toDouble' => $expr];
    }

    /**
     * Convert to int
     *
     * @param mixed $input The input
     * @param mixed $onError The on error value (optional)
     * @param mixed $onNull The on null value (optional)
     * @return array<string, mixed>
     */
    public function toInt(mixed $input, mixed $onError = null, mixed $onNull = null): array
    {
        $expr = ['input' => $input];
        if ($onError !== null) {
            $expr['onError'] = $onError;
        }

        if ($onNull !== null) {
            $expr['onNull'] = $onNull;
        }

        return ['$toInt' => $expr];
    }

    /**
     * Convert to long
     *
     * @param mixed $input The input
     * @param mixed $onError The on error value (optional)
     * @param mixed $onNull The on null value (optional)
     * @return array<string, mixed>
     */
    public function toLong(mixed $input, mixed $onError = null, mixed $onNull = null): array
    {
        $expr = ['input' => $input];
        if ($onError !== null) {
            $expr['onError'] = $onError;
        }

        if ($onNull !== null) {
            $expr['onNull'] = $onNull;
        }

        return ['$toLong' => $expr];
    }

    /**
     * Convert to object ID
     *
     * @param mixed $input The input
     * @param mixed $onError The on error value (optional)
     * @param mixed $onNull The on null value (optional)
     * @return array<string, mixed>
     */
    public function toObjectId(mixed $input, mixed $onError = null, mixed $onNull = null): array
    {
        $expr = ['input' => $input];
        if ($onError !== null) {
            $expr['onError'] = $onError;
        }

        if ($onNull !== null) {
            $expr['onNull'] = $onNull;
        }

        return ['$toObjectId' => $expr];
    }

    /**
     * Convert to string
     *
     * @param mixed $input The input
     * @param mixed $onError The on error value (optional)
     * @param mixed $onNull The on null value (optional)
     * @return array<string, mixed>
     */
    public function toString(mixed $input, mixed $onError = null, mixed $onNull = null): array
    {
        $expr = ['input' => $input];
        if ($onError !== null) {
            $expr['onError'] = $onError;
        }

        if ($onNull !== null) {
            $expr['onNull'] = $onNull;
        }

        return ['$toString' => $expr];
    }

    /**
     * Type
     *
     * @param mixed $expression The expression
     * @return array<string, mixed>
     */
    public function type(mixed $expression): array
    {
        return ['$type' => $expression];
    }

    /**
     * Accumulator Operators (for $group stage)
     */

    /**
     * Sum accumulator
     *
     * @param mixed $expression The expression
     * @return array<string, mixed>
     */
    public function sum(mixed $expression): array
    {
        return ['$sum' => $expression];
    }

    /**
     * Average accumulator
     *
     * @param mixed $expression The expression
     * @return array<string, mixed>
     */
    public function avg(mixed $expression): array
    {
        return ['$avg' => $expression];
    }

    /**
     * First accumulator
     *
     * @param mixed $expression The expression
     * @return array<string, mixed>
     */
    public function first(mixed $expression): array
    {
        return ['$first' => $expression];
    }

    /**
     * Last accumulator
     *
     * @param mixed $expression The expression
     * @return array<string, mixed>
     */
    public function last(mixed $expression): array
    {
        return ['$last' => $expression];
    }

    /**
     * Max accumulator
     *
     * @param mixed $expression The expression
     * @return array<string, mixed>
     */
    public function max(mixed $expression): array
    {
        return ['$max' => $expression];
    }

    /**
     * Min accumulator
     *
     * @param mixed $expression The expression
     * @return array<string, mixed>
     */
    public function min(mixed $expression): array
    {
        return ['$min' => $expression];
    }

    /**
     * Push accumulator
     *
     * @param mixed $expression The expression
     * @return array<string, mixed>
     */
    public function push(mixed $expression): array
    {
        return ['$push' => $expression];
    }

    /**
     * Add to set accumulator
     *
     * @param mixed $expression The expression
     * @return array<string, mixed>
     */
    public function addToSet(mixed $expression): array
    {
        return ['$addToSet' => $expression];
    }

    /**
     * Standard deviation population
     *
     * @param mixed $expression The expression
     * @return array<string, mixed>
     */
    public function stdDevPop(mixed $expression): array
    {
        return ['$stdDevPop' => $expression];
    }

    /**
     * Standard deviation sample
     *
     * @param mixed $expression The expression
     * @return array<string, mixed>
     */
    public function stdDevSamp(mixed $expression): array
    {
        return ['$stdDevSamp' => $expression];
    }

    /**
     * Merge objects
     *
     * @param mixed $expression The expression
     * @return array<string, mixed>
     */
    public function mergeObjects(mixed $expression): array
    {
        return ['$mergeObjects' => $expression];
    }

    /**
     * Window Operators (for $setWindowFields stage)
     */

    /**
     * Document number
     *
     * @return array<string, mixed>
     */
    public function documentNumber(): array
    {
        return ['$documentNumber' => (object)[]];
    }

    /**
     * Rank
     *
     * @return array<string, mixed>
     */
    public function rank(): array
    {
        return ['$rank' => (object)[]];
    }

    /**
     * Dense rank
     *
     * @return array<string, mixed>
     */
    public function denseRank(): array
    {
        return ['$denseRank' => (object)[]];
    }

    /**
     * Shift
     *
     * @param mixed $output The output expression
     * @param mixed $by The shift amount
     * @param mixed $default The default value (optional)
     * @return array<string, mixed>
     */
    public function shift(mixed $output, mixed $by, mixed $default = null): array
    {
        $expr = ['output' => $output, 'by' => $by];
        if ($default !== null) {
            $expr['default'] = $default;
        }

        return ['$shift' => $expr];
    }

    /**
     * Derivative
     *
     * @param mixed $input The input expression
     * @param mixed $unit The unit (optional)
     * @return array<string, mixed>
     */
    public function derivative(mixed $input, mixed $unit = null): array
    {
        if ($unit !== null) {
            return ['$derivative' => ['input' => $input, 'unit' => $unit]];
        }

        return ['$derivative' => ['input' => $input]];
    }

    /**
     * Integral
     *
     * @param mixed $input The input expression
     * @param mixed $unit The unit (optional)
     * @return array<string, mixed>
     */
    public function integral(mixed $input, mixed $unit = null): array
    {
        if ($unit !== null) {
            return ['$integral' => ['input' => $input, 'unit' => $unit]];
        }

        return ['$integral' => ['input' => $input]];
    }

    /**
     * Covariance population
     *
     * @param mixed $x The x expression
     * @param mixed $y The y expression
     * @return array<string, mixed>
     */
    public function covarPop(mixed $x, mixed $y): array
    {
        return ['$covarPop' => ['x' => $x, 'y' => $y]];
    }

    /**
     * Covariance sample
     *
     * @param mixed $x The x expression
     * @param mixed $y The y expression
     * @return array<string, mixed>
     */
    public function covarSamp(mixed $x, mixed $y): array
    {
        return ['$covarSamp' => ['x' => $x, 'y' => $y]];
    }

    /**
     * Exp moving average
     *
     * @param mixed $input The input expression
     * @param mixed $N The N value
     * @param mixed $alpha The alpha value (optional)
     * @return array<string, mixed>
     */
    public function expMovingAvg(mixed $input, mixed $N, mixed $alpha = null): array
    {
        $expr = ['input' => $input, 'N' => $N];
        if ($alpha !== null) {
            $expr['alpha'] = $alpha;
        }

        return ['$expMovingAvg' => $expr];
    }

    /**
     * Linear fill
     *
     * @return array<string, mixed>
     */
    public function linearFill(): array
    {
        return ['$linearFill' => (object)[]];
    }

    /**
     * Locf (Last Observation Carried Forward)
     *
     * @return array<string, mixed>
     */
    public function locf(): array
    {
        return ['$locf' => (object)[]];
    }
}

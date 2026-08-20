<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Type;

use Crustum\Mongo\Database\Driver\MongoDriver;
use InvalidArgumentException;
use Stringable;

/**
 * String type converter
 *
 * Use to convert string data between PHP and MongoDB
 *
 * @ported-from \Cake\Database\Type\StringType
 */
class StringType extends BaseType
{
    /**
     * Convert string data into the database format
     *
     * @param mixed $value The value to convert
     * @param \Crustum\Mongo\Database\Driver\MongoDriver $driver The driver instance to convert with
     * @return string|null
     */
    public function toDatabase(mixed $value, MongoDriver $driver): ?string
    {
        if ($value === null || is_string($value)) {
            return $value;
        }

        if ($value instanceof Stringable) {
            return (string)$value;
        }

        if (is_scalar($value)) {
            return (string)$value;
        }

        throw new InvalidArgumentException(
            sprintf(
                'Cannot convert value `%s` of type `%s` to string',
                print_r($value, true),
                get_debug_type($value),
            ),
        );
    }

    /**
     * Convert string values to PHP strings
     *
     * @param mixed $value The value to convert
     * @param \Crustum\Mongo\Database\Driver\MongoDriver $driver The driver instance to convert with
     * @return string|null
     */
    public function toPHP(mixed $value, MongoDriver $driver): ?string
    {
        if ($value === null) {
            return null;
        }

        return (string)$value;
    }

    /**
     * Marshals request data into PHP strings
     *
     * @param mixed $value The value to convert
     * @return string|null Converted value
     */
    public function marshal(mixed $value): ?string
    {
        if ($value === null || is_array($value)) {
            return null;
        }

        return (string)$value;
    }
}

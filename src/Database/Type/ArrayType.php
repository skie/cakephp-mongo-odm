<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Type;

use Crustum\Mongo\Database\Driver\MongoDriver;

/**
 * Array type converter
 *
 * Use to convert array data between PHP and MongoDB
 */
class ArrayType extends BaseType
{
    /**
     * Convert array data into the database format
     *
     * @param mixed $value The value to convert
     * @param \Crustum\Mongo\Database\Driver\MongoDriver $driver The driver instance to convert with
     * @return array<mixed>|null
     */
    public function toDatabase(mixed $value, MongoDriver $driver): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        return [$value];
    }

    /**
     * Convert array values to PHP arrays
     *
     * @param mixed $value The value to convert
     * @param \Crustum\Mongo\Database\Driver\MongoDriver $driver The driver instance to convert with
     * @return array<mixed>|null
     */
    public function toPHP(mixed $value, MongoDriver $driver): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        return [$value];
    }

    /**
     * Marshals request data into PHP arrays
     *
     * @param mixed $value The value to convert
     * @return array<mixed>|null Converted value
     */
    public function marshal(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        return [$value];
    }
}

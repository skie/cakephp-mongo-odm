<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Type;

use Crustum\Mongo\Database\Driver\MongoDriver;
use InvalidArgumentException;
use MongoDB\BSON\Int64;

/**
 * Int64 type converter
 *
 * Use to convert 64-bit integer data between PHP and MongoDB.
 *
 * Implements `Incrementable` and `Versionable` so int64 fields can be diffed
 * into `$inc` updates and bumped for optimistic locking.
 *
 * @inspired-by \Doctrine\ODM\MongoDB\Types\Int64Type
 */
class Int64Type extends BaseType implements Incrementable, Versionable
{
    /**
     * @inheritDoc
     */
    public function diff(mixed $old, mixed $new): ?int
    {
        if ($old === null || $new === null) {
            return null;
        }

        return (int)$new - (int)$old;
    }

    /**
     * @inheritDoc
     */
    public function getNextVersion(mixed $current): int
    {
        return (int)$current + 1;
    }

    /**
     * Convert int64 data into the database format
     *
     * @param mixed $value The value to convert
     * @param \Crustum\Mongo\Database\Driver\MongoDriver $driver The driver instance to convert with
     * @return \MongoDB\BSON\Int64|null
     */
    public function toDatabase(mixed $value, MongoDriver $driver): ?Int64
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Int64) {
            return $value;
        }

        if (is_int($value) || is_string($value)) {
            return new Int64($value);
        }

        throw new InvalidArgumentException(
            sprintf(
                'Cannot convert value `%s` of type `%s` to int64',
                print_r($value, true),
                get_debug_type($value),
            ),
        );
    }

    /**
     * Convert int64 values to PHP integers
     *
     * @param mixed $value The value to convert
     * @param \Crustum\Mongo\Database\Driver\MongoDriver $driver The driver instance to convert with
     * @return int|null
     */
    public function toPHP(mixed $value, MongoDriver $driver): ?int
    {
        if ($value === null) {
            return null;
        }

        return (int)$value;
    }

    /**
     * Marshals request data into PHP integers
     *
     * @param mixed $value The value to convert
     * @return int|null Converted value
     */
    public function marshal(mixed $value): ?int
    {
        if ($value === '' || !is_numeric($value)) {
            return null;
        }

        return (int)$value;
    }
}

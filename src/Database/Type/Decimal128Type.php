<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Type;

use Crustum\Mongo\Database\Driver\MongoDriver;
use MongoDB\BSON\Decimal128;

/**
 * Decimal128 type converter
 *
 * Use to convert Decimal128 data between PHP and MongoDB
 *
 * @inspired-by \Doctrine\ODM\MongoDB\Types\Decimal128Type
 */
class Decimal128Type extends BaseType
{
    /**
     * Convert Decimal128 data into the database format
     *
     * @param mixed $value The value to convert
     * @param \Crustum\Mongo\Database\Driver\MongoDriver $driver The driver instance to convert with
     * @return \MongoDB\BSON\Decimal128|null
     */
    public function toDatabase(mixed $value, MongoDriver $driver): ?Decimal128
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof Decimal128) {
            return $value;
        }

        return new Decimal128((string)$value);
    }

    /**
     * Convert Decimal128 values to PHP strings
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
     * Marshals request data into PHP Decimal128 strings
     *
     * @param mixed $value The value to convert
     * @return string|null Converted value
     */
    public function marshal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (string)$value;
        }

        return $value;
    }
}

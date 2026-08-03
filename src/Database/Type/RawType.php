<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Type;

use Crustum\Mongo\Database\Driver\MongoDriver;

/**
 * Raw type converter
 *
 * Use for raw BSON values (no conversion)
 */
class RawType extends BaseType
{
    /**
     * Convert raw data into the database format
     *
     * @param mixed $value The value to convert
     * @param \Crustum\Mongo\Database\Driver\MongoDriver $driver The driver instance to convert with
     * @return mixed
     */
    public function toDatabase(mixed $value, MongoDriver $driver): mixed
    {
        return $value;
    }

    /**
     * Convert raw values to PHP
     *
     * @param mixed $value The value to convert
     * @param \Crustum\Mongo\Database\Driver\MongoDriver $driver The driver instance to convert with
     * @return mixed
     */
    public function toPHP(mixed $value, MongoDriver $driver): mixed
    {
        return $value;
    }

    /**
     * Marshals request data
     *
     * @param mixed $value The value to convert
     * @return mixed Converted value
     */
    public function marshal(mixed $value): mixed
    {
        return $value;
    }
}

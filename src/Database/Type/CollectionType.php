<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Type;

use Crustum\Mongo\Database\Driver\MongoDriver;

/**
 * Collection type converter
 *
 * Use to convert collection (indexed array) data between PHP and MongoDB
 *
 * @inspired-by \Doctrine\ODM\MongoDB\Types\CollectionType
 */
class CollectionType extends BaseType
{
    /**
     * Convert collection data into the database format
     *
     * @param mixed $value The value to convert
     * @param \Crustum\Mongo\Database\Driver\MongoDriver $driver The driver instance to convert with
     * @return array<int, mixed>|null
     */
    public function toDatabase(mixed $value, MongoDriver $driver): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return array_values($value);
        }

        return [$value];
    }

    /**
     * Convert collection values to PHP arrays
     *
     * @param mixed $value The value to convert
     * @param \Crustum\Mongo\Database\Driver\MongoDriver $driver The driver instance to convert with
     * @return array<int, mixed>|null
     */
    public function toPHP(mixed $value, MongoDriver $driver): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return array_values($value);
        }

        return [$value];
    }

    /**
     * Marshals request data into PHP arrays
     *
     * @param mixed $value The value to convert
     * @return array<int, mixed>|null Converted value
     */
    public function marshal(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return array_values($value);
        }

        return [$value];
    }
}

<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Type;

use Crustum\Mongo\Database\Driver\MongoDriver;
use Override;
use stdClass;

/**
 * Hash type converter
 *
 * Use to convert hash map (associative array) data between PHP and MongoDB
 *
 * @inspired-by \Doctrine\ODM\MongoDB\Types\HashType
 */
class HashType extends BaseType
{
    /**
     * Convert hash data into the database format
     *
     * @param mixed $value The value to convert
     * @param \Crustum\Mongo\Database\Driver\MongoDriver $driver The driver instance to convert with
     * @return \stdClass|null
     */
    public function toDatabase(mixed $value, MongoDriver $driver): ?stdClass
    {
        if ($value === null) {
            return null;
        }

        if (is_object($value)) {
            return (object)(array)$value;
        }

        if (is_array($value)) {
            return (object)$value;
        }

        return null;
    }

    /**
     * Convert hash values to PHP arrays
     *
     * @param mixed $value The value to convert
     * @param \Crustum\Mongo\Database\Driver\MongoDriver $driver The driver instance to convert with
     * @return array<string, mixed>|null
     */
    public function toPHP(mixed $value, MongoDriver $driver): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_object($value)) {
            return (array)$value;
        }

        if (is_array($value)) {
            return $value;
        }

        return null;
    }

    /**
     * Marshals request data into PHP arrays
     *
     * @param mixed $value The value to convert
     * @return array<string, mixed>|null Converted value
     */
    #[Override]
    public function marshal(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            return (array)$value;
        }

        return null;
    }
}

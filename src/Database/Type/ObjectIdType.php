<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Type;

use Crustum\Mongo\Database\Driver\MongoDriver;
use Exception;
use InvalidArgumentException;
use MongoDB\BSON\ObjectId;

/**
 * ObjectId type converter
 *
 * Use to convert ObjectId data between PHP and MongoDB
 */
class ObjectIdType extends BaseType
{
    /**
     * Convert ObjectId data into the database format
     *
     * @param mixed $value The value to convert
     * @param \Crustum\Mongo\Database\Driver\MongoDriver $driver The driver instance to convert with
     * @return \MongoDB\BSON\ObjectId|null
     */
    public function toDatabase(mixed $value, MongoDriver $driver): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof ObjectId) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[0-9a-f]{24}$/i', $value)) {
            return new ObjectId($value);
        }

        // Non-hex values (e.g. integer or string foreign keys) pass through so
        // existence lookups simply match nothing instead of failing to encode.
        return $value;
    }

    /**
     * Convert ObjectId values to PHP strings
     *
     * Non-ObjectId values pass through unchanged so integer foreign keys keep
     * their type.
     *
     * @param mixed $value The value to convert
     * @param \Crustum\Mongo\Database\Driver\MongoDriver $driver The driver instance to convert with
     * @return mixed|string|null
     */
    public function toPHP(mixed $value, MongoDriver $driver): mixed
    {
        if ($value instanceof ObjectId) {
            return (string)$value;
        }

        return $value;
    }

    /**
     * Marshals request data into PHP ObjectId strings
     *
     * @param mixed $value The value to convert
     * @return string|null Converted value
     */
    public function marshal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }

        if (is_string($value)) {
            try {
                new ObjectId($value);

                return $value;
            } catch (Exception) {
                return $value;
            }
        }

        return is_scalar($value) ? (string)$value : null;
    }

    /**
     * @inheritDoc
     */
    public function newId(): ObjectId
    {
        return new ObjectId();
    }
}

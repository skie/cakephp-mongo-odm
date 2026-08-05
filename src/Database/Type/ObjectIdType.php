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
    public function toDatabase(mixed $value, MongoDriver $driver): ?ObjectId
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof ObjectId) {
            return $value;
        }

        try {
            return new ObjectId((string)$value);
        } catch (Exception $exception) {
            throw new InvalidArgumentException(
                sprintf('Cannot convert value "%s" to ObjectId', $value),
                0,
                $exception,
            );
        }
    }

    /**
     * Convert ObjectId values to PHP strings
     *
     * Non-ObjectId values pass through unchanged so integer foreign keys keep
     * their type.
     *
     * @param mixed $value The value to convert
     * @param \Crustum\Mongo\Database\Driver\MongoDriver $driver The driver instance to convert with
     * @return string|mixed|null
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

        if (is_string($value)) {
            try {
                new ObjectId($value);

                return $value;
            } catch (Exception) {
                return $value;
            }
        }

        return $value;
    }

    /**
     * @inheritDoc
     */
    public function newId(): ObjectId
    {
        return new ObjectId();
    }
}

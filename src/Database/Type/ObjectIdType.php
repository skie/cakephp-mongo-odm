<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Type;

use Crustum\Mongo\Database\Driver\MongoDriver;
use Exception;
use InvalidArgumentException;
use MongoDB\BSON\ObjectId;
use Override;

/**
 * ObjectId type converter
 *
 * Use to convert ObjectId data between PHP and MongoDB
 *
 * @inspired-by \Doctrine\ODM\MongoDB\Types\ObjectIdType
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

        if (is_string($value) && self::isHex($value)) {
            return new ObjectId($value);
        }

        if (is_string($value)) {
            throw new InvalidArgumentException(
                sprintf('Value is not a valid MongoDB ObjectId: %s', $value),
            );
        }

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
    #[Override]
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
                return null;
            }
        }

        return is_scalar($value) ? (string)$value : null;
    }

    /**
     * Returns whether a string is a 24-character ObjectId hex value.
     *
     * @param string $value The candidate string.
     * @return bool
     */
    public static function isHex(string $value): bool
    {
        return preg_match('/^[0-9a-f]{24}$/i', $value) === 1;
    }

    /**
     * Converts a 24-character hex string to ObjectId, otherwise returns the value.
     *
     * @param mixed $value The raw value.
     * @return mixed
     */
    public static function tryFrom(mixed $value): mixed
    {
        if ($value instanceof ObjectId) {
            return $value;
        }

        if (!is_string($value) || !self::isHex($value)) {
            return $value;
        }

        try {
            return new ObjectId($value);
        } catch (Exception) {
            return $value;
        }
    }

    /**
     * @inheritDoc
     */
    public function newId(): ObjectId
    {
        return new ObjectId();
    }
}

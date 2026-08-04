<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Type;

use Cake\Utility\Text;
use Crustum\Mongo\Database\Driver\MongoDriver;

/**
 * UUID type converter
 *
 * Use to convert string-form UUIDs between PHP and MongoDB. Mirrors
 * `Cake\Database\Type\UuidType`; the binary form is handled by
 * `BinaryUuidType`.
 */
class UuidType extends StringType
{
    /**
     * Convert UUID data into the database format
     *
     * Empty and falsy values are normalized to null.
     *
     * @param mixed $value The value to convert
     * @param \Crustum\Mongo\Database\Driver\MongoDriver $driver The driver instance to convert with
     * @return string|null
     */
    public function toDatabase(mixed $value, MongoDriver $driver): ?string
    {
        if (in_array($value, [null, '', false], true)) {
            return null;
        }

        return parent::toDatabase($value, $driver);
    }

    /**
     * Generates a new UUID v4 string
     *
     * @return string A new UUID value
     */
    public function newId(): string
    {
        return Text::uuid();
    }

    /**
     * Marshals request data into a PHP string
     *
     * @param mixed $value The value to convert
     * @return string|null Converted value
     */
    public function marshal(mixed $value): ?string
    {
        if ($value === null || $value === '' || is_array($value)) {
            return null;
        }

        return (string)$value;
    }
}

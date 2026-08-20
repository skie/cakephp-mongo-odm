<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Type;

use Crustum\Mongo\Database\Driver\MongoDriver;
use InvalidArgumentException;
use MongoDB\BSON\Timestamp;
use Override;

/**
 * Timestamp type converter
 *
 * Use to convert Timestamp data between PHP and MongoDB
 *
 * @inspired-by \Doctrine\ODM\MongoDB\Types\TimestampType
 */
class TimestampType extends BaseType
{
    /**
     * Convert timestamp data into the database format
     *
     * @param mixed $value The value to convert
     * @param \Crustum\Mongo\Database\Driver\MongoDriver $driver The driver instance to convert with
     * @return \MongoDB\BSON\Timestamp|null
     */
    public function toDatabase(mixed $value, MongoDriver $driver): ?Timestamp
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Timestamp) {
            return $value;
        }

        if (is_array($value) && count($value) === 2) {
            return new Timestamp($value[0], $value[1]);
        }

        if (is_numeric($value)) {
            return new Timestamp((int)$value, 0);
        }

        throw new InvalidArgumentException(
            sprintf('Cannot convert %s to Timestamp', gettype($value)),
        );
    }

    /**
     * Convert timestamp values to PHP array
     *
     * @param mixed $value The value to convert
     * @param \Crustum\Mongo\Database\Driver\MongoDriver $driver The driver instance to convert with
     * @return array<int, int>|null
     */
    public function toPHP(mixed $value, MongoDriver $driver): ?array
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Timestamp) {
            return [$value->getTimestamp(), $value->getIncrement()];
        }

        if (is_array($value) && count($value) === 2) {
            return $value;
        }

        return null;
    }

    /**
     * Marshals request data into PHP timestamp array
     *
     * @param mixed $value The value to convert
     * @return array<int, int>|null Converted value
     */
    #[Override]
    public function marshal(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value) && count($value) === 2) {
            return [(int)$value[0], (int)($value[1] ?? 0)];
        }

        if (is_numeric($value)) {
            return [(int)$value, 0];
        }

        return null;
    }
}

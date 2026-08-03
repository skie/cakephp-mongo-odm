<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Type;

use Crustum\Mongo\Database\Driver\MongoDriver;
use DateTime;
use DateTimeInterface;
use Exception;
use InvalidArgumentException;
use MongoDB\BSON\UTCDateTime;

/**
 * Date type converter
 *
 * Use to convert date data between PHP and MongoDB
 */
class DateType extends BaseType
{
    /**
     * Convert date data into the database format
     *
     * @param mixed $value The value to convert
     * @param \Crustum\Mongo\Database\Driver\MongoDriver $driver The driver instance to convert with
     * @return \MongoDB\BSON\UTCDateTime|null
     */
    public function toDatabase(mixed $value, MongoDriver $driver): ?UTCDateTime
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof UTCDateTime) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return new UTCDateTime($value);
        }

        if (is_numeric($value)) {
            return new UTCDateTime((int)$value);
        }

        if (is_string($value)) {
            try {
                $date = new DateTime($value);

                return new UTCDateTime($date);
            } catch (Exception $e) {
                throw new InvalidArgumentException(
                    sprintf('Cannot convert "%s" to date', $value),
                    0,
                    $e,
                );
            }
        }

        throw new InvalidArgumentException(
            sprintf('Cannot convert %s to date', gettype($value)),
        );
    }

    /**
     * Convert date values to PHP DateTimeInterface
     *
     * @param mixed $value The value to convert
     * @param \Crustum\Mongo\Database\Driver\MongoDriver $driver The driver instance to convert with
     * @return \DateTimeInterface|null
     */
    public function toPHP(mixed $value, MongoDriver $driver): ?DateTimeInterface
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof UTCDateTime) {
            return $value->toDateTime();
        }

        if ($value instanceof DateTimeInterface) {
            return $value;
        }

        if (is_numeric($value)) {
            $date = new DateTime();
            $date->setTimestamp((int)$value);

            return $date;
        }

        if (is_string($value)) {
            try {
                return new DateTime($value);
            } catch (Exception) {
                return null;
            }
        }

        return null;
    }

    /**
     * Marshals request data into PHP DateTime
     *
     * @param mixed $value The value to convert
     * @return \DateTimeInterface|null Converted value
     */
    public function marshal(mixed $value): ?DateTimeInterface
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value;
        }

        if (is_string($value)) {
            try {
                return new DateTime($value);
            } catch (Exception) {
                return null;
            }
        }

        return null;
    }
}

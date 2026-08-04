<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Type;

use Crustum\Mongo\Database\Driver\MongoDriver;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Time type converter
 *
 * Use to convert time-of-day values between PHP and MongoDB. MongoDB has no
 * dedicated time-only BSON type, so values are stored as `H:i:s` strings.
 * Mirrors `Cake\Database\Type\TimeType`.
 */
class TimeType extends BaseType
{
    /**
     * The PHP time format used when converting to string
     *
     * @var string
     */
    protected string $format = 'H:i:s';

    /**
     * Convert time data into the database format
     *
     * @param mixed $value The value to convert
     * @param \Crustum\Mongo\Database\Driver\MongoDriver $driver The driver instance to convert with
     * @return string|null
     */
    public function toDatabase(mixed $value, MongoDriver $driver): ?string
    {
        if ($value === null || is_string($value)) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format($this->format);
        }

        throw new InvalidArgumentException(
            sprintf(
                'Cannot convert value `%s` of type `%s` to time',
                print_r($value, true),
                get_debug_type($value),
            ),
        );
    }

    /**
     * Convert time values to PHP DateTimeInterface
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

        if ($value instanceof DateTimeInterface) {
            return $value;
        }

        $date = \DateTimeImmutable::createFromFormat('!H:i:s', (string)$value);
        if ($date === false) {
            return null;
        }

        return $date;
    }

    /**
     * Marshals request data into a PHP DateTimeInterface
     *
     * Accepts a time string, an array of `hour`, `minute`, `second` parts or a
     * date/time instance.
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
            $date = \DateTimeImmutable::createFromFormat('!H:i:s', $value);
            if ($date === false) {
                return null;
            }

            return $date;
        }

        if (is_array($value)) {
            $value += ['hour' => null, 'minute' => null, 'second' => 0];
            if (
                !is_numeric($value['hour']) ||
                !is_numeric($value['minute']) ||
                !is_numeric($value['second'])
            ) {
                return null;
            }

            $format = sprintf(
                '%02d:%02d:%02d',
                (int)$value['hour'],
                (int)$value['minute'],
                (int)$value['second'],
            );

            $date = \DateTimeImmutable::createFromFormat('!H:i:s', $format);
            if ($date === false) {
                return null;
            }

            return $date;
        }

        return null;
    }
}

<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Type;

use Crustum\Mongo\Database\Driver\MongoDriver;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Date immutable type converter
 *
 * Use to convert date data between PHP and MongoDB, always returning
 * `DateTimeImmutable` instances. Mirrors `Doctrine\ODM\MongoDB\Types\DateImmutableType`.
 */
class DateImmutableType extends DateType
{
    /**
     * Convert date values to PHP DateTimeImmutable
     *
     * @param mixed $value The value to convert
     * @param \Crustum\Mongo\Database\Driver\MongoDriver $driver The driver instance to convert with
     * @return \DateTimeImmutable|null
     */
    public function toPHP(mixed $value, MongoDriver $driver): ?DateTimeImmutable
    {
        $date = parent::toPHP($value, $driver);
        if (!$date instanceof DateTimeInterface) {
            return null;
        }

        if ($date instanceof DateTimeImmutable) {
            return $date;
        }

        return DateTimeImmutable::createFromMutable($date);
    }

    /**
     * Marshals request data into PHP DateTimeImmutable
     *
     * @param mixed $value The value to convert
     * @return \DateTimeImmutable|null Converted value
     */
    public function marshal(mixed $value): ?DateTimeImmutable
    {
        $result = parent::marshal($value);
        if (!$result instanceof DateTimeInterface) {
            return null;
        }

        if ($result instanceof DateTimeImmutable) {
            return $result;
        }

        return DateTimeImmutable::createFromMutable($result);
    }
}

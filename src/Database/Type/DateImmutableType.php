<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Type;

use Cake\I18n\DateTime as CakeDateTime;
use Crustum\Mongo\Database\Driver\MongoDriver;

/**
 * Date immutable type converter
 *
 * Use to convert date data between PHP and MongoDB. `Cake\I18n\DateTime`
 * extends `DateTimeImmutable`, so all instances are immutable.
 *
 * @inspired-by \Doctrine\ODM\MongoDB\Types\DateImmutableType
 */
class DateImmutableType extends DateType
{
    /**
     * Convert date values to PHP DateTimeImmutable
     *
     * @param mixed $value The value to convert
     * @param \Crustum\Mongo\Database\Driver\MongoDriver $driver The driver instance to convert with
     * @return \Cake\I18n\DateTime|null
     */
    public function toPHP(mixed $value, MongoDriver $driver): ?CakeDateTime
    {
        return parent::toPHP($value, $driver);
    }

    /**
     * Marshals request data into PHP DateTimeImmutable
     *
     * @param mixed $value The value to convert
     * @return \Cake\I18n\DateTime|null Converted value
     */
    public function marshal(mixed $value): ?CakeDateTime
    {
        return parent::marshal($value);
    }
}

<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Type;

use Crustum\Mongo\Database\Driver\MongoDriver;
use InvalidArgumentException;
use MongoDB\BSON\Binary;
use Override;

/**
 * Binary type converter
 *
 * Use to convert Binary data between PHP and MongoDB
 *
 * @inspired-by \Doctrine\ODM\MongoDB\Types\BinDataType
 */
class BinaryType extends BaseType
{
    /**
     * Binary subtype
     *
     * @var int
     */
    protected int $subtype = Binary::TYPE_GENERIC;

    /**
     * Convert binary data into the database format
     *
     * @param mixed $value The value to convert
     * @param \Crustum\Mongo\Database\Driver\MongoDriver $driver The driver instance to convert with
     * @return \MongoDB\BSON\Binary|null
     */
    public function toDatabase(mixed $value, MongoDriver $driver): ?Binary
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Binary) {
            return $value;
        }

        if (is_string($value)) {
            return new Binary($value, $this->subtype);
        }

        throw new InvalidArgumentException('Cannot convert to Binary');
    }

    /**
     * Convert binary values to PHP strings
     *
     * @param mixed $value The value to convert
     * @param \Crustum\Mongo\Database\Driver\MongoDriver $driver The driver instance to convert with
     * @return string|null
     */
    public function toPHP(mixed $value, MongoDriver $driver): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Binary) {
            return $value->getData();
        }

        return (string)$value;
    }

    /**
     * Marshals request data into PHP binary strings
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

        if (is_string($value)) {
            return $value;
        }

        return null;
    }
}

<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Type;

use Crustum\Mongo\Database\Driver\MongoDriver;
use InvalidArgumentException;

/**
 * Float type converter
 *
 * Use to convert float data between PHP and MongoDB
 */
class FloatType extends BaseType
{
    /**
     * Checks if the value is not a numeric value
     *
     * @throws \InvalidArgumentException
     * @param mixed $value Value to check
     * @return void
     */
    protected function checkNumeric(mixed $value): void
    {
        if (!is_numeric($value) && !is_bool($value)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Cannot convert value `%s` of type `%s` to float',
                    print_r($value, true),
                    get_debug_type($value),
                ),
            );
        }
    }

    /**
     * Convert float data into the database format
     *
     * @param mixed $value The value to convert
     * @param \Crustum\Mongo\Database\Driver\MongoDriver $driver The driver instance to convert with
     * @return float|null
     */
    public function toDatabase(mixed $value, MongoDriver $driver): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $this->checkNumeric($value);

        return (float)$value;
    }

    /**
     * Convert float values to PHP floats
     *
     * @param mixed $value The value to convert
     * @param \Crustum\Mongo\Database\Driver\MongoDriver $driver The driver instance to convert with
     * @return float|null
     */
    public function toPHP(mixed $value, MongoDriver $driver): ?float
    {
        if ($value === null) {
            return null;
        }

        return (float)$value;
    }

    /**
     * Marshals request data into PHP floats
     *
     * @param mixed $value The value to convert
     * @return float|null Converted value
     */
    public function marshal(mixed $value): ?float
    {
        if ($value === '' || !is_numeric($value)) {
            return null;
        }

        return (float)$value;
    }
}

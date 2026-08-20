<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Type;

use Crustum\Mongo\Database\Driver\MongoDriver;
use InvalidArgumentException;
use Override;

/**
 * Integer type converter
 *
 * Use to convert integer data between PHP and MongoDB
 *
 * Implements `Incrementable` and `Versionable` so integer fields can be
 * diffed into `$inc` updates and bumped for optimistic locking.
 *
 * @ported-from \Cake\Database\Type\IntegerType
 */
class IntegerType extends BaseType implements Incrementable, Versionable
{
    /**
     * @inheritDoc
     */
    public function diff(mixed $old, mixed $new): ?int
    {
        if ($old === null || $new === null) {
            return null;
        }

        return (int)$new - (int)$old;
    }

    /**
     * @inheritDoc
     */
    public function getNextVersion(mixed $current): int
    {
        return (int)$current + 1;
    }

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
                    'Cannot convert value `%s` of type `%s` to int',
                    print_r($value, true),
                    get_debug_type($value),
                ),
            );
        }
    }

    /**
     * Convert integer data into the database format
     *
     * @param mixed $value The value to convert
     * @param \Crustum\Mongo\Database\Driver\MongoDriver $driver The driver instance to convert with
     * @return int|null
     */
    public function toDatabase(mixed $value, MongoDriver $driver): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $this->checkNumeric($value);

        return (int)$value;
    }

    /**
     * Convert integer values to PHP integers
     *
     * @param mixed $value The value to convert
     * @param \Crustum\Mongo\Database\Driver\MongoDriver $driver The driver instance to convert with
     * @return int|null
     */
    public function toPHP(mixed $value, MongoDriver $driver): ?int
    {
        if ($value === null) {
            return null;
        }

        return (int)$value;
    }

    /**
     * Marshals request data into PHP integers
     *
     * @param mixed $value The value to convert
     * @return int|null Converted value
     */
    #[Override]
    public function marshal(mixed $value): ?int
    {
        if ($value === '' || !is_numeric($value)) {
            return null;
        }

        return (int)$value;
    }
}

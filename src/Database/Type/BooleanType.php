<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Type;

use Crustum\Mongo\Database\Driver\MongoDriver;
use Override;

/**
 * Boolean type converter
 *
 * Use to convert boolean data between PHP and MongoDB
 *
 * @inspired-by \Cake\Database\Type\BoolType
 */
class BooleanType extends BaseType
{
    /**
     * Convert boolean data into the database format
     *
     * @param mixed $value The value to convert
     * @param \Crustum\Mongo\Database\Driver\MongoDriver $driver The driver instance to convert with
     * @return bool|null
     */
    public function toDatabase(mixed $value, MongoDriver $driver): ?bool
    {
        if ($value === null) {
            return null;
        }

        return (bool)$value;
    }

    /**
     * Convert boolean values to PHP booleans
     *
     * @param mixed $value The value to convert
     * @param \Crustum\Mongo\Database\Driver\MongoDriver $driver The driver instance to convert with
     * @return bool|null
     */
    public function toPHP(mixed $value, MongoDriver $driver): ?bool
    {
        if ($value === null) {
            return null;
        }

        return (bool)$value;
    }

    /**
     * Marshals request data into PHP booleans
     *
     * @param mixed $value The value to convert
     * @return bool|null Converted value
     */
    #[Override]
    public function marshal(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true);
        }

        return (bool)$value;
    }
}

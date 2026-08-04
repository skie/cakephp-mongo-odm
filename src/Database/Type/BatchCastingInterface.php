<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Type;

use Crustum\Mongo\Database\Driver\MongoDriver;

/**
 * Denotes type objects capable of converting many values from their original
 * database representation to PHP values.
 *
 * @see cake60/src/Database/Type/BatchCastingInterface.php
 */
interface BatchCastingInterface
{
    /**
     * Returns an array of the values converted to the PHP representation of
     * this type.
     *
     * @param array<string, mixed> $values The original array of values containing the fields to be cast.
     * @param array<string> $fields The field keys to cast.
     * @param \Crustum\Mongo\Database\Driver\MongoDriver $driver The driver from which database preferences are extracted.
     * @return array<string, mixed>
     */
    public function manyToPHP(array $values, array $fields, MongoDriver $driver): array;
}

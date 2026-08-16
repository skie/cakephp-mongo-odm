<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Type;

/**
 * Auto-increment identifier type.
 *
 * Marker for a sequential integer primary key (`id`). Distinct from the plain
 * `IntegerType` so a collection can opt into `IncrementGenerator`-based id
 * generation (via `BaseCollection::newId()`) without polluting the generic
 * integer converter with id-generation responsibilities.
 *
 * @see mongodb-odm Types/IntType.php (id semantics)
 */
class AutoIncrementType extends IntegerType
{
    /**
     * @inheritDoc
     */
    public function newId(): mixed
    {
        return null;
    }
}

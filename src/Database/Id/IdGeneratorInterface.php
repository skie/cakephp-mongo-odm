<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Id;

/**
 * Contract for identifier generators.
 *
 * Generators produce the `_id` value for new documents. They are used by the
 * ODM layer when a collection requires a custom id strategy (sequential
 * counters, UUIDs) instead of the driver-generated `ObjectId`.
 *
 * @see mongodb-odm Id/IdGenerator.php
 */
interface IdGeneratorInterface
{
    /**
     * Returns a new identifier value.
     *
     * @param object|array<string, mixed>|null $document The document being inserted, when available.
     * @return mixed The generated identifier.
     */
    public function generate(array|object|null $document = null): mixed;
}

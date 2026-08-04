<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Type;

/**
 * Marker for types whose values can be diffed and written with `$inc`.
 *
 * Implementing types expose the delta between two values so an update can be
 * expressed as an atomic `$inc` operator instead of a full document rewrite
 * (used by the ODM's dirty-checking path).
 *
 * @see mongodb-odm Types/Incrementable.php
 */
interface Incrementable
{
    /**
     * Returns the value that would be passed to `$inc` to get from `$old` to `$new`.
     *
     * @param mixed $old The original value
     * @param mixed $new The target value
     * @return mixed The delta, or `null` when the diff cannot be expressed via `$inc`
     */
    public function diff(mixed $old, mixed $new): mixed;
}

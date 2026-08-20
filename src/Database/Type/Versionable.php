<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Type;

/**
 * Marker for types that support optimistic-lock version bumping.
 *
 * Implementing types can produce the next version value from the current one,
 * enabling `$inc`-style version counters for optimistic locking (see plan 08
 * §9).
 *
 * @ported-from \Doctrine\ODM\MongoDB\Types\Versionable
 */
interface Versionable
{
    /**
     * Returns the next version value based on the current one.
     *
     * @param mixed $current The current version
     * @return mixed The next version
     */
    public function getNextVersion(mixed $current): mixed;
}

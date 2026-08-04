<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Query;

/**
 * Contract for window specifications compiled to a `$setWindowFields` stage.
 *
 * @see \Crustum\Mongo\Database\Query\SelectQuery::window()
 */
interface WindowInterface
{
    /**
     * Returns the `$setWindowFields` stage body.
     *
     * @return array{partitionBy?: array<string, mixed>|string, sortBy?: array<string, int>, output: array<string, mixed>}
     */
    public function getWindow(): array;
}

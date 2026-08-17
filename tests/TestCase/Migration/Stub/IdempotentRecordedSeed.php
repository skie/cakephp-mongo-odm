<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Migration\Stub;

/**
 * Named idempotent seed used by environment seed-recording tests.
 */
class IdempotentRecordedSeed extends RecordedSeed
{
    public function isIdempotent(): bool
    {
        return true;
    }
}

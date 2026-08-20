<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Migration\Stub;

use Override;

/**
 * Named idempotent seed used by environment seed-recording tests.
 */
class IdempotentRecordedSeed extends RecordedSeed
{
    #[Override]
    public function isIdempotent(): bool
    {
        return true;
    }
}

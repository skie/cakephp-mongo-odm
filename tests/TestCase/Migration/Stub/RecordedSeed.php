<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Migration\Stub;

use Crustum\Mongo\Migration\BaseSeed;

/**
 * Named seed used by environment seed-recording tests.
 */
class RecordedSeed extends BaseSeed
{
    public function run(): void
    {
    }
}

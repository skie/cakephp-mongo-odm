<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Migration\Stub;

use Crustum\Mongo\Migration\BaseSeed;

/**
 * Named seed used by adapter seed-log tests.
 */
class SeedLogSeed extends BaseSeed
{
    public function run(): void
    {
    }
}

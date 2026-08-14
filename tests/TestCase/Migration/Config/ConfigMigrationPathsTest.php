<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Migration\Config;

use Crustum\Mongo\Migration\Config\Config;
use UnexpectedValueException;

/**
 * Tests migration path resolution (ported from cakephp/migrations).
 */
class ConfigMigrationPathsTest extends AbstractConfigTestCase
{
    public function testGetMigrationPathsThrowsExceptionForNoPath(): void
    {
        $config = new Config([]);

        $this->expectException(UnexpectedValueException::class);

        $config->getMigrationPath();
    }

    public function testGetMigrationPaths(): void
    {
        $config = new Config($this->getConfigArray());

        $this->assertSame($this->getMigrationPath(), $config->getMigrationPath());
    }
}

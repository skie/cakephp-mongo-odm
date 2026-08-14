<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Migration\Config;

use Crustum\Mongo\Migration\Config\Config;
use UnexpectedValueException;

/**
 * Tests seed path resolution (ported from cakephp/migrations).
 */
class ConfigSeedPathsTest extends AbstractConfigTestCase
{
    public function testGetSeedPathsThrowsExceptionForNoPath(): void
    {
        $config = new Config([]);

        $this->expectException(UnexpectedValueException::class);

        $config->getSeedPath();
    }

    public function testGetSeedPaths(): void
    {
        $config = new Config($this->getConfigArray());

        $this->assertSame($this->getSeedPath(), $config->getSeedPath());
    }

    public function testGetSeedPathConvertsStringToArray(): void
    {
        $values = [
            'paths' => [
                'seeds' => '/test',
            ],
        ];

        $config = new Config($values);

        $this->assertSame('/test', $config->getSeedPath());
    }
}

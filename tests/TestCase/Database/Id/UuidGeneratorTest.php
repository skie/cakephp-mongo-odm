<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Id;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Id\UuidGenerator;

/**
 * Tests for UuidGenerator (UUID v7)
 */
class UuidGeneratorTest extends TestCase
{
    /**
     * Test generate returns canonical UUID v7
     *
     * @return void
     */
    public function testGenerateFormat(): void
    {
        $generator = new UuidGenerator();
        $uuid = $generator->generate();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid,
        );
    }

    /**
     * Test generate returns unique uuids
     *
     * @return void
     */
    public function testGenerateUnique(): void
    {
        $generator = new UuidGenerator();
        $this->assertNotSame($generator->generate(), $generator->generate());
    }

    /**
     * Test uuids are time-ordered (prefixes are non-decreasing for rapid calls)
     *
     * @return void
     */
    public function testTimeOrdering(): void
    {
        $generator = new UuidGenerator();
        $a = $generator->generate();
        $b = $generator->generate();

        $this->assertLessThanOrEqual(substr($b, 0, 12), substr($a, 0, 12));
    }

    /**
     * Test version nibble is 7
     *
     * @return void
     */
    public function testVersionSeven(): void
    {
        $generator = new UuidGenerator();
        $uuid = $generator->generate();

        $this->assertSame('7', $uuid[14]);
    }
}

<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Id;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Id\ObjectIdGenerator;
use MongoDB\BSON\ObjectId;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for ObjectIdGenerator
 */
#[CoversClass(ObjectIdGenerator::class)]
class ObjectIdGeneratorTest extends TestCase
{
    /**
     * Test generate returns ObjectId
     *
     * @return void
     */
    public function testGenerate(): void
    {
        $generator = new ObjectIdGenerator();
        $this->assertInstanceOf(ObjectId::class, $generator->generate());
    }

    /**
     * Test generate returns unique ids
     *
     * @return void
     */
    public function testGenerateUnique(): void
    {
        $generator = new ObjectIdGenerator();
        $this->assertNotEquals(
            (string)$generator->generate(),
            (string)$generator->generate(),
        );
    }

    /**
     * Test generate ignores document argument
     *
     * @return void
     */
    public function testGenerateWithDocument(): void
    {
        $generator = new ObjectIdGenerator();
        $this->assertInstanceOf(ObjectId::class, $generator->generate(['name' => 'x']));
    }
}

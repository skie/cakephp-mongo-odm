<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Type;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Driver\MongoDriver;
use Crustum\Mongo\Database\Type\FloatType;
use Crustum\Mongo\Database\Type\Incrementable;
use Crustum\Mongo\Database\Type\IntegerType;
use Crustum\Mongo\Database\Type\Versionable;

/**
 * Tests for the Incrementable and Versionable marker interfaces
 *
 * @rewritten-from \Doctrine\ODM\MongoDB\Tests\Types\VersionableTest
 */
class IncrementableVersionableTest extends TestCase
{
    /**
     * @var \Crustum\Mongo\Database\Driver\MongoDriver
     */
    protected MongoDriver $driver;

    /**
     * Set up before each test
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->driver = $this->createStub(MongoDriver::class);
    }

    /**
     * Test IntegerType implements both markers
     *
     * @return void
     */
    public function testIntegerTypeImplementsMarkers(): void
    {
        $this->assertInstanceOf(Incrementable::class, new IntegerType());
        $this->assertInstanceOf(Versionable::class, new IntegerType());
    }

    /**
     * Test FloatType implements Incrementable
     *
     * @return void
     */
    public function testFloatTypeImplementsIncrementable(): void
    {
        $this->assertInstanceOf(Incrementable::class, new FloatType());
    }

    /**
     * Test IntegerType diff
     *
     * @return void
     */
    public function testIntegerDiff(): void
    {
        $type = new IntegerType();
        $this->assertSame(5, $type->diff(10, 15));
        $this->assertSame(-3, $type->diff(10, 7));
    }

    /**
     * Test IntegerType diff with null returns null
     *
     * @return void
     */
    public function testIntegerDiffWithNull(): void
    {
        $type = new IntegerType();
        $this->assertNull($type->diff(null, 15));
        $this->assertNull($type->diff(10, null));
    }

    /**
     * Test FloatType diff
     *
     * @return void
     */
    public function testFloatDiff(): void
    {
        $type = new FloatType();
        $this->assertSame(2.5, $type->diff(1.5, 4.0));
    }

    /**
     * Test FloatType diff with null returns null
     *
     * @return void
     */
    public function testFloatDiffWithNull(): void
    {
        $type = new FloatType();
        $this->assertNull($type->diff(null, 4.0));
    }

    /**
     * Test getNextVersion increments
     *
     * @return void
     */
    public function testGetNextVersion(): void
    {
        $type = new IntegerType();
        $this->assertSame(2, $type->getNextVersion(1));
        $this->assertSame(1, $type->getNextVersion(0));
    }
}

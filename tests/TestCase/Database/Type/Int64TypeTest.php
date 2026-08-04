<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Type;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Driver\MongoDriver;
use Crustum\Mongo\Database\Type\Int64Type;
use InvalidArgumentException;
use MongoDB\BSON\Int64;

/**
 * Test case for Int64Type
 */
class Int64TypeTest extends TestCase
{
    /**
     * @var \Crustum\Mongo\Database\Type\Int64Type
     */
    protected Int64Type $type;

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
        $this->type = new Int64Type('int64');
        $this->driver = $this->createStub(MongoDriver::class);
    }

    /**
     * Test toDatabase with int
     *
     * @return void
     */
    public function testToDatabaseWithInt(): void
    {
        $result = $this->type->toDatabase(1234567890, $this->driver);
        $this->assertInstanceOf(Int64::class, $result);
        $this->assertSame('1234567890', $result->__toString());
    }

    /**
     * Test toDatabase with string
     *
     * @return void
     */
    public function testToDatabaseWithString(): void
    {
        $result = $this->type->toDatabase('1234567890123456789', $this->driver);
        $this->assertInstanceOf(Int64::class, $result);
        $this->assertSame('1234567890123456789', $result->__toString());
    }

    /**
     * Test toDatabase with Int64 passes through
     *
     * @return void
     */
    public function testToDatabaseWithInt64(): void
    {
        $int64 = new Int64('42');
        $this->assertSame($int64, $this->type->toDatabase($int64, $this->driver));
    }

    /**
     * Test toDatabase with null
     *
     * @return void
     */
    public function testToDatabaseWithNull(): void
    {
        $this->assertNull($this->type->toDatabase(null, $this->driver));
    }

    /**
     * Test toDatabase with float throws exception
     *
     * @return void
     */
    public function testToDatabaseWithFloatThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->type->toDatabase(1.5, $this->driver);
    }

    /**
     * Test toPHP with Int64
     *
     * @return void
     */
    public function testToPHPWithInt64(): void
    {
        $result = $this->type->toPHP(new Int64('1234567890'), $this->driver);
        $this->assertSame(1234567890, $result);
    }

    /**
     * Test toPHP with null
     *
     * @return void
     */
    public function testToPHPWithNull(): void
    {
        $this->assertNull($this->type->toPHP(null, $this->driver));
    }

    /**
     * Test marshal with numeric string
     *
     * @return void
     */
    public function testMarshalWithNumericString(): void
    {
        $this->assertSame(42, $this->type->marshal('42'));
    }

    /**
     * Test marshal with null
     *
     * @return void
     */
    public function testMarshalWithNull(): void
    {
        $this->assertNull($this->type->marshal(null));
    }

    /**
     * Test diff
     *
     * @return void
     */
    public function testDiff(): void
    {
        $this->assertSame(5, $this->type->diff(10, 15));
        $this->assertNull($this->type->diff(null, 15));
    }

    /**
     * Test getNextVersion
     *
     * @return void
     */
    public function testGetNextVersion(): void
    {
        $this->assertSame(2, $this->type->getNextVersion(1));
    }
}

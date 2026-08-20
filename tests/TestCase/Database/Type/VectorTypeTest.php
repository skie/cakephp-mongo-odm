<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Type;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Driver\MongoDriver;
use Crustum\Mongo\Database\Type\VectorFloat32Type;
use Crustum\Mongo\Database\Type\VectorInt8Type;
use Crustum\Mongo\Database\Type\VectorPackedBitType;
use MongoDB\BSON\Binary;
use MongoDB\BSON\PackedArray;

/**
 * Tests for the vector types
 *
 * @rewritten-from \Doctrine\ODM\MongoDB\Tests\Types\VectorTypeTest
 */
class VectorTypeTest extends TestCase
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
     * Test VectorFloat32Type toDatabase returns PackedArray
     *
     * @return void
     */
    public function testFloat32ToDatabase(): void
    {
        $type = new VectorFloat32Type();
        $result = $type->toDatabase([1.5, 2.5], $this->driver);

        $this->assertInstanceOf(PackedArray::class, $result);
    }

    /**
     * Test VectorFloat32Type round-trip coerces to floats
     *
     * @return void
     */
    public function testFloat32RoundTrip(): void
    {
        $type = new VectorFloat32Type();
        $stored = $type->toDatabase([1, 2], $this->driver);
        $result = $type->toPHP($stored, $this->driver);

        $this->assertSame([1.0, 2.0], $result);
    }

    /**
     * Test VectorFloat32Type with null
     *
     * @return void
     */
    public function testFloat32Null(): void
    {
        $type = new VectorFloat32Type();
        $this->assertNull($type->toDatabase(null, $this->driver));
        $this->assertNull($type->toPHP(null, $this->driver));
    }

    /**
     * Test VectorInt8Type round-trip coerces to ints
     *
     * @return void
     */
    public function testInt8RoundTrip(): void
    {
        $type = new VectorInt8Type();
        $stored = $type->toDatabase([1.9, 2.9], $this->driver);
        $result = $type->toPHP($stored, $this->driver);

        $this->assertSame([1, 2], $result);
    }

    /**
     * Test VectorPackedBitType toDatabase returns Binary
     *
     * @return void
     */
    public function testPackedBitToDatabase(): void
    {
        $type = new VectorPackedBitType();
        $result = $type->toDatabase([1, 0, 1, 0], $this->driver);

        $this->assertInstanceOf(Binary::class, $result);
        $this->assertSame(Binary::TYPE_USER_DEFINED, $result->getType());
    }

    /**
     * Test VectorPackedBitType round-trip
     *
     * @return void
     */
    public function testPackedBitRoundTrip(): void
    {
        $type = new VectorPackedBitType();
        $bits = [1, 0, 1, 0, 1, 0, 1, 0];
        $stored = $type->toDatabase($bits, $this->driver);
        $result = $type->toPHP($stored, $this->driver);

        $this->assertSame($bits, $result);
    }

    /**
     * Test VectorPackedBitType with non-zero values coerces to bits
     *
     * Output is byte-aligned: the partial trailing byte is zero-padded.
     *
     * @return void
     */
    public function testPackedBitCoercesToBits(): void
    {
        $type = new VectorPackedBitType();
        $result = $type->toPHP($type->toDatabase([5, 0, -2, 0], $this->driver), $this->driver);

        $this->assertSame([1, 0, 1, 0, 0, 0, 0, 0], $result);
    }

    /**
     * Test marshal normalizes arrays
     *
     * @return void
     */
    public function testMarshal(): void
    {
        $type = new VectorFloat32Type();
        $this->assertSame([1.0, 2.0], $type->marshal([1, 2]));
        $this->assertNull($type->marshal(null));
    }
}

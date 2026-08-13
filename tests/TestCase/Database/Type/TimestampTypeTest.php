<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Type;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Driver\MongoDriver;
use Crustum\Mongo\Database\Type\TimestampType;
use InvalidArgumentException;
use MongoDB\BSON\Timestamp;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Test case for TimestampType
 */
#[CoversClass(TimestampType::class)]
class TimestampTypeTest extends TestCase
{
    /**
     * @var \Crustum\Mongo\Database\Type\TimestampType
     */
    protected TimestampType $type;

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
        $this->type = new TimestampType('timestamp');
        $this->driver = $this->createStub(MongoDriver::class);
    }

    /**
     * Test toDatabase with Timestamp instance
     *
     * @return void
     */
    public function testToDatabaseWithTimestamp(): void
    {
        $timestamp = new Timestamp(1234567890, 0);
        $result = $this->type->toDatabase($timestamp, $this->driver);
        $this->assertSame($timestamp, $result);
    }

    /**
     * Test toDatabase with array
     *
     * @return void
     */
    public function testToDatabaseWithArray(): void
    {
        $array = [1234567890, 5];
        $result = $this->type->toDatabase($array, $this->driver);
        $this->assertInstanceOf(Timestamp::class, $result);
        $this->assertEquals(5, $result->getTimestamp());
        $this->assertEquals(1234567890, $result->getIncrement());
    }

    /**
     * Test toDatabase with numeric value
     *
     * @return void
     */
    public function testToDatabaseWithNumeric(): void
    {
        $timestamp = 1234567890;
        $result = $this->type->toDatabase($timestamp, $this->driver);
        $this->assertInstanceOf(Timestamp::class, $result);
        $this->assertEquals(0, $result->getTimestamp());
        $this->assertEquals(1234567890, $result->getIncrement());
    }

    /**
     * Test toDatabase with null
     *
     * @return void
     */
    public function testToDatabaseWithNull(): void
    {
        $result = $this->type->toDatabase(null, $this->driver);
        $this->assertNull($result);
    }

    /**
     * Test toDatabase with invalid value throws exception
     *
     * @return void
     */
    public function testToDatabaseWithInvalidValueThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->type->toDatabase('invalid', $this->driver);
    }

    /**
     * Test toPHP with Timestamp
     *
     * @return void
     */
    public function testToPHPWithTimestamp(): void
    {
        $timestamp = new Timestamp(1234567890, 5);
        $result = $this->type->toPHP($timestamp, $this->driver);
        $this->assertIsArray($result);
        $this->assertEquals([5, 1234567890], $result);
    }

    /**
     * Test toPHP with array
     *
     * @return void
     */
    public function testToPHPWithArray(): void
    {
        $array = [1234567890, 5];
        $result = $this->type->toPHP($array, $this->driver);
        $this->assertEquals($array, $result);
    }

    /**
     * Test toPHP with null
     *
     * @return void
     */
    public function testToPHPWithNull(): void
    {
        $result = $this->type->toPHP(null, $this->driver);
        $this->assertNull($result);
    }

    /**
     * Test marshal with array
     *
     * @return void
     */
    public function testMarshalWithArray(): void
    {
        $array = [1234567890, 5];
        $result = $this->type->marshal($array);
        $this->assertEquals([1234567890, 5], $result);
    }

    /**
     * Test marshal with numeric value
     *
     * @return void
     */
    public function testMarshalWithNumeric(): void
    {
        $timestamp = 1234567890;
        $result = $this->type->marshal($timestamp);
        $this->assertEquals([1234567890, 0], $result);
    }

    /**
     * Test marshal with null
     *
     * @return void
     */
    public function testMarshalWithNull(): void
    {
        $result = $this->type->marshal(null);
        $this->assertNull($result);
    }

    /**
     * Test marshal with empty string
     *
     * @return void
     */
    public function testMarshalWithEmptyString(): void
    {
        $result = $this->type->marshal('');
        $this->assertNull($result);
    }
}

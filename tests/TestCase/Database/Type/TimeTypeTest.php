<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Type;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Driver\MongoDriver;
use Crustum\Mongo\Database\Type\TimeType;
use DateTime;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Test case for TimeType
 */
class TimeTypeTest extends TestCase
{
    /**
     * @var \Crustum\Mongo\Database\Type\TimeType
     */
    protected TimeType $type;

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
        $this->type = new TimeType('time');
        $this->driver = $this->createStub(MongoDriver::class);
    }

    /**
     * Test toDatabase with a time string
     *
     * @return void
     */
    public function testToDatabaseWithString(): void
    {
        $this->assertSame('13:30:45', $this->type->toDatabase('13:30:45', $this->driver));
    }

    /**
     * Test toDatabase with a DateTime
     *
     * @return void
     */
    public function testToDatabaseWithDateTime(): void
    {
        $result = $this->type->toDatabase(new DateTime('2024-01-01 13:30:45'), $this->driver);
        $this->assertSame('13:30:45', $result);
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
     * Test toDatabase with invalid value throws exception
     *
     * @return void
     */
    public function testToDatabaseWithInvalidValueThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->type->toDatabase(['time'], $this->driver);
    }

    /**
     * Test toPHP with a time string
     *
     * @return void
     */
    public function testToPHPWithString(): void
    {
        $result = $this->type->toPHP('13:30:45', $this->driver);
        $this->assertInstanceOf(DateTimeInterface::class, $result);
        $this->assertSame('13:30:45', $result->format('H:i:s'));
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
     * Test toPHP with an invalid string returns null
     *
     * @return void
     */
    public function testToPHPWithInvalidString(): void
    {
        $this->assertNull($this->type->toPHP('not-a-time', $this->driver));
    }

    /**
     * Test marshal with a time string
     *
     * @return void
     */
    public function testMarshalWithString(): void
    {
        $result = $this->type->marshal('09:15:30');
        $this->assertInstanceOf(DateTimeInterface::class, $result);
        $this->assertSame('09:15:30', $result->format('H:i:s'));
    }

    /**
     * Test marshal with an array of parts
     *
     * @return void
     */
    public function testMarshalWithArray(): void
    {
        $result = $this->type->marshal(['hour' => 9, 'minute' => 15, 'second' => 30]);
        $this->assertInstanceOf(DateTimeInterface::class, $result);
        $this->assertSame('09:15:30', $result->format('H:i:s'));
    }

    /**
     * Test marshal with null returns null
     *
     * @return void
     */
    public function testMarshalWithNull(): void
    {
        $this->assertNull($this->type->marshal(null));
    }

    /**
     * Test marshal with an invalid string returns null
     *
     * @return void
     */
    public function testMarshalWithInvalidString(): void
    {
        $this->assertNull($this->type->marshal('not-a-time'));
    }
}

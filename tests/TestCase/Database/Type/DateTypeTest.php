<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Type;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Driver\MongoDriver;
use Crustum\Mongo\Database\Type\DateType;
use DateTime;
use InvalidArgumentException;
use MongoDB\BSON\UTCDateTime;

/**
 * Test case for DateType
 */
class DateTypeTest extends TestCase
{
    /**
     * @var \Crustum\Mongo\Database\Type\DateType
     */
    protected DateType $type;

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
        $this->type = new DateType('date');
        $this->driver = $this->createStub(MongoDriver::class);
    }

    /**
     * Test toDatabase with UTCDateTime
     *
     * @return void
     */
    public function testToDatabaseWithUTCDateTime(): void
    {
        $utcDateTime = new UTCDateTime();
        $result = $this->type->toDatabase($utcDateTime, $this->driver);
        $this->assertSame($utcDateTime, $result);
    }

    /**
     * Test toDatabase with DateTime
     *
     * @return void
     */
    public function testToDatabaseWithDateTime(): void
    {
        $dateTime = new DateTime('2024-01-01 12:00:00');
        $result = $this->type->toDatabase($dateTime, $this->driver);
        $this->assertInstanceOf(UTCDateTime::class, $result);
    }

    /**
     * Test toDatabase with string
     *
     * @return void
     */
    public function testToDatabaseWithString(): void
    {
        $string = '2024-01-01 12:00:00';
        $result = $this->type->toDatabase($string, $this->driver);
        $this->assertInstanceOf(UTCDateTime::class, $result);
    }

    /**
     * Test toDatabase with numeric timestamp
     *
     * @return void
     */
    public function testToDatabaseWithNumeric(): void
    {
        $timestamp = 1704110400;
        $result = $this->type->toDatabase($timestamp, $this->driver);
        $this->assertInstanceOf(UTCDateTime::class, $result);
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
     * Test toDatabase with invalid string throws exception
     *
     * @return void
     */
    public function testToDatabaseWithInvalidStringThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->type->toDatabase('invalid date', $this->driver);
    }

    /**
     * Test toPHP with UTCDateTime
     *
     * @return void
     */
    public function testToPHPWithUTCDateTime(): void
    {
        $utcDateTime = new UTCDateTime();
        $result = $this->type->toPHP($utcDateTime, $this->driver);
        $this->assertInstanceOf(DateTime::class, $result);
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
     * Test marshal with string
     *
     * @return void
     */
    public function testMarshalWithString(): void
    {
        $string = '2024-01-01 12:00:00';
        $result = $this->type->marshal($string);
        $this->assertInstanceOf(DateTime::class, $result);
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

    public function testManyToPHP(): void
    {
        $values = ['created' => '2024-01-02 03:04:05', 'other' => 'x'];
        $result = $this->type->manyToPHP($values, ['created'], $this->driver);

        $this->assertInstanceOf(DateTime::class, $result['created']);
        $this->assertSame('x', $result['other']);
    }

    public function testMarshalWithPartsArray(): void
    {
        $this->assertSame(
            '2024-01-02',
            $this->type->marshal(['year' => 2024, 'month' => 1, 'day' => 2])->format('Y-m-d'),
        );
        $this->assertNull($this->type->marshal(['year' => 'x', 'month' => 1, 'day' => 2]));
    }

    public function testMarshalWithLocaleParser(): void
    {
        $this->type->useLocaleParser()->setLocaleFormat('d.m.Y');

        $result = $this->type->marshal('02.01.2024');
        $this->assertInstanceOf(DateTime::class, $result);
        $this->assertSame('2024-01-02', $result->format('Y-m-d'));
    }

    public function testGetDateClassName(): void
    {
        $this->assertSame(DateTime::class, $this->type->getDateClassName());
    }
}

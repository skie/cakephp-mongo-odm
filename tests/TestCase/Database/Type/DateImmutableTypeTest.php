<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Type;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Driver\MongoDriver;
use Crustum\Mongo\Database\Type\DateImmutableType;
use DateTimeImmutable;
use MongoDB\BSON\UTCDateTime;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Test case for DateImmutableType
 */
#[CoversClass(DateImmutableType::class)]
class DateImmutableTypeTest extends TestCase
{
    /**
     * @var \Crustum\Mongo\Database\Type\DateImmutableType
     */
    protected DateImmutableType $type;

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
        $this->type = new DateImmutableType('date_immutable');
        $this->driver = $this->createStub(MongoDriver::class);
    }

    /**
     * Test toDatabase with DateTime
     *
     * @return void
     */
    public function testToDatabaseWithDateTime(): void
    {
        $result = $this->type->toDatabase(new DateTimeImmutable('2024-01-01 12:00:00'), $this->driver);
        $this->assertInstanceOf(UTCDateTime::class, $result);
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
     * Test toPHP returns DateTimeImmutable
     *
     * @return void
     */
    public function testToPHPWithUTCDateTime(): void
    {
        $result = $this->type->toPHP(new UTCDateTime(), $this->driver);
        $this->assertInstanceOf(DateTimeImmutable::class, $result);
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
     * Test marshal returns DateTimeImmutable
     *
     * @return void
     */
    public function testMarshalWithString(): void
    {
        $result = $this->type->marshal('2024-01-01 12:00:00');
        $this->assertInstanceOf(DateTimeImmutable::class, $result);
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
}

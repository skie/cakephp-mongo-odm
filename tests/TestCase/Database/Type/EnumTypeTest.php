<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Type;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Driver\MongoDriver;
use Crustum\Mongo\Database\Type\EnumType;
use Crustum\Mongo\Database\Type\TypeFactory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Test case for EnumType
 */
#[CoversClass(EnumType::class)]
class EnumTypeTest extends TestCase
{
    /**
     * @var \Crustum\Mongo\Database\Type\EnumType
     */
    protected EnumType $type;

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
        $this->type = new EnumType('enum-suit', Suit::class);
        $this->driver = $this->createStub(MongoDriver::class);
    }

    /**
     * Test toDatabase with an enum instance
     *
     * @return void
     */
    public function testToDatabaseWithEnum(): void
    {
        $this->assertSame('hearts', $this->type->toDatabase(Suit::Hearts, $this->driver));
    }

    /**
     * Test toDatabase with a string value
     *
     * @return void
     */
    public function testToDatabaseWithString(): void
    {
        $this->assertSame('spades', $this->type->toDatabase('spades', $this->driver));
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
     * Test toDatabase with an invalid value throws exception
     *
     * @return void
     */
    public function testToDatabaseWithInvalidValueThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->type->toDatabase('clubs', $this->driver);
    }

    /**
     * Test toPHP with a string value
     *
     * @return void
     */
    public function testToPHPWithString(): void
    {
        $this->assertSame(Suit::Hearts, $this->type->toPHP('hearts', $this->driver));
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
     * Test marshal with an enum instance
     *
     * @return void
     */
    public function testMarshalWithEnum(): void
    {
        $this->assertSame(Suit::Spades, $this->type->marshal(Suit::Spades));
    }

    /**
     * Test marshal with a string value
     *
     * @return void
     */
    public function testMarshalWithString(): void
    {
        $this->assertSame(Suit::Hearts, $this->type->marshal('hearts'));
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
     * Test marshal with an invalid value returns null
     *
     * @return void
     */
    public function testMarshalWithInvalidValue(): void
    {
        $this->assertNull($this->type->marshal('clubs'));
    }

    /**
     * Test int backed enums round-trip
     *
     * @return void
     */
    public function testIntBackedEnum(): void
    {
        $type = new EnumType('enum-priority', Priority::class);
        $this->assertSame(2, $type->toDatabase(Priority::High, $this->driver));
        $this->assertSame(2, $type->toDatabase('2', $this->driver));
        $this->assertSame(Priority::High, $type->toPHP('2', $this->driver));
        $this->assertSame(Priority::Low, $type->marshal('1'));
    }

    /**
     * Test non-backed enums are rejected
     *
     * @return void
     */
    public function testNonBackedEnumThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EnumType('enum-nonbacked', NonBacked::class);
    }

    /**
     * Test from() registers the type in the factory
     *
     * @return void
     */
    public function testFromRegistersType(): void
    {
        $name = EnumType::from(Suit::class);
        $built = TypeFactory::build($name);
        $this->assertInstanceOf(EnumType::class, $built);
        $this->assertSame(Suit::class, $built->getEnumClassName());
    }

    /**
     * Test getEnumClassName
     *
     * @return void
     */
    public function testGetEnumClassName(): void
    {
        $this->assertSame(Suit::class, $this->type->getEnumClassName());
    }
}

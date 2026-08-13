<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Type;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Driver\MongoDriver;
use Crustum\Mongo\Database\Type\Decimal128Type;
use MongoDB\BSON\Decimal128;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Test case for Decimal128Type
 */
#[CoversClass(Decimal128Type::class)]
class Decimal128TypeTest extends TestCase
{
    /**
     * @var \Crustum\Mongo\Database\Type\Decimal128Type
     */
    protected Decimal128Type $type;

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
        $this->type = new Decimal128Type('decimal128');
        $this->driver = $this->createStub(MongoDriver::class);
    }

    /**
     * Test toDatabase with Decimal128
     *
     * @return void
     */
    public function testToDatabaseWithDecimal128(): void
    {
        $decimal = new Decimal128('123.45');
        $result = $this->type->toDatabase($decimal, $this->driver);
        $this->assertSame($decimal, $result);
    }

    /**
     * Test toDatabase with string
     *
     * @return void
     */
    public function testToDatabaseWithString(): void
    {
        $string = '123.45';
        $result = $this->type->toDatabase($string, $this->driver);
        $this->assertInstanceOf(Decimal128::class, $result);
        $this->assertEquals($string, (string)$result);
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
     * Test toDatabase with empty string
     *
     * @return void
     */
    public function testToDatabaseWithEmptyString(): void
    {
        $result = $this->type->toDatabase('', $this->driver);
        $this->assertNull($result);
    }

    /**
     * Test toPHP with Decimal128
     *
     * @return void
     */
    public function testToPHPWithDecimal128(): void
    {
        $decimal = new Decimal128('123.45');
        $result = $this->type->toPHP($decimal, $this->driver);
        $this->assertIsString($result);
        $this->assertEquals('123.45', $result);
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
     * Test marshal with numeric string
     *
     * @return void
     */
    public function testMarshalWithNumericString(): void
    {
        $result = $this->type->marshal('123.45');
        $this->assertEquals('123.45', $result);
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
}

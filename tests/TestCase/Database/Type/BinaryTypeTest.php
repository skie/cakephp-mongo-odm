<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Type;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Driver\MongoDriver;
use Crustum\Mongo\Database\Type\BinaryType;
use InvalidArgumentException;
use MongoDB\BSON\Binary;

/**
 * Test case for BinaryType
 */
class BinaryTypeTest extends TestCase
{
    /**
     * @var \Crustum\Mongo\Database\Type\BinaryType
     */
    protected BinaryType $type;

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
        $this->type = new BinaryType('binary');
        $this->driver = $this->createStub(MongoDriver::class);
    }

    /**
     * Test toDatabase with Binary instance
     *
     * @return void
     */
    public function testToDatabaseWithBinary(): void
    {
        $binary = new Binary('test data', Binary::TYPE_GENERIC);
        $result = $this->type->toDatabase($binary, $this->driver);
        $this->assertSame($binary, $result);
    }

    /**
     * Test toDatabase with string
     *
     * @return void
     */
    public function testToDatabaseWithString(): void
    {
        $string = 'test data';
        $result = $this->type->toDatabase($string, $this->driver);
        $this->assertInstanceOf(Binary::class, $result);
        $this->assertEquals($string, $result->getData());
        $this->assertEquals(Binary::TYPE_GENERIC, $result->getType());
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
        $this->type->toDatabase(123, $this->driver);
    }

    /**
     * Test toPHP with Binary
     *
     * @return void
     */
    public function testToPHPWithBinary(): void
    {
        $binary = new Binary('test data', Binary::TYPE_GENERIC);
        $result = $this->type->toPHP($binary, $this->driver);
        $this->assertEquals('test data', $result);
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
        $string = 'test data';
        $result = $this->type->marshal($string);
        $this->assertEquals($string, $result);
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

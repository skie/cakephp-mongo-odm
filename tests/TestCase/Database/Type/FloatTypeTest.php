<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Type;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Driver\MongoDriver;
use Crustum\Mongo\Database\Type\FloatType;
use InvalidArgumentException;

/**
 * Test case for FloatType
 */
class FloatTypeTest extends TestCase
{
    /**
     * @var \Crustum\Mongo\Database\Type\FloatType
     */
    protected FloatType $type;

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
        $this->type = new FloatType('float');
        $this->driver = $this->createStub(MongoDriver::class);
    }

    /**
     * Test toDatabase with float
     *
     * @return void
     */
    public function testToDatabaseWithFloat(): void
    {
        $result = $this->type->toDatabase(123.45, $this->driver);
        $this->assertEquals(123.45, $result);
        $this->assertIsFloat($result);
    }

    /**
     * Test toDatabase with string number
     *
     * @return void
     */
    public function testToDatabaseWithStringNumber(): void
    {
        $result = $this->type->toDatabase('123.45', $this->driver);
        $this->assertEquals(123.45, $result);
        $this->assertIsFloat($result);
    }

    /**
     * Test toDatabase with integer
     *
     * @return void
     */
    public function testToDatabaseWithInteger(): void
    {
        $result = $this->type->toDatabase(123, $this->driver);
        $this->assertEquals(123.0, $result);
        $this->assertIsFloat($result);
    }

    /**
     * Test toDatabase with boolean
     *
     * @return void
     */
    public function testToDatabaseWithBoolean(): void
    {
        $result = $this->type->toDatabase(true, $this->driver);
        $this->assertEquals(1.0, $result);
        $this->assertIsFloat($result);
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
     * Test toPHP with float
     *
     * @return void
     */
    public function testToPHPWithFloat(): void
    {
        $result = $this->type->toPHP(123.45, $this->driver);
        $this->assertEquals(123.45, $result);
        $this->assertIsFloat($result);
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
        $this->assertEquals(123.45, $result);
        $this->assertIsFloat($result);
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

    /**
     * Test marshal with non-numeric returns null
     *
     * @return void
     */
    public function testMarshalWithNonNumeric(): void
    {
        $result = $this->type->marshal('invalid');
        $this->assertNull($result);
    }
}

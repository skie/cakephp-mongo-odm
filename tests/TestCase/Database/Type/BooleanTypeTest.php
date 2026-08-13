<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Type;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Driver\MongoDriver;
use Crustum\Mongo\Database\Type\BooleanType;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Test case for BooleanType
 */
#[CoversClass(BooleanType::class)]
class BooleanTypeTest extends TestCase
{
    /**
     * @var \Crustum\Mongo\Database\Type\BooleanType
     */
    protected BooleanType $type;

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
        $this->type = new BooleanType('boolean');
        $this->driver = $this->createStub(MongoDriver::class);
    }

    /**
     * Test toDatabase with true
     *
     * @return void
     */
    public function testToDatabaseWithTrue(): void
    {
        $result = $this->type->toDatabase(true, $this->driver);
        $this->assertTrue($result);
        $this->assertIsBool($result);
    }

    /**
     * Test toDatabase with false
     *
     * @return void
     */
    public function testToDatabaseWithFalse(): void
    {
        $result = $this->type->toDatabase(false, $this->driver);
        $this->assertFalse($result);
        $this->assertIsBool($result);
    }

    /**
     * Test toDatabase with integer
     *
     * @return void
     */
    public function testToDatabaseWithInteger(): void
    {
        $result = $this->type->toDatabase(1, $this->driver);
        $this->assertTrue($result);
    }

    /**
     * Test toDatabase with string
     *
     * @return void
     */
    public function testToDatabaseWithString(): void
    {
        $result = $this->type->toDatabase('test', $this->driver);
        $this->assertTrue($result);
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
     * Test toPHP with true
     *
     * @return void
     */
    public function testToPHPWithTrue(): void
    {
        $result = $this->type->toPHP(true, $this->driver);
        $this->assertTrue($result);
        $this->assertIsBool($result);
    }

    /**
     * Test toPHP with false
     *
     * @return void
     */
    public function testToPHPWithFalse(): void
    {
        $result = $this->type->toPHP(false, $this->driver);
        $this->assertFalse($result);
        $this->assertIsBool($result);
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
     * Test marshal with true boolean
     *
     * @return void
     */
    public function testMarshalWithTrueBoolean(): void
    {
        $result = $this->type->marshal(true);
        $this->assertTrue($result);
    }

    /**
     * Test marshal with false boolean
     *
     * @return void
     */
    public function testMarshalWithFalseBoolean(): void
    {
        $result = $this->type->marshal(false);
        $this->assertFalse($result);
    }

    /**
     * Test marshal with 'true' string
     *
     * @return void
     */
    public function testMarshalWithTrueString(): void
    {
        $result = $this->type->marshal('true');
        $this->assertTrue($result);
    }

    /**
     * Test marshal with '1' string
     *
     * @return void
     */
    public function testMarshalWithOneString(): void
    {
        $result = $this->type->marshal('1');
        $this->assertTrue($result);
    }

    /**
     * Test marshal with 'yes' string
     *
     * @return void
     */
    public function testMarshalWithYesString(): void
    {
        $result = $this->type->marshal('yes');
        $this->assertTrue($result);
    }

    /**
     * Test marshal with 'on' string
     *
     * @return void
     */
    public function testMarshalWithOnString(): void
    {
        $result = $this->type->marshal('on');
        $this->assertTrue($result);
    }

    /**
     * Test marshal with 'false' string
     *
     * @return void
     */
    public function testMarshalWithFalseString(): void
    {
        $result = $this->type->marshal('false');
        $this->assertFalse($result);
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

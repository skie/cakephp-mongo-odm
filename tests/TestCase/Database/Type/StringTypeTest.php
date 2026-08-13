<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Type;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Driver\MongoDriver;
use Crustum\Mongo\Database\Type\StringType;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Test case for StringType
 */
#[CoversClass(StringType::class)]
class StringTypeTest extends TestCase
{
    /**
     * @var \Crustum\Mongo\Database\Type\StringType
     */
    protected StringType $type;

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
        $this->type = new StringType('string');
        $this->driver = $this->createStub(MongoDriver::class);
    }

    /**
     * Test toDatabase with string
     *
     * @return void
     */
    public function testToDatabaseWithString(): void
    {
        $result = $this->type->toDatabase('test', $this->driver);
        $this->assertEquals('test', $result);
    }

    /**
     * Test toDatabase with integer
     *
     * @return void
     */
    public function testToDatabaseWithInteger(): void
    {
        $result = $this->type->toDatabase(123, $this->driver);
        $this->assertEquals('123', $result);
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
     * Test toPHP with string
     *
     * @return void
     */
    public function testToPHPWithString(): void
    {
        $result = $this->type->toPHP('test', $this->driver);
        $this->assertEquals('test', $result);
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
        $result = $this->type->marshal('test');
        $this->assertEquals('test', $result);
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
     * Test marshal with array returns null
     *
     * @return void
     */
    public function testMarshalWithArray(): void
    {
        $result = $this->type->marshal(['test']);
        $this->assertNull($result);
    }
}

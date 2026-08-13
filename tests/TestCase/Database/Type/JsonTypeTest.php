<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Type;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Driver\MongoDriver;
use Crustum\Mongo\Database\Type\JsonType;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Test case for JsonType
 */
#[CoversClass(JsonType::class)]
class JsonTypeTest extends TestCase
{
    /**
     * @var \Crustum\Mongo\Database\Type\JsonType
     */
    protected JsonType $type;

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
        $this->type = new JsonType('json');
        $this->driver = $this->createStub(MongoDriver::class);
    }

    /**
     * Test toDatabase with an array
     *
     * @return void
     */
    public function testToDatabaseWithArray(): void
    {
        $result = $this->type->toDatabase(['key' => 'value'], $this->driver);
        $this->assertSame('{"key":"value"}', $result);
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
     * Test toDatabase with a resource throws exception
     *
     * @return void
     */
    public function testToDatabaseWithResourceThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->type->toDatabase(fopen('php://memory', 'r'), $this->driver);
    }

    /**
     * Test toPHP with a JSON string
     *
     * @return void
     */
    public function testToPHPWithString(): void
    {
        $this->assertSame(['key' => 'value'], $this->type->toPHP('{"key":"value"}', $this->driver));
    }

    /**
     * Test toPHP with a non-string value returns null
     *
     * @return void
     */
    public function testToPHPWithNonString(): void
    {
        $this->assertNull($this->type->toPHP(['key' => 'value'], $this->driver));
    }

    /**
     * Test toPHP with null returns null
     *
     * @return void
     */
    public function testToPHPWithNull(): void
    {
        $this->assertNull($this->type->toPHP(null, $this->driver));
    }

    /**
     * Test marshal passes values through
     *
     * @return void
     */
    public function testMarshal(): void
    {
        $value = ['key' => 'value'];
        $this->assertSame($value, $this->type->marshal($value));
    }

    /**
     * Test encoding options affect output
     *
     * @return void
     */
    public function testSetEncodingOptions(): void
    {
        $this->type->setEncodingOptions(JSON_PRETTY_PRINT);
        $result = $this->type->toDatabase(['key' => 'value'], $this->driver);
        $this->assertStringContainsString("\n", $result);
    }

    /**
     * Test decoding options affect output
     *
     * @return void
     */
    public function testSetDecodingOptions(): void
    {
        $this->type->setDecodingOptions(0);
        $result = $this->type->toPHP('{"key":"value"}', $this->driver);
        $this->assertIsObject($result);
        $this->assertSame('value', $result->key);
    }
}

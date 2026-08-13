<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Type;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Driver\MongoDriver;
use Crustum\Mongo\Database\Type\HashType;
use PHPUnit\Framework\Attributes\CoversClass;
use stdClass;

/**
 * Test case for HashType
 */
#[CoversClass(HashType::class)]
class HashTypeTest extends TestCase
{
    /**
     * @var \Crustum\Mongo\Database\Type\HashType
     */
    protected HashType $type;

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
        $this->type = new HashType('hash');
        $this->driver = $this->createStub(MongoDriver::class);
    }

    /**
     * Test toDatabase with array
     *
     * @return void
     */
    public function testToDatabaseWithArray(): void
    {
        $array = ['key1' => 'value1', 'key2' => 'value2'];
        $result = $this->type->toDatabase($array, $this->driver);
        $this->assertInstanceOf(stdClass::class, $result);
        $this->assertEquals('value1', $result->key1);
        $this->assertEquals('value2', $result->key2);
    }

    /**
     * Test toDatabase with object
     *
     * @return void
     */
    public function testToDatabaseWithObject(): void
    {
        $object = (object)['key1' => 'value1', 'key2' => 'value2'];
        $result = $this->type->toDatabase($object, $this->driver);
        $this->assertInstanceOf(stdClass::class, $result);
        $this->assertEquals('value1', $result->key1);
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
     * Test toPHP with object
     *
     * @return void
     */
    public function testToPHPWithObject(): void
    {
        $object = (object)['key1' => 'value1', 'key2' => 'value2'];
        $result = $this->type->toPHP($object, $this->driver);
        $this->assertIsArray($result);
        $this->assertEquals('value1', $result['key1']);
        $this->assertEquals('value2', $result['key2']);
    }

    /**
     * Test toPHP with array
     *
     * @return void
     */
    public function testToPHPWithArray(): void
    {
        $array = ['key1' => 'value1', 'key2' => 'value2'];
        $result = $this->type->toPHP($array, $this->driver);
        $this->assertEquals($array, $result);
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
     * Test marshal with array
     *
     * @return void
     */
    public function testMarshalWithArray(): void
    {
        $array = ['key1' => 'value1', 'key2' => 'value2'];
        $result = $this->type->marshal($array);
        $this->assertEquals($array, $result);
    }

    /**
     * Test marshal with object
     *
     * @return void
     */
    public function testMarshalWithObject(): void
    {
        $object = (object)['key1' => 'value1', 'key2' => 'value2'];
        $result = $this->type->marshal($object);
        $this->assertIsArray($result);
        $this->assertEquals('value1', $result['key1']);
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

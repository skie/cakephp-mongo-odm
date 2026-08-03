<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Type;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Driver\MongoDriver;
use Crustum\Mongo\Database\Type\CollectionType;

/**
 * Test case for CollectionType
 */
class CollectionTypeTest extends TestCase
{
    /**
     * @var \Crustum\Mongo\Database\Type\CollectionType
     */
    protected CollectionType $type;

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
        $this->type = new CollectionType('collection');
        $this->driver = $this->createStub(MongoDriver::class);
    }

    /**
     * Test toDatabase with indexed array
     *
     * @return void
     */
    public function testToDatabaseWithIndexedArray(): void
    {
        $array = ['a', 'b', 'c'];
        $result = $this->type->toDatabase($array, $this->driver);
        $this->assertEquals($array, $result);
        $this->assertEquals([0, 1, 2], array_keys($result));
    }

    /**
     * Test toDatabase with associative array
     *
     * @return void
     */
    public function testToDatabaseWithAssociativeArray(): void
    {
        $array = ['key1' => 'a', 'key2' => 'b'];
        $result = $this->type->toDatabase($array, $this->driver);
        $this->assertEquals(['a', 'b'], $result);
        $this->assertEquals([0, 1], array_keys($result));
    }

    /**
     * Test toDatabase with single value
     *
     * @return void
     */
    public function testToDatabaseWithSingleValue(): void
    {
        $value = 'single';
        $result = $this->type->toDatabase($value, $this->driver);
        $this->assertEquals([$value], $result);
        $this->assertIsArray($result);
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
     * Test toPHP with indexed array
     *
     * @return void
     */
    public function testToPHPWithIndexedArray(): void
    {
        $array = ['a', 'b', 'c'];
        $result = $this->type->toPHP($array, $this->driver);
        $this->assertEquals($array, $result);
        $this->assertEquals([0, 1, 2], array_keys($result));
    }

    /**
     * Test toPHP with associative array
     *
     * @return void
     */
    public function testToPHPWithAssociativeArray(): void
    {
        $array = ['key1' => 'a', 'key2' => 'b'];
        $result = $this->type->toPHP($array, $this->driver);
        $this->assertEquals(['a', 'b'], $result);
        $this->assertEquals([0, 1], array_keys($result));
    }

    /**
     * Test toPHP with single value
     *
     * @return void
     */
    public function testToPHPWithSingleValue(): void
    {
        $value = 'single';
        $result = $this->type->toPHP($value, $this->driver);
        $this->assertEquals([$value], $result);
        $this->assertIsArray($result);
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
     * Test marshal with indexed array
     *
     * @return void
     */
    public function testMarshalWithIndexedArray(): void
    {
        $array = ['a', 'b', 'c'];
        $result = $this->type->marshal($array);
        $this->assertEquals($array, $result);
    }

    /**
     * Test marshal with associative array
     *
     * @return void
     */
    public function testMarshalWithAssociativeArray(): void
    {
        $array = ['key1' => 'a', 'key2' => 'b'];
        $result = $this->type->marshal($array);
        $this->assertEquals(['a', 'b'], $result);
    }

    /**
     * Test marshal with single value
     *
     * @return void
     */
    public function testMarshalWithSingleValue(): void
    {
        $value = 'single';
        $result = $this->type->marshal($value);
        $this->assertEquals([$value], $result);
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

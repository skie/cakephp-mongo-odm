<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Type;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Type\StringType;
use Crustum\Mongo\Database\Type\TypeFactory;
use Crustum\Mongo\Database\Type\TypeInterface;

/**
 * Test case for TypeFactory
 */
class TypeFactoryTest extends TestCase
{
    /**
     * Tear down after each test
     *
     * @return void
     */
    protected function tearDown(): void
    {
        parent::tearDown();
        TypeFactory::clear();
    }

    /**
     * Test build returns type instance
     *
     * @return void
     */
    public function testBuild(): void
    {
        $type = TypeFactory::build('string');
        $this->assertInstanceOf(TypeInterface::class, $type);
        $this->assertEquals('string', $type->getName());
    }

    /**
     * Test build returns same instance for same name
     *
     * @return void
     */
    public function testBuildReturnsSameInstance(): void
    {
        $type1 = TypeFactory::build('string');
        $type2 = TypeFactory::build('string');
        $this->assertSame($type1, $type2);
    }

    /**
     * Test build returns default string type for unknown type
     *
     * @return void
     */
    public function testBuildReturnsDefaultForUnknownType(): void
    {
        $type = TypeFactory::build('unknown_type');
        $this->assertInstanceOf(TypeInterface::class, $type);
        $this->assertEquals('unknown_type', $type->getName());
    }

    /**
     * Test buildAll returns all types
     *
     * @return void
     */
    public function testBuildAll(): void
    {
        $types = TypeFactory::buildAll();
        $this->assertIsArray($types);
        $this->assertArrayHasKey('string', $types);
        $this->assertArrayHasKey('integer', $types);
        $this->assertArrayHasKey('objectid', $types);
    }

    /**
     * Test map registers new type
     *
     * @return void
     */
    public function testMap(): void
    {
        TypeFactory::map('custom', StringType::class);
        $type = TypeFactory::build('custom');
        $this->assertInstanceOf(StringType::class, $type);
    }

    /**
     * Test set sets type instance
     *
     * @return void
     */
    public function testSet(): void
    {
        $instance = new StringType('test');
        TypeFactory::set('test', $instance);
        $this->assertSame($instance, TypeFactory::build('test'));
    }

    /**
     * Test getMap returns type map
     *
     * @return void
     */
    public function testGetMap(): void
    {
        $map = TypeFactory::getMap();
        $this->assertIsArray($map);
        $this->assertArrayHasKey('string', $map);
    }

    /**
     * Test getMapped returns mapped class name
     *
     * @return void
     */
    public function testGetMapped(): void
    {
        $className = TypeFactory::getMapped('string');
        $this->assertEquals(StringType::class, $className);
    }

    /**
     * Test getMapped returns null for unknown type
     *
     * @return void
     */
    public function testGetMappedReturnsNullForUnknown(): void
    {
        $className = TypeFactory::getMapped('unknown');
        $this->assertNull($className);
    }

    /**
     * Test clear clears built types
     *
     * @return void
     */
    public function testClear(): void
    {
        $type1 = TypeFactory::build('string');
        TypeFactory::clear();
        $type2 = TypeFactory::build('string');
        $this->assertNotSame($type1, $type2);
    }
}

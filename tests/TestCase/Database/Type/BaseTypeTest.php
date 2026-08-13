<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Type;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Driver\MongoDriver;
use Crustum\Mongo\Database\Type\BaseType;
use Crustum\Mongo\Database\Type\TypeInterface;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Test case for BaseType
 */
#[CoversClass(BaseType::class)]
class BaseTypeTest extends TestCase
{
    /**
     * @var \Crustum\Mongo\Database\Type\BaseType
     */
    protected BaseType $type;

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
        $this->type = new class ('test_type') extends BaseType {
            public function toDatabase(mixed $value, MongoDriver $driver): mixed
            {
                return $value;
            }

            public function toPHP(mixed $value, MongoDriver $driver): mixed
            {
                return $value;
            }
        };
        $this->driver = $this->createStub(MongoDriver::class);
    }

    /**
     * Test getName returns type name
     *
     * @return void
     */
    public function testGetName(): void
    {
        $this->assertEquals('test_type', $this->type->getName());
    }

    /**
     * Test getName returns null when no name provided
     *
     * @return void
     */
    public function testGetNameReturnsNull(): void
    {
        $type = new class extends BaseType {
            public function toDatabase(mixed $value, MongoDriver $driver): mixed
            {
                return $value;
            }

            public function toPHP(mixed $value, MongoDriver $driver): mixed
            {
                return $value;
            }
        };
        $this->assertNull($type->getName());
    }

    /**
     * Test getBaseType returns type name
     *
     * @return void
     */
    public function testGetBaseType(): void
    {
        $this->assertEquals('test_type', $this->type->getBaseType());
    }

    /**
     * Test marshal returns value as-is
     *
     * @return void
     */
    public function testMarshal(): void
    {
        $value = 'test_value';
        $result = $this->type->marshal($value);
        $this->assertEquals($value, $result);
    }

    /**
     * Test implements TypeInterface
     *
     * @return void
     */
    public function testImplementsTypeInterface(): void
    {
        $this->assertInstanceOf(TypeInterface::class, $this->type);
    }
}

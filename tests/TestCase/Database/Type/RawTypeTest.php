<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Type;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Driver\MongoDriver;
use Crustum\Mongo\Database\Type\RawType;

/**
 * Test case for RawType
 */
class RawTypeTest extends TestCase
{
    /**
     * @var \Crustum\Mongo\Database\Type\RawType
     */
    protected RawType $type;

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
        $this->type = new RawType('raw');
        $this->driver = $this->createStub(MongoDriver::class);
    }

    /**
     * Test toDatabase returns value as-is
     *
     * @return void
     */
    public function testToDatabaseReturnsValueAsIs(): void
    {
        $value = 'test';
        $result = $this->type->toDatabase($value, $this->driver);
        $this->assertSame($value, $result);
    }

    /**
     * Test toDatabase with array
     *
     * @return void
     */
    public function testToDatabaseWithArray(): void
    {
        $array = ['key' => 'value'];
        $result = $this->type->toDatabase($array, $this->driver);
        $this->assertSame($array, $result);
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
     * Test toPHP returns value as-is
     *
     * @return void
     */
    public function testToPHPReturnsValueAsIs(): void
    {
        $value = 'test';
        $result = $this->type->toPHP($value, $this->driver);
        $this->assertSame($value, $result);
    }

    /**
     * Test toPHP with array
     *
     * @return void
     */
    public function testToPHPWithArray(): void
    {
        $array = ['key' => 'value'];
        $result = $this->type->toPHP($array, $this->driver);
        $this->assertSame($array, $result);
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
     * Test marshal returns value as-is
     *
     * @return void
     */
    public function testMarshalReturnsValueAsIs(): void
    {
        $value = 'test';
        $result = $this->type->marshal($value);
        $this->assertSame($value, $result);
    }

    /**
     * Test marshal with array
     *
     * @return void
     */
    public function testMarshalWithArray(): void
    {
        $array = ['key' => 'value'];
        $result = $this->type->marshal($array);
        $this->assertSame($array, $result);
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

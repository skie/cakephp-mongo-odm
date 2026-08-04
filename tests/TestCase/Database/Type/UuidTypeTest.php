<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Type;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Driver\MongoDriver;
use Crustum\Mongo\Database\Type\UuidType;

/**
 * Test case for UuidType
 */
class UuidTypeTest extends TestCase
{
    /**
     * @var \Crustum\Mongo\Database\Type\UuidType
     */
    protected UuidType $type;

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
        $this->type = new UuidType('uuid');
        $this->driver = $this->createStub(MongoDriver::class);
    }

    /**
     * Test toDatabase with a uuid string
     *
     * @return void
     */
    public function testToDatabaseWithString(): void
    {
        $value = '550e8400-e29b-41d4-a716-446655440000';
        $this->assertSame($value, $this->type->toDatabase($value, $this->driver));
    }

    /**
     * Test toDatabase with scalar
     *
     * @return void
     */
    public function testToDatabaseWithScalar(): void
    {
        $this->assertSame('123', $this->type->toDatabase(123, $this->driver));
    }

    /**
     * Test toDatabase with empty values returns null
     *
     * @return void
     */
    public function testToDatabaseWithEmptyValuesReturnsNull(): void
    {
        $this->assertNull($this->type->toDatabase(null, $this->driver));
        $this->assertNull($this->type->toDatabase('', $this->driver));
        $this->assertNull($this->type->toDatabase(false, $this->driver));
    }

    /**
     * Test toPHP with string
     *
     * @return void
     */
    public function testToPHPWithString(): void
    {
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $this->type->toPHP('550e8400-e29b-41d4-a716-446655440000', $this->driver));
    }

    /**
     * Test toPHP with null
     *
     * @return void
     */
    public function testToPHPWithNull(): void
    {
        $this->assertNull($this->type->toPHP(null, $this->driver));
    }

    /**
     * Test newId generates a valid uuid
     *
     * @return void
     */
    public function testNewId(): void
    {
        $id = $this->type->newId();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id);
    }

    /**
     * Test marshal with string
     *
     * @return void
     */
    public function testMarshalWithString(): void
    {
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $this->type->marshal('550e8400-e29b-41d4-a716-446655440000'));
    }

    /**
     * Test marshal with null returns null
     *
     * @return void
     */
    public function testMarshalWithNull(): void
    {
        $this->assertNull($this->type->marshal(null));
    }

    /**
     * Test marshal with array returns null
     *
     * @return void
     */
    public function testMarshalWithArray(): void
    {
        $this->assertNull($this->type->marshal(['test']));
    }
}

<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Type;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Driver\MongoDriver;
use Crustum\Mongo\Database\Type\IdType;
use Crustum\Mongo\Database\Type\ObjectIdType;
use InvalidArgumentException;
use MongoDB\BSON\ObjectId;

/**
 * Test case for IdType
 */
class IdTypeTest extends TestCase
{
    /**
     * @var \Crustum\Mongo\Database\Type\IdType
     */
    protected IdType $type;

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
        $this->type = new IdType('id');
        $this->driver = $this->createStub(MongoDriver::class);
    }

    /**
     * Test IdType extends ObjectIdType
     *
     * @return void
     */
    public function testExtendsObjectIdType(): void
    {
        $this->assertInstanceOf(ObjectIdType::class, $this->type);
    }

    /**
     * Test toDatabase with ObjectId instance
     *
     * @return void
     */
    public function testToDatabaseWithObjectId(): void
    {
        $objectId = new ObjectId();
        $result = $this->type->toDatabase($objectId, $this->driver);
        $this->assertSame($objectId, $result);
    }

    /**
     * Test toDatabase with string
     *
     * @return void
     */
    public function testToDatabaseWithString(): void
    {
        $string = '507f1f77bcf86cd799439011';
        $result = $this->type->toDatabase($string, $this->driver);
        $this->assertInstanceOf(ObjectId::class, $result);
        $this->assertEquals($string, (string)$result);
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
     * Test toDatabase with invalid string throws exception
     *
     * @return void
     */
    public function testToDatabaseWithInvalidStringThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->type->toDatabase('invalid', $this->driver);
    }

    /**
     * Test toPHP with ObjectId
     *
     * @return void
     */
    public function testToPHPWithObjectId(): void
    {
        $objectId = new ObjectId();
        $result = $this->type->toPHP($objectId, $this->driver);
        $this->assertIsString($result);
        $this->assertEquals((string)$objectId, $result);
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
     * Test marshal with valid string
     *
     * @return void
     */
    public function testMarshalWithValidString(): void
    {
        $string = '507f1f77bcf86cd799439011';
        $result = $this->type->marshal($string);
        $this->assertEquals($string, $result);
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

<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Type;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Driver\MongoDriver;
use Crustum\Mongo\Database\Type\ObjectIdType;
use InvalidArgumentException;
use MongoDB\BSON\ObjectId;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Test case for ObjectIdType
 */
#[CoversClass(ObjectIdType::class)]
class ObjectIdTypeTest extends TestCase
{
    /**
     * @var \Crustum\Mongo\Database\Type\ObjectIdType
     */
    protected ObjectIdType $type;

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
        $this->type = new ObjectIdType('objectid');
        $this->driver = $this->createStub(MongoDriver::class);
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

    /**
     * Test isHex accepts 24-character hex and rejects other strings.
     *
     * @return void
     */
    public function testIsHex(): void
    {
        $this->assertTrue(ObjectIdType::isHex('507f1f77bcf86cd799439011'));
        $this->assertTrue(ObjectIdType::isHex('507F1F77BCF86CD799439011'));
        $this->assertFalse(ObjectIdType::isHex('invalid'));
        $this->assertFalse(ObjectIdType::isHex('507f1f77bcf86cd79943901'));
    }

    /**
     * Test tryFrom converts hex strings and leaves other values unchanged.
     *
     * @return void
     */
    public function testTryFrom(): void
    {
        $hex = '507f1f77bcf86cd799439011';
        $converted = ObjectIdType::tryFrom($hex);
        $this->assertInstanceOf(ObjectId::class, $converted);
        $this->assertSame($hex, (string)$converted);

        $existing = new ObjectId($hex);
        $this->assertSame($existing, ObjectIdType::tryFrom($existing));
        $this->assertSame('tag-name', ObjectIdType::tryFrom('tag-name'));
        $this->assertSame(12, ObjectIdType::tryFrom(12));
    }
}

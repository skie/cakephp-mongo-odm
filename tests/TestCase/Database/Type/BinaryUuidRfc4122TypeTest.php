<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Type;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Driver\MongoDriver;
use Crustum\Mongo\Database\Type\BinaryType;
use Crustum\Mongo\Database\Type\BinaryUuidRfc4122Type;
use MongoDB\BSON\Binary;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Test case for BinaryUuidRfc4122Type
 */
#[CoversClass(BinaryUuidRfc4122Type::class)]
class BinaryUuidRfc4122TypeTest extends TestCase
{
    /**
     * @var \Crustum\Mongo\Database\Type\BinaryUuidRfc4122Type
     */
    protected BinaryUuidRfc4122Type $type;

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
        $this->type = new BinaryUuidRfc4122Type('bin_uuid_rfc4122');
        $this->driver = $this->createStub(MongoDriver::class);
    }

    /**
     * Test BinaryUuidRfc4122Type extends BinaryType
     *
     * @return void
     */
    public function testExtendsBinaryType(): void
    {
        $this->assertInstanceOf(BinaryType::class, $this->type);
    }

    /**
     * Test toDatabase uses RFC 4122 UUID subtype
     *
     * @return void
     */
    public function testToDatabaseUsesRfc4122Subtype(): void
    {
        $string = '1234567890123456';
        $result = $this->type->toDatabase($string, $this->driver);
        $this->assertInstanceOf(Binary::class, $result);
        $this->assertEquals($string, $result->getData());
        $this->assertEquals(Binary::TYPE_UUID, $result->getType());
    }

    /**
     * Test toPHP with Binary RFC 4122 UUID
     *
     * @return void
     */
    public function testToPHPWithBinaryUuid(): void
    {
        $binary = new Binary('1234567890123456', Binary::TYPE_UUID);
        $this->assertEquals('1234567890123456', $this->type->toPHP($binary, $this->driver));
    }
}

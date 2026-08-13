<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Type;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Driver\MongoDriver;
use Crustum\Mongo\Database\Type\BinaryType;
use Crustum\Mongo\Database\Type\BinaryUuidType;
use MongoDB\BSON\Binary;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Test case for BinaryUuidType
 */
#[CoversClass(BinaryUuidType::class)]
class BinaryUuidTypeTest extends TestCase
{
    /**
     * @var \Crustum\Mongo\Database\Type\BinaryUuidType
     */
    protected BinaryUuidType $type;

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
        $this->type = new BinaryUuidType('bin_uuid');
        $this->driver = $this->createStub(MongoDriver::class);
    }

    /**
     * Test BinaryUuidType extends BinaryType
     *
     * @return void
     */
    public function testExtendsBinaryType(): void
    {
        $this->assertInstanceOf(BinaryType::class, $this->type);
    }

    /**
     * Test toDatabase uses UUID subtype
     *
     * @return void
     */
    public function testToDatabaseUsesUuidSubtype(): void
    {
        $uuid = '1234567890123456';
        $result = $this->type->toDatabase($uuid, $this->driver);
        $this->assertInstanceOf(Binary::class, $result);
        $this->assertEquals($uuid, $result->getData());
        $this->assertEquals(Binary::TYPE_OLD_UUID, $result->getType());
    }

    /**
     * Test toPHP with Binary UUID
     *
     * @return void
     */
    public function testToPHPWithBinaryUuid(): void
    {
        $uuid = '1234567890123456';
        $binary = new Binary($uuid, Binary::TYPE_OLD_UUID);
        $result = $this->type->toPHP($binary, $this->driver);
        $this->assertEquals($uuid, $result);
    }
}

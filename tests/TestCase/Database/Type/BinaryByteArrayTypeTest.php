<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Type;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Driver\MongoDriver;
use Crustum\Mongo\Database\Type\BinaryByteArrayType;
use Crustum\Mongo\Database\Type\BinaryType;
use MongoDB\BSON\Binary;

/**
 * Test case for BinaryByteArrayType
 */
class BinaryByteArrayTypeTest extends TestCase
{
    /**
     * @var \Crustum\Mongo\Database\Type\BinaryByteArrayType
     */
    protected BinaryByteArrayType $type;

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
        $this->type = new BinaryByteArrayType('bin_bytearray');
        $this->driver = $this->createStub(MongoDriver::class);
    }

    /**
     * Test BinaryByteArrayType extends BinaryType
     *
     * @return void
     */
    public function testExtendsBinaryType(): void
    {
        $this->assertInstanceOf(BinaryType::class, $this->type);
    }

    /**
     * Test toDatabase uses byte array subtype
     *
     * @return void
     */
    public function testToDatabaseUsesByteArraySubtype(): void
    {
        $string = 'byte-array-data';
        $result = $this->type->toDatabase($string, $this->driver);
        $this->assertInstanceOf(Binary::class, $result);
        $this->assertEquals($string, $result->getData());
        $this->assertEquals(Binary::TYPE_OLD_BINARY, $result->getType());
    }

    /**
     * Test toPHP with Binary byte array
     *
     * @return void
     */
    public function testToPHPWithBinaryByteArray(): void
    {
        $binary = new Binary('byte-array-data', Binary::TYPE_OLD_BINARY);
        $this->assertEquals('byte-array-data', $this->type->toPHP($binary, $this->driver));
    }
}

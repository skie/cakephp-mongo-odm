<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Type;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Driver\MongoDriver;
use Crustum\Mongo\Database\Type\BinaryCustomType;
use Crustum\Mongo\Database\Type\BinaryType;
use MongoDB\BSON\Binary;

/**
 * Test case for BinaryCustomType
 */
class BinaryCustomTypeTest extends TestCase
{
    /**
     * @var \Crustum\Mongo\Database\Type\BinaryCustomType
     */
    protected BinaryCustomType $type;

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
        $this->type = new BinaryCustomType('bin_custom');
        $this->driver = $this->createStub(MongoDriver::class);
    }

    /**
     * Test BinaryCustomType extends BinaryType
     *
     * @return void
     */
    public function testExtendsBinaryType(): void
    {
        $this->assertInstanceOf(BinaryType::class, $this->type);
    }

    /**
     * Test toDatabase uses user-defined subtype
     *
     * @return void
     */
    public function testToDatabaseUsesUserDefinedSubtype(): void
    {
        $string = 'custom-data';
        $result = $this->type->toDatabase($string, $this->driver);
        $this->assertInstanceOf(Binary::class, $result);
        $this->assertEquals($string, $result->getData());
        $this->assertEquals(Binary::TYPE_USER_DEFINED, $result->getType());
    }

    /**
     * Test toPHP with Binary custom
     *
     * @return void
     */
    public function testToPHPWithBinaryCustom(): void
    {
        $binary = new Binary('custom-data', Binary::TYPE_USER_DEFINED);
        $this->assertEquals('custom-data', $this->type->toPHP($binary, $this->driver));
    }
}

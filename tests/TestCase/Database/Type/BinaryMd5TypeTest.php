<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Type;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Driver\MongoDriver;
use Crustum\Mongo\Database\Type\BinaryMd5Type;
use Crustum\Mongo\Database\Type\BinaryType;
use MongoDB\BSON\Binary;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Test case for BinaryMd5Type
 */
#[CoversClass(BinaryMd5Type::class)]
class BinaryMd5TypeTest extends TestCase
{
    /**
     * @var \Crustum\Mongo\Database\Type\BinaryMd5Type
     */
    protected BinaryMd5Type $type;

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
        $this->type = new BinaryMd5Type('bin_md5');
        $this->driver = $this->createStub(MongoDriver::class);
    }

    /**
     * Test BinaryMd5Type extends BinaryType
     *
     * @return void
     */
    public function testExtendsBinaryType(): void
    {
        $this->assertInstanceOf(BinaryType::class, $this->type);
    }

    /**
     * Test toDatabase uses MD5 subtype
     *
     * @return void
     */
    public function testToDatabaseUsesMd5Subtype(): void
    {
        $string = 'test-md5-data';
        $result = $this->type->toDatabase($string, $this->driver);
        $this->assertInstanceOf(Binary::class, $result);
        $this->assertEquals($string, $result->getData());
        $this->assertEquals(Binary::TYPE_MD5, $result->getType());
    }

    /**
     * Test toPHP with Binary MD5
     *
     * @return void
     */
    public function testToPHPWithBinaryMd5(): void
    {
        $binary = new Binary('test-md5-data', Binary::TYPE_MD5);
        $result = $this->type->toPHP($binary, $this->driver);
        $this->assertEquals('test-md5-data', $result);
    }
}

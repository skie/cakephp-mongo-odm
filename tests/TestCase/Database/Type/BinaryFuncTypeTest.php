<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Type;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Driver\MongoDriver;
use Crustum\Mongo\Database\Type\BinaryFuncType;
use Crustum\Mongo\Database\Type\BinaryType;
use MongoDB\BSON\Binary;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Test case for BinaryFuncType
 */
#[CoversClass(BinaryFuncType::class)]
class BinaryFuncTypeTest extends TestCase
{
    /**
     * @var \Crustum\Mongo\Database\Type\BinaryFuncType
     */
    protected BinaryFuncType $type;

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
        $this->type = new BinaryFuncType('bin_func');
        $this->driver = $this->createStub(MongoDriver::class);
    }

    /**
     * Test BinaryFuncType extends BinaryType
     *
     * @return void
     */
    public function testExtendsBinaryType(): void
    {
        $this->assertInstanceOf(BinaryType::class, $this->type);
    }

    /**
     * Test toDatabase uses function subtype
     *
     * @return void
     */
    public function testToDatabaseUsesFunctionSubtype(): void
    {
        $string = 'function-data';
        $result = $this->type->toDatabase($string, $this->driver);
        $this->assertInstanceOf(Binary::class, $result);
        $this->assertEquals($string, $result->getData());
        $this->assertEquals(Binary::TYPE_FUNCTION, $result->getType());
    }

    /**
     * Test toPHP with Binary function
     *
     * @return void
     */
    public function testToPHPWithBinaryFunction(): void
    {
        $binary = new Binary('function-data', Binary::TYPE_FUNCTION);
        $this->assertEquals('function-data', $this->type->toPHP($binary, $this->driver));
    }
}

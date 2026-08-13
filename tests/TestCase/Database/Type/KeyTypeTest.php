<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Type;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Driver\MongoDriver;
use Crustum\Mongo\Database\Type\HashType;
use Crustum\Mongo\Database\Type\KeyType;
use PHPUnit\Framework\Attributes\CoversClass;
use stdClass;

/**
 * Tests for KeyType
 */
#[CoversClass(KeyType::class)]
class KeyTypeTest extends TestCase
{
    /**
     * @var \Crustum\Mongo\Database\Type\KeyType
     */
    protected KeyType $type;

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
        $this->type = new KeyType('key');
        $this->driver = $this->createStub(MongoDriver::class);
    }

    /**
     * Test KeyType extends HashType
     *
     * @return void
     */
    public function testExtendsHashType(): void
    {
        $this->assertInstanceOf(HashType::class, $this->type);
    }

    /**
     * Test toDatabase converts assoc array to stdClass
     *
     * @return void
     */
    public function testToDatabase(): void
    {
        $result = $this->type->toDatabase(['a' => 1, 'b' => 2], $this->driver);

        $this->assertInstanceOf(stdClass::class, $result);
        $this->assertEquals((object)['a' => 1, 'b' => 2], $result);
    }

    /**
     * Test toDatabase with null
     *
     * @return void
     */
    public function testToDatabaseWithNull(): void
    {
        $this->assertNull($this->type->toDatabase(null, $this->driver));
    }

    /**
     * Test toPHP converts stdClass to assoc array
     *
     * @return void
     */
    public function testToPHP(): void
    {
        $result = $this->type->toPHP((object)['a' => 1, 'b' => 2], $this->driver);

        $this->assertSame(['a' => 1, 'b' => 2], $result);
    }

    /**
     * Test toPHP with array passes through
     *
     * @return void
     */
    public function testToPHPWithArray(): void
    {
        $result = $this->type->toPHP(['a' => 1], $this->driver);

        $this->assertSame(['a' => 1], $result);
    }

    /**
     * Test marshal with array
     *
     * @return void
     */
    public function testMarshal(): void
    {
        $this->assertSame(['a' => 1], $this->type->marshal(['a' => 1]));
        $this->assertNull($this->type->marshal(null));
    }
}

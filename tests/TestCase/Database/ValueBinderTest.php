<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\ValueBinder;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests the ValueBinder class.
 *
 * Adapted from cake50/tests/TestCase/Database/ValueBinderTest.php for the
 * Mongo-specific binding shape (no positional `?` placeholders, no `placeholder`
 * metadata key).
 *
 * @inspired-by \Cake\Test\TestCase\Database\ValueBinderTest
 */
#[CoversClass(ValueBinder::class)]
class ValueBinderTest extends TestCase
{
    /**
     * Test the bind method.
     *
     * @return void
     */
    public function testBind(): void
    {
        $valueBinder = new ValueBinder();
        $valueBinder->bind('c0', 'value0');
        $valueBinder->bind('c1', 1, 'int');
        $valueBinder->bind('c2', 'value2');

        $this->assertCount(3, $valueBinder->bindings());

        $expected = [
            'c0' => 'value0',
            'c1' => 1,
            'c2' => 'value2',
        ];

        $this->assertEquals($expected, $valueBinder->bindings());
    }

    /**
     * Test the bind method returns $this for chaining.
     *
     * @return void
     */
    public function testBindReturnsSelf(): void
    {
        $valueBinder = new ValueBinder();
        $this->assertSame($valueBinder, $valueBinder->bind('c0', 'value0'));
    }

    /**
     * Test the value method.
     *
     * @return void
     */
    public function testValue(): void
    {
        $valueBinder = new ValueBinder();
        $valueBinder->bind('c0', 'value0', 'string');

        $this->assertSame('value0', $valueBinder->value('c0'));
        $this->assertNull($valueBinder->value('missing'));
    }

    /**
     * Test the type method.
     *
     * @return void
     */
    public function testType(): void
    {
        $valueBinder = new ValueBinder();
        $valueBinder->bind('c0', 'value0', 'string');

        $this->assertSame('string', $valueBinder->type('c0'));
        $this->assertNull($valueBinder->type('c1'));
        $this->assertNull($valueBinder->type('missing'));
    }

    /**
     * Test the has method.
     *
     * @return void
     */
    public function testHas(): void
    {
        $valueBinder = new ValueBinder();
        $valueBinder->bind('c0', 'value0');

        $this->assertTrue($valueBinder->has('c0'));
        $this->assertFalse($valueBinder->has('c1'));
    }

    /**
     * Test the reset method clears all bindings.
     *
     * @return void
     */
    public function testReset(): void
    {
        $valueBinder = new ValueBinder();
        $valueBinder->bind('c0', 'value0');
        $valueBinder->bind('c1', 1);

        $valueBinder->reset();

        $this->assertSame([], $valueBinder->bindings());
        $this->assertFalse($valueBinder->has('c0'));
    }

    /**
     * Test that binding the same placeholder overwrites the previous value.
     *
     * @return void
     */
    public function testBindOverwrites(): void
    {
        $valueBinder = new ValueBinder();
        $valueBinder->bind('c0', 'value0', 'string');
        $valueBinder->bind('c0', 123, 'int');

        $this->assertCount(1, $valueBinder->bindings());
        $this->assertSame(123, $valueBinder->value('c0'));
        $this->assertSame('int', $valueBinder->type('c0'));
    }
}

<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Aggregation\Stage;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Aggregation\AggregationBuilder;
use Crustum\Mongo\Database\Aggregation\Stage\RawStage;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for RawStage
 *
 * @rewritten-from \Doctrine\ODM\MongoDB\Tests\Aggregation\Stage\OperatorTest
 */
#[CoversClass(RawStage::class)]
class RawStageTest extends TestCase
{
    /**
     * Test array value is kept verbatim.
     *
     * @return void
     */
    public function testArrayValue(): void
    {
        $stage = new RawStage(new AggregationBuilder(), '$limit', ['limit' => 10]);

        $this->assertSame(['$limit' => ['limit' => 10]], $stage->getExpression());
    }

    /**
     * Test scalar limit value is normalized to the limit shape.
     *
     * @return void
     */
    public function testScalarLimitValue(): void
    {
        $stage = new RawStage(new AggregationBuilder(), '$limit', 10);

        $this->assertSame(['$limit' => ['limit' => 10]], $stage->getExpression());
    }

    /**
     * Test scalar non-limit value is normalized to the value shape.
     *
     * @return void
     */
    public function testScalarValue(): void
    {
        $stage = new RawStage(new AggregationBuilder(), '$custom', 'payload');

        $this->assertSame(['$custom' => ['value' => 'payload']], $stage->getExpression());
    }

    /**
     * Test fromWire preserves a wire-format stage verbatim.
     *
     * @return void
     */
    public function testFromWire(): void
    {
        $stage = RawStage::fromWire(new AggregationBuilder(), ['$limit' => 5]);

        $this->assertSame(['$limit' => 5], $stage->getExpression());
    }
}

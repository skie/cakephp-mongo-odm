<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Aggregation\Stage;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Aggregation\AggregationBuilder;
use Crustum\Mongo\Database\Aggregation\Stage\Set;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for Set stage
 */
#[CoversClass(Set::class)]
class SetTest extends TestCase
{
    /**
     * Test basic field setting
     *
     * @return void
     */
    public function testBasicField(): void
    {
        $builder = new AggregationBuilder();
        $stage = $builder->set();
        $result = $stage->field('total', 100);

        $this->assertSame($stage, $result);
        $expression = $stage->getExpression();
        $this->assertArrayHasKey('$set', $expression);
        $this->assertEquals(['total' => 100], $expression['$set']);
    }

    /**
     * Test multiple fields
     *
     * @return void
     */
    public function testMultipleFields(): void
    {
        $builder = new AggregationBuilder();
        $stage = $builder->set();
        $stage->field('field1', 'value1')
            ->field('field2', 'value2');

        $expression = $stage->getExpression();
        $this->assertCount(2, $expression['$set']);
    }

    /**
     * Test method chaining
     *
     * @return void
     */
    public function testMethodChaining(): void
    {
        $builder = new AggregationBuilder();
        $stage = $builder->set();
        $result = $stage->field('field1', 'value1')
            ->field('field2', 'value2');

        $this->assertSame($stage, $result);
    }
}

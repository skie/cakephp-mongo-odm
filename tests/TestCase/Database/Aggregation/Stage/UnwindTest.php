<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Aggregation\Stage;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Aggregation\AggregationBuilder;
use Crustum\Mongo\Database\Aggregation\Stage\Unwind;

/**
 * Tests for Unwind stage
 */
class UnwindTest extends TestCase
{
    /**
     * Test basic unwind stage
     *
     * @return void
     */
    public function testBasicUnwind(): void
    {
        $builder = new AggregationBuilder();
        $builder->unwind('$tags');

        $pipeline = $builder->getPipeline();
        $this->assertCount(1, $pipeline);
        $this->assertEquals(['$unwind' => ['path' => '$tags']], $pipeline[0]);
    }

    /**
     * Test unwind with options
     *
     * @return void
     */
    public function testUnwindWithOptions(): void
    {
        $builder = new AggregationBuilder();
        $builder->unwind('$tags', ['preserveNullAndEmptyArrays' => true]);

        $pipeline = $builder->getPipeline();
        $this->assertTrue($pipeline[0]['$unwind']['preserveNullAndEmptyArrays']);
    }

    /**
     * Test unwind via dedicated stage instance
     *
     * @return void
     */
    public function testUnwindStageInstance(): void
    {
        $builder = new AggregationBuilder();
        $stage = new Unwind($builder, '$tags');

        $this->assertEquals(['$unwind' => ['path' => '$tags']], $stage->getExpression());
    }

    /**
     * Test preserveNullAndEmptyArrays fluent
     *
     * @return void
     */
    public function testPreserveNullAndEmptyArrays(): void
    {
        $builder = new AggregationBuilder();
        $stage = new Unwind($builder, '$tags');
        $stage->preserveNullAndEmptyArrays();

        $expression = $stage->getExpression();
        $this->assertTrue($expression['$unwind']['preserveNullAndEmptyArrays']);
    }

    /**
     * Test includeArrayIndex fluent
     *
     * @return void
     */
    public function testIncludeArrayIndex(): void
    {
        $builder = new AggregationBuilder();
        $stage = new Unwind($builder, '$tags');
        $stage->includeArrayIndex('idx');

        $expression = $stage->getExpression();
        $this->assertEquals('idx', $expression['$unwind']['includeArrayIndex']);
    }

    /**
     * Test outputPath fluent
     *
     * @return void
     */
    public function testOutputPath(): void
    {
        $builder = new AggregationBuilder();
        $stage = new Unwind($builder, '$tags');
        $stage->outputPath('tag');

        $expression = $stage->getExpression();
        $this->assertEquals('tag', $expression['$unwind']['outputPath']);
    }

    /**
     * Test fluent chaining returns same stage
     *
     * @return void
     */
    public function testFluentChaining(): void
    {
        $builder = new AggregationBuilder();
        $stage = new Unwind($builder, '$tags');
        $this->assertSame($stage, $stage->preserveNullAndEmptyArrays());
    }
}

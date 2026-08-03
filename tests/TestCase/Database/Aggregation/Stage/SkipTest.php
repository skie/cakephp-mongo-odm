<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Aggregation\Stage;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Aggregation\AggregationBuilder;

/**
 * Tests for Skip stage
 */
class SkipTest extends TestCase
{
    /**
     * Test basic skip
     *
     * @return void
     */
    public function testBasicSkip(): void
    {
        $builder = new AggregationBuilder();
        $builder->skip(20);

        $pipeline = $builder->getPipeline();
        $this->assertCount(1, $pipeline);
        $this->assertArrayHasKey('$skip', $pipeline[0]);
        $this->assertEquals(20, $pipeline[0]['$skip']);
    }

    /**
     * Test skip in pipeline
     *
     * @return void
     */
    public function testSkipInPipeline(): void
    {
        $builder = new AggregationBuilder();
        $builder->match(['status' => 'active'])
            ->sort(['created' => -1])
            ->skip(10)
            ->limit(5);

        $pipeline = $builder->getPipeline();
        $this->assertCount(4, $pipeline);
        $this->assertEquals(10, $pipeline[2]['$skip']);
        $this->assertEquals(5, $pipeline[3]['$limit']);
    }

    /**
     * Test method chaining
     *
     * @return void
     */
    public function testMethodChaining(): void
    {
        $builder = new AggregationBuilder();
        $result = $builder->skip(20);

        $this->assertSame($builder, $result);
    }
}

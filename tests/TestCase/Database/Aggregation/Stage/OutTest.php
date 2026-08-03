<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Aggregation\Stage;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Aggregation\AggregationBuilder;
use Crustum\Mongo\Database\Aggregation\Stage\Out;

/**
 * Test case for Out aggregation stage
 */
class OutTest extends TestCase
{
    /**
     * Test basic out stage
     */
    public function testBasicOut(): void
    {
        $builder = new AggregationBuilder();
        $builder->out('output_collection');

        $pipeline = $builder->getPipeline();
        $this->assertCount(1, $pipeline);
        $this->assertArrayHasKey('$out', $pipeline[0]);
        $this->assertEquals('output_collection', $pipeline[0]['$out']);
    }

    /**
     * Test out stage directly
     */
    public function testOutStageDirect(): void
    {
        $builder = new AggregationBuilder();
        $stage = new Out($builder, 'output_collection');

        $expression = $stage->getExpression();
        $this->assertArrayHasKey('$out', $expression);
        $this->assertEquals('output_collection', $expression['$out']);
    }

    /**
     * Test out in pipeline chain
     */
    public function testOutInPipeline(): void
    {
        $builder = new AggregationBuilder();
        $builder->match(['status' => 'active'])
            ->out('active_collection');

        $pipeline = $builder->getPipeline();
        $this->assertCount(2, $pipeline);
        $this->assertArrayHasKey('$match', $pipeline[0]);
        $this->assertArrayHasKey('$out', $pipeline[1]);
    }
}

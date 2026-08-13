<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Aggregation\Stage;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Aggregation\AggregationBuilder;
use Crustum\Mongo\Database\Aggregation\Stage\Merge;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Test case for Merge aggregation stage
 */
#[CoversClass(Merge::class)]
class MergeTest extends TestCase
{
    /**
     * Test basic merge stage
     */
    public function testBasicMerge(): void
    {
        $builder = new AggregationBuilder();
        $builder->merge('output_collection');

        $pipeline = $builder->getPipeline();
        $this->assertCount(1, $pipeline);
        $this->assertArrayHasKey('$merge', $pipeline[0]);

        $mergeExpr = $pipeline[0]['$merge'];
        $this->assertEquals('output_collection', $mergeExpr['into']);
    }

    /**
     * Test merge with whenMatched
     */
    public function testMergeWithWhenMatched(): void
    {
        $builder = new AggregationBuilder();
        $builder->merge('output_collection')
            ->whenMatched('replace');

        $pipeline = $builder->getPipeline();
        $mergeExpr = $pipeline[0]['$merge'];
        $this->assertEquals('replace', $mergeExpr['whenMatched']);
    }

    /**
     * Test merge with whenNotMatched
     */
    public function testMergeWithWhenNotMatched(): void
    {
        $builder = new AggregationBuilder();
        $builder->merge('output_collection')
            ->whenNotMatched('insert');

        $pipeline = $builder->getPipeline();
        $mergeExpr = $pipeline[0]['$merge'];
        $this->assertEquals('insert', $mergeExpr['whenNotMatched']);
    }

    /**
     * Test merge stage directly
     */
    public function testMergeStageDirect(): void
    {
        $builder = new AggregationBuilder();
        $stage = new Merge($builder, 'output_collection');
        $stage->whenMatched('merge')
            ->whenNotMatched('insert')
            ->let(['var1' => '$field1']);

        $expression = $stage->getExpression();
        $this->assertArrayHasKey('$merge', $expression);
        $mergeExpr = $expression['$merge'];
        $this->assertEquals('output_collection', $mergeExpr['into']);
        $this->assertEquals('merge', $mergeExpr['whenMatched']);
        $this->assertEquals('insert', $mergeExpr['whenNotMatched']);
        $this->assertEquals(['var1' => '$field1'], $mergeExpr['let']);
    }
}

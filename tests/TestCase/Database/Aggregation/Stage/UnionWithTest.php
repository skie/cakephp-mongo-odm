<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Aggregation\Stage;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Aggregation\AggregationBuilder;
use Crustum\Mongo\Database\Aggregation\Stage\MatchStage;
use Crustum\Mongo\Database\Aggregation\Stage\UnionWith;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Test case for UnionWith aggregation stage
 *
 * @rewritten-from \Doctrine\ODM\MongoDB\Tests\Aggregation\Stage\UnionWithTest
 */
#[CoversClass(UnionWith::class)]
class UnionWithTest extends TestCase
{
    /**
     * Test basic unionWith stage
     */
    public function testBasicUnionWith(): void
    {
        $builder = new AggregationBuilder();
        $builder->unionWith('other_collection');

        $pipeline = $builder->getPipeline();
        $this->assertCount(1, $pipeline);
        $this->assertArrayHasKey('$unionWith', $pipeline[0]);

        $unionExpr = $pipeline[0]['$unionWith'];
        $this->assertEquals('other_collection', $unionExpr['coll']);
    }

    /**
     * Test unionWith with pipeline
     */
    public function testUnionWithWithPipeline(): void
    {
        $builder = new AggregationBuilder();
        $builder->unionWith('other_collection')
            ->pipeline([
                ['$match' => ['status' => 'active']],
                ['$limit' => 10],
            ]);

        $pipeline = $builder->getPipeline();
        $unionExpr = $pipeline[0]['$unionWith'];
        $this->assertArrayHasKey('pipeline', $unionExpr);
        $this->assertCount(2, $unionExpr['pipeline']);
    }

    /**
     * Test unionWith with a builder closure sub-pipeline.
     */
    public function testUnionWithWithPipelineClosure(): void
    {
        $builder = new AggregationBuilder();
        $builder->unionWith('other_collection')
            ->pipeline(fn(AggregationBuilder $sub): MatchStage => $sub->match(['status' => 'active']));

        $pipeline = $builder->getPipeline();
        $this->assertSame(
            [['$match' => ['status' => 'active']]],
            $pipeline[0]['$unionWith']['pipeline'],
        );
    }

    /**
     * Test the pipeline cannot reference the top-level pipeline itself.
     */
    public function testUnionWithPipelineSelfReferenceThrows(): void
    {
        $builder = new AggregationBuilder();
        $stage = $builder->unionWith('other_collection');

        $this->expectException(InvalidArgumentException::class);
        $stage->pipeline($builder->getPipelineInstance());
    }

    /**
     * Test unionWith stage directly
     */
    public function testUnionWithStageDirect(): void
    {
        $builder = new AggregationBuilder();
        $stage = new UnionWith($builder, 'other_collection');
        $stage->pipeline([['$match' => ['status' => 'active']]]);

        $expression = $stage->getExpression();
        $this->assertArrayHasKey('$unionWith', $expression);
        $unionExpr = $expression['$unionWith'];
        $this->assertEquals('other_collection', $unionExpr['coll']);
        $this->assertArrayHasKey('pipeline', $unionExpr);
    }
}

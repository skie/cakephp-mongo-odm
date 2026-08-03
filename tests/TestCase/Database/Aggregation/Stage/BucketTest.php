<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Aggregation\Stage;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Aggregation\AggregationBuilder;
use Crustum\Mongo\Database\Aggregation\Stage\Bucket;

/**
 * Test case for Bucket aggregation stage
 */
class BucketTest extends TestCase
{
    /**
     * Test basic bucket stage
     */
    public function testBasicBucket(): void
    {
        $builder = new AggregationBuilder();
        $builder->bucket('$price', [0, 100, 200, 300]);

        $pipeline = $builder->getPipeline();
        $this->assertCount(1, $pipeline);
        $this->assertArrayHasKey('$bucket', $pipeline[0]);

        $bucketExpr = $pipeline[0]['$bucket'];
        $this->assertEquals('$price', $bucketExpr['groupBy']);
        $this->assertEquals([0, 100, 200, 300], $bucketExpr['boundaries']);
    }

    /**
     * Test bucket with default
     */
    public function testBucketWithDefault(): void
    {
        $builder = new AggregationBuilder();
        $builder->bucket('$price', [0, 100, 200])
            ->default('other');

        $pipeline = $builder->getPipeline();
        $bucketExpr = $pipeline[0]['$bucket'];
        $this->assertEquals('other', $bucketExpr['default']);
    }

    /**
     * Test bucket with output
     */
    public function testBucketWithOutput(): void
    {
        $builder = new AggregationBuilder();
        $builder->bucket('$price', [0, 100, 200])
            ->output([
                'count' => ['$sum' => 1],
                'avgPrice' => ['$avg' => '$price'],
            ]);

        $pipeline = $builder->getPipeline();
        $bucketExpr = $pipeline[0]['$bucket'];
        $this->assertArrayHasKey('output', $bucketExpr);
        $this->assertArrayHasKey('count', $bucketExpr['output']);
        $this->assertArrayHasKey('avgPrice', $bucketExpr['output']);
    }

    /**
     * Test bucket stage directly
     */
    public function testBucketStageDirect(): void
    {
        $builder = new AggregationBuilder();
        $stage = new Bucket($builder, '$price', [0, 100, 200]);
        $stage->default('other')
            ->output(['count' => ['$sum' => 1]]);

        $expression = $stage->getExpression();
        $this->assertArrayHasKey('$bucket', $expression);
        $bucketExpr = $expression['$bucket'];
        $this->assertEquals('$price', $bucketExpr['groupBy']);
        $this->assertEquals([0, 100, 200], $bucketExpr['boundaries']);
        $this->assertEquals('other', $bucketExpr['default']);
    }
}

<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Aggregation\Stage;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Aggregation\AggregationBuilder;
use Crustum\Mongo\Database\Aggregation\Stage\BucketAuto;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Test case for BucketAuto aggregation stage
 *
 * @rewritten-from \Doctrine\ODM\MongoDB\Tests\Aggregation\Stage\BucketAutoTest
 */
#[CoversClass(BucketAuto::class)]
class BucketAutoTest extends TestCase
{
    /**
     * Test basic bucketAuto stage
     */
    public function testBasicBucketAuto(): void
    {
        $builder = new AggregationBuilder();
        $builder->bucketAuto('$price', 5);

        $pipeline = $builder->getPipeline();
        $this->assertCount(1, $pipeline);
        $this->assertArrayHasKey('$bucketAuto', $pipeline[0]);

        $bucketAutoExpr = $pipeline[0]['$bucketAuto'];
        $this->assertEquals('$price', $bucketAutoExpr['groupBy']);
        $this->assertEquals(5, $bucketAutoExpr['buckets']);
    }

    /**
     * Test bucketAuto with output
     */
    public function testBucketAutoWithOutput(): void
    {
        $builder = new AggregationBuilder();
        $builder->bucketAuto('$price', 5)
            ->output([
                'count' => ['$sum' => 1],
                'avgPrice' => ['$avg' => '$price'],
            ]);

        $pipeline = $builder->getPipeline();
        $bucketAutoExpr = $pipeline[0]['$bucketAuto'];
        $this->assertArrayHasKey('output', $bucketAutoExpr);
        $this->assertArrayHasKey('count', $bucketAutoExpr['output']);
    }

    /**
     * Test bucketAuto with granularity
     */
    public function testBucketAutoWithGranularity(): void
    {
        $builder = new AggregationBuilder();
        $builder->bucketAuto('$price', 5)
            ->granularity('R5');

        $pipeline = $builder->getPipeline();
        $bucketAutoExpr = $pipeline[0]['$bucketAuto'];
        $this->assertEquals('R5', $bucketAutoExpr['granularity']);
    }

    /**
     * Test bucketAuto stage directly
     */
    public function testBucketAutoStageDirect(): void
    {
        $builder = new AggregationBuilder();
        $stage = new BucketAuto($builder, '$price', 10);
        $stage->granularity('R10')
            ->output(['count' => ['$sum' => 1]]);

        $expression = $stage->getExpression();
        $this->assertArrayHasKey('$bucketAuto', $expression);
        $bucketAutoExpr = $expression['$bucketAuto'];
        $this->assertEquals('$price', $bucketAutoExpr['groupBy']);
        $this->assertEquals(10, $bucketAutoExpr['buckets']);
        $this->assertEquals('R10', $bucketAutoExpr['granularity']);
    }
}

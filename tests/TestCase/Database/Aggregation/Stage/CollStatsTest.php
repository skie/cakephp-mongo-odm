<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Aggregation\Stage;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Aggregation\AggregationBuilder;
use Crustum\Mongo\Database\Aggregation\Stage\CollStats;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Test case for CollStats aggregation stage
 */
#[CoversClass(CollStats::class)]
class CollStatsTest extends TestCase
{
    /**
     * Test basic collStats stage
     */
    public function testBasicCollStats(): void
    {
        $builder = new AggregationBuilder();
        $builder->collStats();

        $pipeline = $builder->getPipeline();
        $this->assertCount(1, $pipeline);
        $this->assertArrayHasKey('$collStats', $pipeline[0]);
    }

    /**
     * Test collStats with latencyStats
     */
    public function testCollStatsWithLatencyStats(): void
    {
        $builder = new AggregationBuilder();
        $builder->collStats()
            ->latencyStats(true);

        $pipeline = $builder->getPipeline();
        $statsExpr = $pipeline[0]['$collStats'];
        $this->assertTrue($statsExpr['latencyStats']);
    }

    /**
     * Test collStats with storageStats
     */
    public function testCollStatsWithStorageStats(): void
    {
        $builder = new AggregationBuilder();
        $builder->collStats()
            ->storageStats(true);

        $pipeline = $builder->getPipeline();
        $statsExpr = $pipeline[0]['$collStats'];
        $this->assertTrue($statsExpr['storageStats']);
    }

    /**
     * Test collStats with count
     */
    public function testCollStatsWithCount(): void
    {
        $builder = new AggregationBuilder();
        $builder->collStats()
            ->showCount(true);

        $pipeline = $builder->getPipeline();
        $statsExpr = $pipeline[0]['$collStats'];
        $this->assertTrue($statsExpr['count']);
    }

    /**
     * Test collStats stage directly
     */
    public function testCollStatsStageDirect(): void
    {
        $builder = new AggregationBuilder();
        $stage = new CollStats($builder);
        $stage->latencyStats(true)
            ->storageStats(true)
            ->showCount(true);

        $expression = $stage->getExpression();
        $this->assertArrayHasKey('$collStats', $expression);
        $statsExpr = $expression['$collStats'];
        $this->assertTrue($statsExpr['latencyStats']);
        $this->assertTrue($statsExpr['storageStats']);
        $this->assertTrue($statsExpr['count']);
    }
}

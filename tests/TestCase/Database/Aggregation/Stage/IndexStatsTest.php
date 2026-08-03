<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Aggregation\Stage;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Aggregation\AggregationBuilder;
use Crustum\Mongo\Database\Aggregation\Stage\IndexStats;

/**
 * Test case for IndexStats aggregation stage
 */
class IndexStatsTest extends TestCase
{
    /**
     * Test basic indexStats stage
     */
    public function testBasicIndexStats(): void
    {
        $builder = new AggregationBuilder();
        $builder->indexStats();

        $pipeline = $builder->getPipeline();
        $this->assertCount(1, $pipeline);
        $this->assertArrayHasKey('$indexStats', $pipeline[0]);
    }

    /**
     * Test indexStats stage directly
     */
    public function testIndexStatsStageDirect(): void
    {
        $builder = new AggregationBuilder();
        $stage = new IndexStats($builder);

        $expression = $stage->getExpression();
        $this->assertArrayHasKey('$indexStats', $expression);
    }
}

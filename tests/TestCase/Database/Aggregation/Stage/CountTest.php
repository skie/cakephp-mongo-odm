<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Aggregation\Stage;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Aggregation\AggregationBuilder;
use Crustum\Mongo\Database\Aggregation\Stage\Count;

/**
 * Tests for Count stage
 */
class CountTest extends TestCase
{
    /**
     * Test basic count
     *
     * @return void
     */
    public function testBasicCount(): void
    {
        $builder = new AggregationBuilder();
        $builder->count('total');

        $pipeline = $builder->getPipeline();
        $this->assertCount(1, $pipeline);
        $this->assertArrayHasKey('$count', $pipeline[0]);
        $this->assertEquals('total', $pipeline[0]['$count']);
    }

    /**
     * Test count in pipeline
     *
     * @return void
     */
    public function testCountInPipeline(): void
    {
        $builder = new AggregationBuilder();
        $builder->match(['status' => 'active'])
            ->count('activeCount');

        $pipeline = $builder->getPipeline();
        $this->assertCount(2, $pipeline);
        $this->assertEquals('activeCount', $pipeline[1]['$count']);
    }

    /**
     * Test method chaining
     *
     * @return void
     */
    public function testMethodChaining(): void
    {
        $builder = new AggregationBuilder();
        $result = $builder->count('total');

        $this->assertInstanceOf(Count::class, $result);
    }
}

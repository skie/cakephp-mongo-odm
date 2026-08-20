<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Aggregation\Stage;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Aggregation\AggregationBuilder;
use Crustum\Mongo\Database\Aggregation\Stage\Limit;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for Limit stage
 *
 * @inspired-by \Doctrine\ODM\MongoDB\Tests\Aggregation\Stage\LimitTest
 */
#[CoversClass(Limit::class)]
class LimitTest extends TestCase
{
    /**
     * Test basic limit
     *
     * @return void
     */
    public function testBasicLimit(): void
    {
        $builder = new AggregationBuilder();
        $builder->limit(10);

        $pipeline = $builder->getPipeline();
        $this->assertCount(1, $pipeline);
        $this->assertArrayHasKey('$limit', $pipeline[0]);
        $this->assertEquals(10, $pipeline[0]['$limit']);
    }

    /**
     * Test limit in pipeline
     *
     * @return void
     */
    public function testLimitInPipeline(): void
    {
        $builder = new AggregationBuilder();
        $builder->match(['status' => 'active'])
            ->sort(['created' => -1])
            ->limit(5);

        $pipeline = $builder->getPipeline();
        $this->assertCount(3, $pipeline);
        $this->assertEquals(5, $pipeline[2]['$limit']);
    }

    /**
     * Test method chaining
     *
     * @return void
     */
    public function testMethodChaining(): void
    {
        $builder = new AggregationBuilder();
        $result = $builder->limit(10);

        $this->assertInstanceOf(Limit::class, $result);
    }
}

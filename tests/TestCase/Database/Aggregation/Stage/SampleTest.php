<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Aggregation\Stage;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Aggregation\AggregationBuilder;
use Crustum\Mongo\Database\Aggregation\Stage\Sample;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Test case for Sample aggregation stage
 *
 * @rewritten-from \Doctrine\ODM\MongoDB\Tests\Aggregation\Stage\SampleTest
 */
#[CoversClass(Sample::class)]
class SampleTest extends TestCase
{
    /**
     * Test basic sample stage
     */
    public function testBasicSample(): void
    {
        $builder = new AggregationBuilder();
        $builder->sample(10);

        $pipeline = $builder->getPipeline();
        $this->assertCount(1, $pipeline);
        $this->assertArrayHasKey('$sample', $pipeline[0]);

        $sampleExpr = $pipeline[0]['$sample'];
        $this->assertArrayHasKey('size', $sampleExpr);
        $this->assertEquals(10, $sampleExpr['size']);
    }

    /**
     * Test sample stage directly
     */
    public function testSampleStageDirect(): void
    {
        $builder = new AggregationBuilder();
        $stage = new Sample($builder, 5);

        $expression = $stage->getExpression();
        $this->assertArrayHasKey('$sample', $expression);
        $this->assertEquals(['size' => 5], $expression['$sample']);
    }

    /**
     * Test sample in pipeline chain
     */
    public function testSampleInPipeline(): void
    {
        $builder = new AggregationBuilder();
        $builder->match(['status' => 'active'])
            ->sample(20)
            ->limit(10);

        $pipeline = $builder->getPipeline();
        $this->assertCount(3, $pipeline);
        $this->assertArrayHasKey('$match', $pipeline[0]);
        $this->assertArrayHasKey('$sample', $pipeline[1]);
        $this->assertArrayHasKey('$limit', $pipeline[2]);
    }
}

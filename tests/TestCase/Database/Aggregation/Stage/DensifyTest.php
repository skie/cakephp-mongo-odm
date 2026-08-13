<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Aggregation\Stage;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Aggregation\AggregationBuilder;
use Crustum\Mongo\Database\Aggregation\Stage\Densify;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Test case for Densify aggregation stage
 */
#[CoversClass(Densify::class)]
class DensifyTest extends TestCase
{
    /**
     * Test basic densify stage
     */
    public function testBasicDensify(): void
    {
        $builder = new AggregationBuilder();
        $builder->densify('date', ['step' => 1, 'unit' => 'day']);

        $pipeline = $builder->getPipeline();
        $this->assertCount(1, $pipeline);
        $this->assertArrayHasKey('$densify', $pipeline[0]);

        $densifyExpr = $pipeline[0]['$densify'];
        $this->assertEquals('date', $densifyExpr['field']);
        $this->assertArrayHasKey('range', $densifyExpr);
        $this->assertEquals(['step' => 1, 'unit' => 'day'], $densifyExpr['range']);
    }

    /**
     * Test densify without range
     */
    public function testDensifyWithoutRange(): void
    {
        $builder = new AggregationBuilder();
        $builder->densify('date');

        $pipeline = $builder->getPipeline();
        $densifyExpr = $pipeline[0]['$densify'];
        $this->assertEquals('date', $densifyExpr['field']);
        $this->assertArrayNotHasKey('range', $densifyExpr);
    }

    /**
     * Test densify with partitionByFields
     */
    public function testDensifyWithPartitionByFields(): void
    {
        $builder = new AggregationBuilder();
        $builder->densify('date', ['step' => 1, 'unit' => 'day'])
            ->partitionByFields(['category', 'status']);

        $pipeline = $builder->getPipeline();
        $densifyExpr = $pipeline[0]['$densify'];
        $this->assertArrayHasKey('partitionByFields', $densifyExpr);
        $this->assertEquals(['category', 'status'], $densifyExpr['partitionByFields']);
    }

    /**
     * Test densify with range update
     */
    public function testDensifyWithRangeUpdate(): void
    {
        $builder = new AggregationBuilder();
        $builder->densify('date', ['step' => 1, 'unit' => 'day'])
            ->range(['step' => 2, 'unit' => 'day', 'bounds' => 'full']);

        $pipeline = $builder->getPipeline();
        $densifyExpr = $pipeline[0]['$densify'];
        $this->assertArrayHasKey('range', $densifyExpr);
        $this->assertEquals(['step' => 2, 'unit' => 'day', 'bounds' => 'full'], $densifyExpr['range']);
    }

    /**
     * Test densify stage directly
     */
    public function testDensifyStageDirect(): void
    {
        $builder = new AggregationBuilder();
        $stage = new Densify($builder, 'date', ['step' => 1, 'unit' => 'day']);
        $stage->partitionByFields(['category']);

        $expression = $stage->getExpression();
        $this->assertArrayHasKey('$densify', $expression);
        $densifyExpr = $expression['$densify'];
        $this->assertEquals('date', $densifyExpr['field']);
        $this->assertArrayHasKey('partitionByFields', $densifyExpr);
        $this->assertArrayHasKey('range', $densifyExpr);
    }
}

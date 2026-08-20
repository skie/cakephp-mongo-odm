<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Aggregation\Stage;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Aggregation\AggregationBuilder;
use Crustum\Mongo\Database\Aggregation\Stage\Fill;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Test case for Fill aggregation stage
 *
 * @rewritten-from \Doctrine\ODM\MongoDB\Tests\Aggregation\Stage\FillTest
 */
#[CoversClass(Fill::class)]
class FillTest extends TestCase
{
    /**
     * Test basic fill stage
     */
    public function testBasicFill(): void
    {
        $builder = new AggregationBuilder();
        $builder->fill();

        $pipeline = $builder->getPipeline();
        $this->assertCount(1, $pipeline);
        $this->assertArrayHasKey('$fill', $pipeline[0]);
    }

    /**
     * Test fill with output
     */
    public function testFillWithOutput(): void
    {
        $builder = new AggregationBuilder();
        $builder->fill()
            ->output('value', 'linear')
            ->output('count', 'locf');

        $pipeline = $builder->getPipeline();
        $fillExpr = $pipeline[0]['$fill'];
        $this->assertArrayHasKey('output', $fillExpr);
        $this->assertEquals('linear', $fillExpr['output']['value']);
        $this->assertEquals('locf', $fillExpr['output']['count']);
    }

    /**
     * Test fill with partitionBy
     */
    public function testFillWithPartitionBy(): void
    {
        $builder = new AggregationBuilder();
        $builder->fill()
            ->partitionBy(['category'])
            ->output('value', 'linear');

        $pipeline = $builder->getPipeline();
        $fillExpr = $pipeline[0]['$fill'];
        $this->assertArrayHasKey('partitionBy', $fillExpr);
        $this->assertEquals(['category'], $fillExpr['partitionBy']);
    }

    /**
     * Test fill with sortBy
     */
    public function testFillWithSortBy(): void
    {
        $builder = new AggregationBuilder();
        $builder->fill()
            ->sortBy(['date' => 1])
            ->output('value', 'linear');

        $pipeline = $builder->getPipeline();
        $fillExpr = $pipeline[0]['$fill'];
        $this->assertArrayHasKey('sortBy', $fillExpr);
        $this->assertEquals(['date' => 1], $fillExpr['sortBy']);
    }

    /**
     * Test fill stage directly
     */
    public function testFillStageDirect(): void
    {
        $builder = new AggregationBuilder();
        $stage = new Fill($builder);
        $stage->partitionBy(['category'])
            ->sortBy(['date' => 1])
            ->output('value', 'linear');

        $expression = $stage->getExpression();
        $this->assertArrayHasKey('$fill', $expression);
        $fillExpr = $expression['$fill'];
        $this->assertArrayHasKey('output', $fillExpr);
        $this->assertArrayHasKey('partitionBy', $fillExpr);
        $this->assertArrayHasKey('sortBy', $fillExpr);
    }
}

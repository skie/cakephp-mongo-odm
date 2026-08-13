<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Aggregation\Stage;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Aggregation\AggregationBuilder;
use Crustum\Mongo\Database\Aggregation\Stage\SetWindowFields;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Test case for SetWindowFields aggregation stage
 */
#[CoversClass(SetWindowFields::class)]
class SetWindowFieldsTest extends TestCase
{
    /**
     * Test basic setWindowFields stage
     */
    public function testBasicSetWindowFields(): void
    {
        $builder = new AggregationBuilder();
        $builder->setWindowFields();

        $pipeline = $builder->getPipeline();
        $this->assertCount(1, $pipeline);
        $this->assertArrayHasKey('$setWindowFields', $pipeline[0]);
    }

    /**
     * Test setWindowFields with output
     */
    public function testSetWindowFieldsWithOutput(): void
    {
        $builder = new AggregationBuilder();
        $builder->setWindowFields()
            ->output('cumulativeSum', ['$sum' => '$value', 'window' => ['documents' => ['unboundedPreceding', 'current']]]);

        $pipeline = $builder->getPipeline();
        $windowExpr = $pipeline[0]['$setWindowFields'];
        $this->assertArrayHasKey('output', $windowExpr);
        $this->assertArrayHasKey('cumulativeSum', $windowExpr['output']);
    }

    /**
     * Test setWindowFields with partitionBy
     */
    public function testSetWindowFieldsWithPartitionBy(): void
    {
        $builder = new AggregationBuilder();
        $builder->setWindowFields()
            ->partitionBy(['category'])
            ->output('cumulativeSum', ['$sum' => '$value']);

        $pipeline = $builder->getPipeline();
        $windowExpr = $pipeline[0]['$setWindowFields'];
        $this->assertArrayHasKey('partitionBy', $windowExpr);
        $this->assertEquals(['category'], $windowExpr['partitionBy']);
    }

    /**
     * Test setWindowFields with sortBy
     */
    public function testSetWindowFieldsWithSortBy(): void
    {
        $builder = new AggregationBuilder();
        $builder->setWindowFields()
            ->sortBy(['date' => 1])
            ->output('cumulativeSum', ['$sum' => '$value']);

        $pipeline = $builder->getPipeline();
        $windowExpr = $pipeline[0]['$setWindowFields'];
        $this->assertArrayHasKey('sortBy', $windowExpr);
        $this->assertEquals(['date' => 1], $windowExpr['sortBy']);
    }

    /**
     * Test setWindowFields stage directly
     */
    public function testSetWindowFieldsStageDirect(): void
    {
        $builder = new AggregationBuilder();
        $stage = new SetWindowFields($builder);
        $stage->partitionBy(['category'])
            ->sortBy(['date' => 1])
            ->output('cumulativeSum', ['$sum' => '$value']);

        $expression = $stage->getExpression();
        $this->assertArrayHasKey('$setWindowFields', $expression);
        $windowExpr = $expression['$setWindowFields'];
        $this->assertArrayHasKey('output', $windowExpr);
        $this->assertArrayHasKey('partitionBy', $windowExpr);
        $this->assertArrayHasKey('sortBy', $windowExpr);
    }
}

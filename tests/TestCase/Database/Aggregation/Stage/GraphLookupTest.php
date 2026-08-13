<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Aggregation\Stage;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Aggregation\AggregationBuilder;
use Crustum\Mongo\Database\Aggregation\Stage\GraphLookup;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Test case for GraphLookup aggregation stage
 */
#[CoversClass(GraphLookup::class)]
class GraphLookupTest extends TestCase
{
    /**
     * Test basic graphLookup stage
     */
    public function testBasicGraphLookup(): void
    {
        $builder = new AggregationBuilder();
        $builder->graphLookup('employees', '$reportsTo', 'reportsTo', '_id', 'reportingHierarchy');

        $pipeline = $builder->getPipeline();
        $this->assertCount(1, $pipeline);
        $this->assertArrayHasKey('$graphLookup', $pipeline[0]);

        $graphExpr = $pipeline[0]['$graphLookup'];
        $this->assertEquals('employees', $graphExpr['from']);
        $this->assertEquals('$reportsTo', $graphExpr['startWith']);
        $this->assertEquals('reportsTo', $graphExpr['connectFromField']);
        $this->assertEquals('_id', $graphExpr['connectToField']);
        $this->assertEquals('reportingHierarchy', $graphExpr['as']);
    }

    /**
     * Test graphLookup with maxDepth
     */
    public function testGraphLookupWithMaxDepth(): void
    {
        $builder = new AggregationBuilder();
        $builder->graphLookup('employees', '$reportsTo', 'reportsTo', '_id', 'reportingHierarchy')
            ->maxDepth(5);

        $pipeline = $builder->getPipeline();
        $graphExpr = $pipeline[0]['$graphLookup'];
        $this->assertEquals(5, $graphExpr['maxDepth']);
    }

    /**
     * Test graphLookup with depthField
     */
    public function testGraphLookupWithDepthField(): void
    {
        $builder = new AggregationBuilder();
        $builder->graphLookup('employees', '$reportsTo', 'reportsTo', '_id', 'reportingHierarchy')
            ->depthField('depth');

        $pipeline = $builder->getPipeline();
        $graphExpr = $pipeline[0]['$graphLookup'];
        $this->assertEquals('depth', $graphExpr['depthField']);
    }

    /**
     * Test graphLookup with restrictSearchWithMatch
     */
    public function testGraphLookupWithRestrictSearchWithMatch(): void
    {
        $builder = new AggregationBuilder();
        $builder->graphLookup('employees', '$reportsTo', 'reportsTo', '_id', 'reportingHierarchy')
            ->restrictSearchWithMatch(['status' => 'active']);

        $pipeline = $builder->getPipeline();
        $graphExpr = $pipeline[0]['$graphLookup'];
        $this->assertArrayHasKey('restrictSearchWithMatch', $graphExpr);
        $this->assertEquals(['status' => 'active'], $graphExpr['restrictSearchWithMatch']);
    }

    /**
     * Test graphLookup stage directly
     */
    public function testGraphLookupStageDirect(): void
    {
        $builder = new AggregationBuilder();
        $stage = new GraphLookup($builder, 'employees', '$reportsTo', 'reportsTo', '_id', 'reportingHierarchy');
        $stage->maxDepth(5)
            ->depthField('depth')
            ->restrictSearchWithMatch(['status' => 'active']);

        $expression = $stage->getExpression();
        $this->assertArrayHasKey('$graphLookup', $expression);
        $graphExpr = $expression['$graphLookup'];
        $this->assertEquals(5, $graphExpr['maxDepth']);
        $this->assertEquals('depth', $graphExpr['depthField']);
        $this->assertArrayHasKey('restrictSearchWithMatch', $graphExpr);
    }
}

<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Aggregation\Stage;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Aggregation\AggregationBuilder;
use Crustum\Mongo\Database\Aggregation\Stage\Facet;

/**
 * Test case for Facet aggregation stage
 */
class FacetTest extends TestCase
{
    /**
     * Test basic facet stage
     */
    public function testBasicFacet(): void
    {
        $builder = new AggregationBuilder();
        $facet = $builder->facet();
        $facet->facet('categorizedByPrice', [
            ['$match' => ['price' => ['$exists' => true]]],
            ['$bucket' => [
                'groupBy' => '$price',
                'boundaries' => [0, 100, 200, 300],
            ]],
        ]);

        $pipeline = $builder->getPipeline();
        $this->assertCount(1, $pipeline);
        $this->assertArrayHasKey('$facet', $pipeline[0]);

        $facetExpr = $pipeline[0]['$facet'];
        $this->assertArrayHasKey('categorizedByPrice', $facetExpr);
        $this->assertCount(2, $facetExpr['categorizedByPrice']);
    }

    /**
     * Test facet with multiple pipelines
     */
    public function testFacetMultiplePipelines(): void
    {
        $builder = new AggregationBuilder();
        $facet = $builder->facet();
        $facet->facet('price', [
            ['$match' => ['price' => ['$exists' => true]]],
        ])
        ->facet('category', [
            ['$group' => ['_id' => '$category', 'count' => ['$sum' => 1]]],
        ])
        ->facet('tags', [
            ['$unwind' => '$tags'],
            ['$group' => ['_id' => '$tags', 'count' => ['$sum' => 1]]],
        ]);

        $pipeline = $builder->getPipeline();
        $facetExpr = $pipeline[0]['$facet'];
        $this->assertArrayHasKey('price', $facetExpr);
        $this->assertArrayHasKey('category', $facetExpr);
        $this->assertArrayHasKey('tags', $facetExpr);
    }

    /**
     * Test facet stage directly
     */
    public function testFacetStageDirect(): void
    {
        $builder = new AggregationBuilder();
        $stage = new Facet($builder);
        $stage->facet('test', [
            ['$match' => ['status' => 'active']],
        ]);

        $expression = $stage->getExpression();
        $this->assertArrayHasKey('$facet', $expression);
        $facetExpr = $expression['$facet'];
        $this->assertArrayHasKey('test', $facetExpr);
        $this->assertCount(1, $facetExpr['test']);
    }
}

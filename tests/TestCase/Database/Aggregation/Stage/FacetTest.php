<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Aggregation\Stage;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Aggregation\AggregationBuilder;
use Crustum\Mongo\Database\Aggregation\Stage\Facet;
use Crustum\Mongo\Database\Aggregation\Stage\MatchStage;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Test case for Facet aggregation stage
 *
 * @inspired-by \Doctrine\ODM\MongoDB\Tests\Aggregation\Stage\FacetTest
 */
#[CoversClass(Facet::class)]
class FacetTest extends TestCase
{
    /**
     * Test basic facet stage
     */
    public function testBasicFacet(): void
    {
        $builder = new AggregationBuilder();
        $facet = $builder->facet();
        $facet->addFacet('categorizedByPrice', [
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
        $facet->addFacet('price', [
            ['$match' => ['price' => ['$exists' => true]]],
        ])
        ->addFacet('category', [
            ['$group' => ['_id' => '$category', 'count' => ['$sum' => 1]]],
        ])
        ->addFacet('tags', [
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
     * Test facet stage with a builder closure sub-pipeline.
     */
    public function testFacetWithPipelineClosure(): void
    {
        $builder = new AggregationBuilder();
        $facet = $builder->facet();
        $facet->addFacet('active', fn(AggregationBuilder $sub): MatchStage => $sub->match(['status' => 'active']));

        $facetExpr = $builder->getPipeline()[0]['$facet'];
        $this->assertSame([['$match' => ['status' => 'active']]], $facetExpr['active']);
    }

    /**
     * Test facet stage directly
     */
    public function testFacetStageDirect(): void
    {
        $builder = new AggregationBuilder();
        $stage = new Facet($builder);
        $stage->addFacet('test', [
            ['$match' => ['status' => 'active']],
        ]);

        $expression = $stage->getExpression();
        $this->assertArrayHasKey('$facet', $expression);
        $facetExpr = $expression['$facet'];
        $this->assertArrayHasKey('test', $facetExpr);
        $this->assertCount(1, $facetExpr['test']);
    }
}

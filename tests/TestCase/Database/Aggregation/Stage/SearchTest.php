<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Aggregation\Stage;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Aggregation\AggregationBuilder;
use Crustum\Mongo\Database\Aggregation\Stage\Search;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Test case for Search aggregation stage
 */
#[CoversClass(Search::class)]
class SearchTest extends TestCase
{
    /**
     * Test basic search stage
     */
    public function testBasicSearch(): void
    {
        $builder = new AggregationBuilder();
        $builder->search(['text' => ['query' => 'test', 'path' => 'title']]);

        $pipeline = $builder->getPipeline();
        $this->assertCount(1, $pipeline);
        $this->assertArrayHasKey('$search', $pipeline[0]);

        $searchExpr = $pipeline[0]['$search'];
        $this->assertSame(['query' => 'test', 'path' => 'title'], $searchExpr['text']);
        $this->assertArrayNotHasKey('search', $searchExpr);
    }

    /**
     * Test search with index
     */
    public function testSearchWithIndex(): void
    {
        $builder = new AggregationBuilder();
        $builder->search(['text' => ['query' => 'test', 'path' => 'title']])
            ->index('default');

        $pipeline = $builder->getPipeline();
        $searchExpr = $pipeline[0]['$search'];
        $this->assertEquals('default', $searchExpr['index']);
        $this->assertSame(['query' => 'test', 'path' => 'title'], $searchExpr['text']);
        $this->assertArrayNotHasKey('search', $searchExpr);
    }

    /**
     * Test search stage directly
     */
    public function testSearchStageDirect(): void
    {
        $builder = new AggregationBuilder();
        $stage = new Search($builder, ['text' => ['query' => 'test']]);
        $stage->index('default');

        $expression = $stage->getExpression();
        $this->assertArrayHasKey('$search', $expression);
        $searchExpr = $expression['$search'];
        $this->assertEquals('default', $searchExpr['index']);
        $this->assertSame(['query' => 'test'], $searchExpr['text']);
        $this->assertArrayNotHasKey('search', $searchExpr);
    }
}

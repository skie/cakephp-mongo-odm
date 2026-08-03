<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Aggregation\Stage;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Aggregation\AggregationBuilder;
use Crustum\Mongo\Database\Aggregation\Stage\SortByCount;

/**
 * Test case for SortByCount aggregation stage
 */
class SortByCountTest extends TestCase
{
    /**
     * Test basic sortByCount stage
     */
    public function testBasicSortByCount(): void
    {
        $builder = new AggregationBuilder();
        $builder->sortByCount('$category');

        $pipeline = $builder->getPipeline();
        $this->assertCount(1, $pipeline);
        $this->assertArrayHasKey('$sortByCount', $pipeline[0]);
        $this->assertEquals('$category', $pipeline[0]['$sortByCount']);
    }

    /**
     * Test sortByCount with expression
     */
    public function testSortByCountWithExpression(): void
    {
        $builder = new AggregationBuilder();
        $builder->sortByCount(['$toLower' => '$category']);

        $pipeline = $builder->getPipeline();
        $this->assertArrayHasKey('$toLower', $pipeline[0]['$sortByCount']);
    }

    /**
     * Test sortByCount stage directly
     */
    public function testSortByCountStageDirect(): void
    {
        $builder = new AggregationBuilder();
        $stage = new SortByCount($builder, '$category');

        $expression = $stage->getExpression();
        $this->assertArrayHasKey('$sortByCount', $expression);
        $this->assertEquals('$category', $expression['$sortByCount']);
    }

    /**
     * Test sortByCount in pipeline chain
     */
    public function testSortByCountInPipeline(): void
    {
        $builder = new AggregationBuilder();
        $builder->match(['status' => 'active'])
            ->sortByCount('$category')
            ->limit(10);

        $pipeline = $builder->getPipeline();
        $this->assertCount(3, $pipeline);
        $this->assertArrayHasKey('$match', $pipeline[0]);
        $this->assertArrayHasKey('$sortByCount', $pipeline[1]);
        $this->assertArrayHasKey('$limit', $pipeline[2]);
    }
}

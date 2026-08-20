<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Aggregation\Stage;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Aggregation\AggregationBuilder;
use Crustum\Mongo\Database\Aggregation\Stage\VectorSearch;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for VectorSearch stage
 *
 * @rewritten-from \Doctrine\ODM\MongoDB\Tests\Aggregation\Stage\VectorSearchTest
 */
#[CoversClass(VectorSearch::class)]
class VectorSearchTest extends TestCase
{
    /**
     * Test basic vector search stage
     *
     * @return void
     */
    public function testBasicVectorSearch(): void
    {
        $builder = new AggregationBuilder();
        $builder->vectorSearch([1.0, 2.0, 3.0], 'embedding', 10);

        $pipeline = $builder->getPipeline();
        $this->assertCount(1, $pipeline);
        $this->assertArrayHasKey('$vectorSearch', $pipeline[0]);

        $search = $pipeline[0]['$vectorSearch'];
        $this->assertEquals([1.0, 2.0, 3.0], $search['queryVector']);
        $this->assertEquals('embedding', $search['path']);
        $this->assertEquals(10, $search['numCandidates']);
    }

    /**
     * Test vector search via dedicated stage instance
     *
     * @return void
     */
    public function testVectorSearchStageInstance(): void
    {
        $builder = new AggregationBuilder();
        $stage = new VectorSearch($builder, [1.0, 2.0], 'embedding');

        $expression = $stage->getExpression();
        $this->assertArrayHasKey('$vectorSearch', $expression);
        $this->assertArrayNotHasKey('numCandidates', $expression['$vectorSearch']);
    }

    /**
     * Test index fluent
     *
     * @return void
     */
    public function testIndex(): void
    {
        $builder = new AggregationBuilder();
        $stage = new VectorSearch($builder, [1.0], 'embedding');
        $stage->index('vector_idx');

        $expression = $stage->getExpression();
        $this->assertEquals('vector_idx', $expression['$vectorSearch']['index']);
    }

    /**
     * Test numCandidates fluent
     *
     * @return void
     */
    public function testNumCandidates(): void
    {
        $builder = new AggregationBuilder();
        $stage = new VectorSearch($builder, [1.0], 'embedding');
        $stage->numCandidates(25);

        $expression = $stage->getExpression();
        $this->assertEquals(25, $expression['$vectorSearch']['numCandidates']);
    }

    /**
     * Test filter fluent
     *
     * @return void
     */
    public function testFilter(): void
    {
        $builder = new AggregationBuilder();
        $stage = new VectorSearch($builder, [1.0], 'embedding');
        $stage->filter(['status' => 'active']);

        $expression = $stage->getExpression();
        $this->assertEquals(['status' => 'active'], $expression['$vectorSearch']['filter']);
    }

    /**
     * Test object query vector is preserved
     *
     * @return void
     */
    public function testObjectQueryVector(): void
    {
        $builder = new AggregationBuilder();
        $vector = (object)['0' => 1.5, '1' => 2.5];
        $stage = new VectorSearch($builder, $vector, 'embedding');

        $expression = $stage->getExpression();
        $this->assertSame($vector, $expression['$vectorSearch']['queryVector']);
    }

    /**
     * Test fluent chaining returns same stage
     *
     * @return void
     */
    public function testFluentChaining(): void
    {
        $builder = new AggregationBuilder();
        $stage = new VectorSearch($builder, [1.0], 'embedding');
        $this->assertSame($stage, $stage->index('vector_idx'));
    }
}

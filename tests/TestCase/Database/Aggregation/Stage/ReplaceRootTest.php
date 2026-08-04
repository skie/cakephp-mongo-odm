<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Aggregation\Stage;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Aggregation\AggregationBuilder;
use Crustum\Mongo\Database\Aggregation\Stage\ReplaceRoot;

/**
 * Tests for ReplaceRoot stage
 */
class ReplaceRootTest extends TestCase
{
    /**
     * Test basic replaceRoot with document
     *
     * @return void
     */
    public function testBasicReplaceRootWithDocument(): void
    {
        $builder = new AggregationBuilder();
        $builder->replaceRoot(['name' => '$user.name', 'email' => '$user.email']);

        $pipeline = $builder->getPipeline();
        $this->assertCount(1, $pipeline);
        $this->assertArrayHasKey('$replaceRoot', $pipeline[0]);
        $this->assertArrayHasKey('newRoot', $pipeline[0]['$replaceRoot']);
        $this->assertEquals(['name' => '$user.name', 'email' => '$user.email'], $pipeline[0]['$replaceRoot']['newRoot']);
    }

    /**
     * Test replaceRoot with expression string
     *
     * @return void
     */
    public function testReplaceRootWithExpression(): void
    {
        $builder = new AggregationBuilder();
        $builder->replaceRoot('$user');

        $pipeline = $builder->getPipeline();
        $this->assertCount(1, $pipeline);
        $this->assertEquals('$user', $pipeline[0]['$replaceRoot']['newRoot']);
    }

    /**
     * Test replaceRoot in pipeline
     *
     * @return void
     */
    public function testReplaceRootInPipeline(): void
    {
        $builder = new AggregationBuilder();
        $builder->match(['status' => 'active'])
            ->replaceRoot('$details');

        $pipeline = $builder->getPipeline();
        $this->assertCount(2, $pipeline);
        $this->assertArrayHasKey('$replaceRoot', $pipeline[1]);
    }

    /**
     * Test method chaining
     *
     * @return void
     */
    public function testMethodChaining(): void
    {
        $builder = new AggregationBuilder();
        $result = $builder->replaceRoot('$user');

        $this->assertInstanceOf(ReplaceRoot::class, $result);
    }
}

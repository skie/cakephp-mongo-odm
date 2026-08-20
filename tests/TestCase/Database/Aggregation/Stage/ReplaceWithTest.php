<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Aggregation\Stage;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Aggregation\AggregationBuilder;
use Crustum\Mongo\Database\Aggregation\Stage\ReplaceWith;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for ReplaceWith stage
 *
 * @inspired-by \Doctrine\ODM\MongoDB\Tests\Aggregation\Stage\ReplaceWithTest
 */
#[CoversClass(ReplaceWith::class)]
class ReplaceWithTest extends TestCase
{
    /**
     * Test basic replaceWith with document
     *
     * @return void
     */
    public function testBasicReplaceWithWithDocument(): void
    {
        $builder = new AggregationBuilder();
        $builder->replaceWith(['name' => '$user.name', 'email' => '$user.email']);

        $pipeline = $builder->getPipeline();
        $this->assertCount(1, $pipeline);
        $this->assertArrayHasKey('$replaceWith', $pipeline[0]);
        $this->assertEquals(['name' => '$user.name', 'email' => '$user.email'], $pipeline[0]['$replaceWith']);
    }

    /**
     * Test replaceWith with expression string
     *
     * @return void
     */
    public function testReplaceWithWithExpression(): void
    {
        $builder = new AggregationBuilder();
        $builder->replaceWith('$user');

        $pipeline = $builder->getPipeline();
        $this->assertCount(1, $pipeline);
        $this->assertEquals('$user', $pipeline[0]['$replaceWith']);
    }

    /**
     * Test replaceWith in pipeline
     *
     * @return void
     */
    public function testReplaceWithInPipeline(): void
    {
        $builder = new AggregationBuilder();
        $builder->match(['status' => 'active'])
            ->replaceWith('$details');

        $pipeline = $builder->getPipeline();
        $this->assertCount(2, $pipeline);
        $this->assertArrayHasKey('$replaceWith', $pipeline[1]);
    }

    /**
     * Test method chaining
     *
     * @return void
     */
    public function testMethodChaining(): void
    {
        $builder = new AggregationBuilder();
        $result = $builder->replaceWith('$user');

        $this->assertInstanceOf(ReplaceWith::class, $result);
    }
}

<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Aggregation\Stage;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Aggregation\AggregationBuilder;
use Crustum\Mongo\Database\Aggregation\Stage\MatchStage;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for MatchStage
 *
 * @inspired-by \Doctrine\ODM\MongoDB\Tests\Aggregation\Stage\MatchStageTest
 */
#[CoversClass(MatchStage::class)]
class MatchStageTest extends TestCase
{
    /**
     * Test basic match stage
     *
     * @return void
     */
    public function testBasicMatch(): void
    {
        $builder = new AggregationBuilder();
        $builder->match(['status' => 'active']);

        $pipeline = $builder->getPipeline();
        $this->assertCount(1, $pipeline);
        $this->assertEquals(['$match' => ['status' => 'active']], $pipeline[0]);
    }

    /**
     * Test match via dedicated stage instance
     *
     * @return void
     */
    public function testMatchStageInstance(): void
    {
        $builder = new AggregationBuilder();
        $stage = new MatchStage($builder, ['status' => 'active']);

        $this->assertEquals(['$match' => ['status' => 'active']], $stage->getExpression());
    }

    /**
     * Test add merges conditions with $and
     *
     * @return void
     */
    public function testAdd(): void
    {
        $builder = new AggregationBuilder();
        $stage = new MatchStage($builder, ['status' => 'active']);
        $stage->add(['age' => ['$gt' => 18]]);

        $this->assertEquals(
            ['$match' => ['$and' => [['status' => 'active'], ['age' => ['$gt' => 18]]]]],
            $stage->getExpression(),
        );
    }

    /**
     * Test add on empty criteria sets directly
     *
     * @return void
     */
    public function testAddToEmpty(): void
    {
        $builder = new AggregationBuilder();
        $stage = new MatchStage($builder);
        $stage->add(['status' => 'active']);

        $this->assertEquals(['$match' => ['status' => 'active']], $stage->getExpression());
    }

    /**
     * Test add with empty conditions is a no-op
     *
     * @return void
     */
    public function testAddEmptyIsNoOp(): void
    {
        $builder = new AggregationBuilder();
        $stage = new MatchStage($builder, ['status' => 'active']);
        $stage->add([]);

        $this->assertEquals(['$match' => ['status' => 'active']], $stage->getExpression());
    }

    /**
     * Test fluent chaining returns same stage
     *
     * @return void
     */
    public function testFluentChaining(): void
    {
        $builder = new AggregationBuilder();
        $stage = new MatchStage($builder);
        $this->assertSame($stage, $stage->add(['status' => 'active']));
    }
}

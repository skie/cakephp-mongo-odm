<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Aggregation\Stage;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Aggregation\AggregationBuilder;
use Crustum\Mongo\Database\Aggregation\Stage\Group;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for Group stage
 *
 * @inspired-by \Doctrine\ODM\MongoDB\Tests\Aggregation\Stage\GroupTest
 */
#[CoversClass(Group::class)]
class GroupTest extends TestCase
{
    /**
     * Test basic group stage
     *
     * @return void
     */
    public function testBasicGroup(): void
    {
        $builder = new AggregationBuilder();
        $builder->group(['_id' => '$category', 'total' => ['$sum' => '$amount']]);

        $pipeline = $builder->getPipeline();
        $this->assertCount(1, $pipeline);
        $this->assertEquals(
            ['$group' => ['_id' => '$category', 'total' => ['$sum' => '$amount']]],
            $pipeline[0],
        );
    }

    /**
     * Test group via dedicated stage instance
     *
     * @return void
     */
    public function testGroupStageInstance(): void
    {
        $builder = new AggregationBuilder();
        $stage = new Group($builder, ['_id' => '$category']);

        $this->assertEquals(['$group' => ['_id' => '$category']], $stage->getExpression());
    }

    /**
     * Test add merges groups
     *
     * @return void
     */
    public function testAdd(): void
    {
        $builder = new AggregationBuilder();
        $stage = new Group($builder, ['_id' => '$category']);
        $stage->add(['total' => ['$sum' => '$amount']]);

        $this->assertEquals(
            ['$group' => ['_id' => '$category', 'total' => ['$sum' => '$amount']]],
            $stage->getExpression(),
        );
    }

    /**
     * Test fluent chaining returns same stage
     *
     * @return void
     */
    public function testFluentChaining(): void
    {
        $builder = new AggregationBuilder();
        $stage = new Group($builder);
        $this->assertSame($stage, $stage->add(['_id' => '$category']));
    }
}

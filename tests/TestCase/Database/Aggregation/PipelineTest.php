<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Aggregation;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Aggregation\AggregationBuilder;
use Crustum\Mongo\Database\Aggregation\Pipeline;
use Crustum\Mongo\Database\Aggregation\Stage\Group;
use Crustum\Mongo\Database\Aggregation\Stage\MatchStage;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for Pipeline
 */
#[CoversClass(Pipeline::class)]
class PipelineTest extends TestCase
{
    /**
     * Test addStage appends and returns the stage.
     *
     * @return void
     */
    public function testAddStageReturnsStage(): void
    {
        $pipeline = new Pipeline();
        $builder = new AggregationBuilder();
        $stage = $pipeline->addStage(new MatchStage($builder, ['status' => 'active']));

        $this->assertInstanceOf(MatchStage::class, $stage);
        $this->assertSame($stage, $pipeline->getStages()[0]);
    }

    /**
     * Test getStages returns the ordered stage list.
     *
     * @return void
     */
    public function testGetStagesPreservesOrder(): void
    {
        $pipeline = new Pipeline();
        $builder = new AggregationBuilder();

        $first = $pipeline->addStage(new MatchStage($builder, ['status' => 'active']));
        $second = $pipeline->addStage(new Group($builder, ['_id' => '$category']));

        $this->assertSame([$first, $second], $pipeline->getStages());
    }

    /**
     * Test compile is the single canonical compile loop.
     *
     * @return void
     */
    public function testCompile(): void
    {
        $pipeline = new Pipeline();
        $builder = new AggregationBuilder();
        $pipeline->addStage(new MatchStage($builder, ['status' => 'active']));
        $pipeline->addStage(new Group($builder, ['_id' => '$category']));

        $this->assertSame(
            [
                ['$match' => ['status' => 'active']],
                ['$group' => ['_id' => '$category']],
            ],
            $pipeline->compile(),
        );
    }
}

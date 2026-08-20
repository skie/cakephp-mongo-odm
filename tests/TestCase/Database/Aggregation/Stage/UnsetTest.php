<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Aggregation\Stage;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Aggregation\AggregationBuilder;
use Crustum\Mongo\Database\Aggregation\Stage\UnsetStage;

/**
 * Test case for Unset aggregation stage
 *
 * @rewritten-from \Doctrine\ODM\MongoDB\Tests\Aggregation\Stage\UnsetTest
 */
class UnsetTest extends TestCase
{
    /**
     * Test basic unset with single field
     */
    public function testUnsetSingleField(): void
    {
        $builder = new AggregationBuilder();
        $builder->unsetFields('field1');

        $pipeline = $builder->getPipeline();
        $this->assertCount(1, $pipeline);
        $this->assertArrayHasKey('$unset', $pipeline[0]);
        $this->assertEquals('field1', $pipeline[0]['$unset']);
    }

    /**
     * Test unset with multiple fields
     */
    public function testUnsetMultipleFields(): void
    {
        $builder = new AggregationBuilder();
        $builder->unsetFields(['field1', 'field2', 'field3']);

        $pipeline = $builder->getPipeline();
        $this->assertCount(1, $pipeline);
        $this->assertArrayHasKey('$unset', $pipeline[0]);
        $this->assertEquals(['field1', 'field2', 'field3'], $pipeline[0]['$unset']);
    }

    /**
     * Test unset stage directly
     */
    public function testUnsetStageDirect(): void
    {
        $builder = new AggregationBuilder();
        $stage = new UnsetStage($builder, 'field1');

        $expression = $stage->getExpression();
        $this->assertArrayHasKey('$unset', $expression);
        $this->assertEquals('field1', $expression['$unset']);
    }

    /**
     * Test unset in pipeline chain
     */
    public function testUnsetInPipeline(): void
    {
        $builder = new AggregationBuilder();
        $builder->match(['status' => 'active'])
            ->unsetFields(['temp', 'debug'])
            ->limit(10);

        $pipeline = $builder->getPipeline();
        $this->assertCount(3, $pipeline);
        $this->assertArrayHasKey('$match', $pipeline[0]);
        $this->assertArrayHasKey('$unset', $pipeline[1]);
        $this->assertArrayHasKey('$limit', $pipeline[2]);
    }
}

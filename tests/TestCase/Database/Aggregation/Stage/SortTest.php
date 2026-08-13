<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Aggregation\Stage;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Aggregation\AggregationBuilder;
use Crustum\Mongo\Database\Aggregation\Stage\Sort;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for Sort stage
 */
#[CoversClass(Sort::class)]
class SortTest extends TestCase
{
    /**
     * Test basic sort stage
     *
     * @return void
     */
    public function testBasicSort(): void
    {
        $builder = new AggregationBuilder();
        $builder->sort(['name' => 1, 'age' => -1]);

        $pipeline = $builder->getPipeline();
        $this->assertCount(1, $pipeline);
        $this->assertEquals(['$sort' => ['name' => 1, 'age' => -1]], $pipeline[0]);
    }

    /**
     * Test sort via dedicated stage instance
     *
     * @return void
     */
    public function testSortStageInstance(): void
    {
        $builder = new AggregationBuilder();
        $stage = new Sort($builder, ['name' => 1]);

        $this->assertEquals(['$sort' => ['name' => 1]], $stage->getExpression());
    }

    /**
     * Test add merges sort keys
     *
     * @return void
     */
    public function testAdd(): void
    {
        $builder = new AggregationBuilder();
        $stage = new Sort($builder, ['name' => 1]);
        $stage->add(['age' => -1]);

        $this->assertEquals(
            ['$sort' => ['name' => 1, 'age' => -1]],
            $stage->getExpression(),
        );
    }

    /**
     * Test string sort values are preserved
     *
     * @return void
     */
    public function testMetaSortValue(): void
    {
        $builder = new AggregationBuilder();
        $stage = new Sort($builder, ['score' => ['$meta' => 'textScore']]);

        $this->assertEquals(
            ['$sort' => ['score' => ['$meta' => 'textScore']]],
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
        $stage = new Sort($builder);
        $this->assertSame($stage, $stage->add(['name' => 1]));
    }
}

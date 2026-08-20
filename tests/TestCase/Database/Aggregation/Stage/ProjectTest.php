<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Aggregation\Stage;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Aggregation\AggregationBuilder;
use Crustum\Mongo\Database\Aggregation\Stage\Project;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for Project stage
 *
 * @inspired-by \Doctrine\ODM\MongoDB\Tests\Aggregation\Stage\ProjectTest
 */
#[CoversClass(Project::class)]
class ProjectTest extends TestCase
{
    /**
     * Test basic project stage
     *
     * @return void
     */
    public function testBasicProject(): void
    {
        $builder = new AggregationBuilder();
        $builder->project(['name' => 1, 'email' => 1]);

        $pipeline = $builder->getPipeline();
        $this->assertCount(1, $pipeline);
        $this->assertEquals(['$project' => ['name' => 1, 'email' => 1]], $pipeline[0]);
    }

    /**
     * Test project via dedicated stage instance
     *
     * @return void
     */
    public function testProjectStageInstance(): void
    {
        $builder = new AggregationBuilder();
        $stage = new Project($builder, ['name' => 1]);

        $this->assertEquals(['$project' => ['name' => 1]], $stage->getExpression());
    }

    /**
     * Test add merges projection fields
     *
     * @return void
     */
    public function testAdd(): void
    {
        $builder = new AggregationBuilder();
        $stage = new Project($builder, ['name' => 1]);
        $stage->add(['email' => 1]);

        $this->assertEquals(
            ['$project' => ['name' => 1, 'email' => 1]],
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
        $stage = new Project($builder);
        $this->assertSame($stage, $stage->add(['name' => 1]));
    }
}

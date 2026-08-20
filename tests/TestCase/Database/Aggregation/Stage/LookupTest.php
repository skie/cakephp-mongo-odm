<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Aggregation\Stage;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Aggregation\AggregationBuilder;
use Crustum\Mongo\Database\Aggregation\Pipeline;
use Crustum\Mongo\Database\Aggregation\Stage\Limit;
use Crustum\Mongo\Database\Aggregation\Stage\Lookup;
use Crustum\Mongo\Database\Aggregation\Stage\RawStage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for Lookup stage
 *
 * @rewritten-from \Doctrine\ODM\MongoDB\Tests\Aggregation\Stage\LookupTest
 */
#[CoversClass(Lookup::class)]
class LookupTest extends TestCase
{
    /**
     * Test basic lookup
     *
     * @return void
     */
    public function testBasicLookup(): void
    {
        $builder = new AggregationBuilder();
        $stage = $builder->lookup('orders');
        $result = $stage->localField('_id')
            ->foreignField('user_id')
            ->alias('user_orders');

        $this->assertSame($stage, $result);
        $expression = $stage->getExpression();
        $this->assertArrayHasKey('$lookup', $expression);
        $this->assertEquals('orders', $expression['$lookup']['from']);
        $this->assertEquals('_id', $expression['$lookup']['localField']);
        $this->assertEquals('user_id', $expression['$lookup']['foreignField']);
        $this->assertEquals('user_orders', $expression['$lookup']['as']);
    }

    /**
     * Test lookup with pipeline
     *
     * @return void
     */
    public function testLookupWithPipeline(): void
    {
        $builder = new AggregationBuilder();
        $stage = $builder->lookup('orders')
            ->pipeline([
                ['$match' => ['status' => 'active']],
                ['$limit' => 10],
            ])
            ->alias('active_orders');

        $expression = $stage->getExpression();
        $this->assertArrayHasKey('pipeline', $expression['$lookup']);
        $this->assertCount(2, $expression['$lookup']['pipeline']);
    }

    /**
     * Test lookup with let variables
     *
     * @return void
     */
    public function testLookupWithLet(): void
    {
        $builder = new AggregationBuilder();
        $stage = $builder->lookup('orders')
            ->let(['user_id' => '$_id'])
            ->pipeline([
                ['$match' => ['$expr' => ['$eq' => ['$user_id', '$$user_id']]]],
            ])
            ->alias('user_orders');

        $expression = $stage->getExpression();
        $this->assertArrayHasKey('let', $expression['$lookup']);
        $this->assertArrayHasKey('pipeline', $expression['$lookup']);
    }

    /**
     * Test lookup with a builder closure sub-pipeline.
     *
     * @return void
     */
    public function testLookupWithPipelineClosure(): void
    {
        $builder = new AggregationBuilder();
        $stage = $builder->lookup('orders')
            ->pipeline(fn(AggregationBuilder $sub): Limit => $sub->match(['status' => 'active'])->limit(10))
            ->alias('active_orders');

        $expression = $stage->getExpression();
        $this->assertSame(
            [
                ['$match' => ['status' => 'active']],
                ['$limit' => 10],
            ],
            $expression['$lookup']['pipeline'],
        );
    }

    /**
     * Test lookup with a Pipeline instance sub-pipeline.
     *
     * @return void
     */
    public function testLookupWithPipelineObject(): void
    {
        $builder = new AggregationBuilder();
        $sub = new Pipeline();
        $sub->addStage(new RawStage($builder, '$match', ['status' => 'active']));

        $stage = $builder->lookup('orders')->pipeline($sub);

        $this->assertSame(
            [['$match' => ['status' => 'active']]],
            $stage->getExpression()['$lookup']['pipeline'],
        );
    }

    /**
     * Test the pipeline cannot reference the top-level pipeline itself.
     *
     * @return void
     */
    public function testLookupPipelineSelfReferenceThrows(): void
    {
        $builder = new AggregationBuilder();
        $stage = $builder->lookup('orders');

        $this->expectException(InvalidArgumentException::class);
        $stage->pipeline($builder->getPipelineInstance());
    }

    /**
     * Test method chaining
     *
     * @return void
     */
    public function testMethodChaining(): void
    {
        $builder = new AggregationBuilder();
        $stage = $builder->lookup('orders');
        $result = $stage->localField('_id')
            ->foreignField('user_id')
            ->alias('orders');

        $this->assertSame($stage, $result);
    }

    /**
     * Test lookup without optional fields
     *
     * @return void
     */
    public function testLookupMinimal(): void
    {
        $builder = new AggregationBuilder();
        $stage = $builder->lookup('orders');

        $expression = $stage->getExpression();
        $this->assertEquals('orders', $expression['$lookup']['from']);
        $this->assertArrayNotHasKey('localField', $expression['$lookup']);
        $this->assertArrayNotHasKey('foreignField', $expression['$lookup']);
    }
}

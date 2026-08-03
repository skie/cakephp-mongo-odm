<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Aggregation;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Aggregation\AggregationBuilder;
use Crustum\Mongo\Database\Aggregation\Stage\AddFields;
use Crustum\Mongo\Database\Aggregation\Stage\Lookup;
use Crustum\Mongo\Database\Aggregation\Stage\Set;

/**
 * Tests for AggregationBuilder
 */
class AggregationBuilderTest extends TestCase
{
    /**
     * Test basic match stage
     *
     * @return void
     */
    public function testMatch(): void
    {
        $builder = new AggregationBuilder();
        $result = $builder->match(['status' => 'active']);

        $this->assertSame($builder, $result);
        $pipeline = $builder->getPipeline();
        $this->assertEquals([['$match' => ['status' => 'active']]], $pipeline);
    }

    /**
     * Test basic group stage
     *
     * @return void
     */
    public function testGroup(): void
    {
        $builder = new AggregationBuilder();
        $result = $builder->group(['_id' => '$category', 'total' => ['$sum' => '$amount']]);

        $this->assertSame($builder, $result);
        $pipeline = $builder->getPipeline();
        $this->assertEquals([['$group' => ['_id' => '$category', 'total' => ['$sum' => '$amount']]]], $pipeline);
    }

    /**
     * Test basic sort stage
     *
     * @return void
     */
    public function testSort(): void
    {
        $builder = new AggregationBuilder();
        $result = $builder->sort(['name' => 1, 'age' => -1]);

        $this->assertSame($builder, $result);
        $pipeline = $builder->getPipeline();
        $this->assertEquals([['$sort' => ['name' => 1, 'age' => -1]]], $pipeline);
    }

    /**
     * Test basic project stage
     *
     * @return void
     */
    public function testProject(): void
    {
        $builder = new AggregationBuilder();
        $result = $builder->project(['name' => 1, 'email' => 1]);

        $this->assertSame($builder, $result);
        $pipeline = $builder->getPipeline();
        $this->assertEquals([['$project' => ['name' => 1, 'email' => 1]]], $pipeline);
    }

    /**
     * Test lookup stage with fluent builder
     *
     * @return void
     */
    public function testLookup(): void
    {
        $builder = new AggregationBuilder();
        $lookup = $builder->lookup('orders')
            ->localField('_id')
            ->foreignField('user_id')
            ->alias('user_orders');

        $this->assertInstanceOf(Lookup::class, $lookup);
        $pipeline = $builder->getPipeline();
        $this->assertCount(1, $pipeline);
        $this->assertArrayHasKey('$lookup', $pipeline[0]);
        $this->assertEquals('orders', $pipeline[0]['$lookup']['from']);
        $this->assertEquals('_id', $pipeline[0]['$lookup']['localField']);
        $this->assertEquals('user_id', $pipeline[0]['$lookup']['foreignField']);
        $this->assertEquals('user_orders', $pipeline[0]['$lookup']['as']);
    }

    /**
     * Test lookup stage with array (legacy)
     *
     * @return void
     */
    public function testLookupArray(): void
    {
        $builder = new AggregationBuilder();
        $result = $builder->lookupArray(
            [
            'from' => 'orders',
            'localField' => '_id',
            'foreignField' => 'user_id',
            'as' => 'user_orders',
            ],
        );

        $this->assertSame($builder, $result);
        $pipeline = $builder->getPipeline();
        $this->assertEquals(
            [['$lookup' => [
            'from' => 'orders',
            'localField' => '_id',
            'foreignField' => 'user_id',
            'as' => 'user_orders',
            ]]],
            $pipeline,
        );
    }

    /**
     * Test lookup with pipeline
     *
     * @return void
     */
    public function testLookupWithPipeline(): void
    {
        $builder = new AggregationBuilder();
        $builder->lookup('orders')
            ->pipeline(
                [
                ['$match' => ['status' => 'active']],
                ['$limit' => 10],
                ],
            )
            ->alias('active_orders');

        $pipeline = $builder->getPipeline();
        $this->assertArrayHasKey('pipeline', $pipeline[0]['$lookup']);
        $this->assertCount(2, $pipeline[0]['$lookup']['pipeline']);
    }

    /**
     * Test unwind stage
     *
     * @return void
     */
    public function testUnwind(): void
    {
        $builder = new AggregationBuilder();
        $result = $builder->unwind('$tags');

        $this->assertSame($builder, $result);
        $pipeline = $builder->getPipeline();
        $this->assertEquals([['$unwind' => ['path' => '$tags']]], $pipeline);
    }

    /**
     * Test unwind with options
     *
     * @return void
     */
    public function testUnwindWithOptions(): void
    {
        $builder = new AggregationBuilder();
        $builder->unwind('$tags', ['preserveNullAndEmptyArrays' => true]);

        $pipeline = $builder->getPipeline();
        $this->assertTrue($pipeline[0]['$unwind']['preserveNullAndEmptyArrays']);
    }

    /**
     * Test addFields stage
     *
     * @return void
     */
    public function testAddFields(): void
    {
        $builder = new AggregationBuilder();
        $addFields = $builder->addFields()
            ->field('total', ['$add' => ['$price', '$tax']])
            ->field('discounted', ['$multiply' => ['$total', 0.9]]);

        $this->assertInstanceOf(AddFields::class, $addFields);
        $pipeline = $builder->getPipeline();
        $this->assertCount(1, $pipeline);
        $this->assertArrayHasKey('$addFields', $pipeline[0]);
        $this->assertArrayHasKey('total', $pipeline[0]['$addFields']);
        $this->assertArrayHasKey('discounted', $pipeline[0]['$addFields']);
        $this->assertEquals(['$add' => ['$price', '$tax']], $pipeline[0]['$addFields']['total']);
    }

    /**
     * Test addFields with $filter (used by HasMany)
     *
     * @return void
     */
    public function testAddFieldsWithFilter(): void
    {
        $builder = new AggregationBuilder();
        $builder->addFields()
            ->field(
                'comments',
                [
                '$filter' => [
                    'input' => '$comments',
                    'as' => 'comment',
                    'cond' => ['$eq' => ['$$comment.status', 'active']],
                ],
                ],
            );

        $pipeline = $builder->getPipeline();
        $this->assertArrayHasKey('$filter', $pipeline[0]['$addFields']['comments']);
        $this->assertEquals('$comments', $pipeline[0]['$addFields']['comments']['$filter']['input']);
    }

    /**
     * Test set stage (alias for addFields)
     *
     * @return void
     */
    public function testSet(): void
    {
        $builder = new AggregationBuilder();
        $set = $builder->set()
            ->field('total', ['$add' => ['$price', '$tax']]);

        $this->assertInstanceOf(Set::class, $set);
        $pipeline = $builder->getPipeline();
        $this->assertArrayHasKey('$set', $pipeline[0]);
        $this->assertArrayHasKey('total', $pipeline[0]['$set']);
    }

    /**
     * Test method chaining
     *
     * @return void
     */
    public function testMethodChaining(): void
    {
        $builder = new AggregationBuilder();
        $result = $builder->match(['status' => 'active'])
            ->group(['_id' => '$category'])
            ->sort(['_id' => 1])
            ->addStage('$limit', ['limit' => 10]);

        $this->assertSame($builder, $result);
        $pipeline = $builder->getPipeline();
        $this->assertCount(4, $pipeline);
    }

    /**
     * Test addStage for custom stages
     *
     * @return void
     */
    public function testAddStage(): void
    {
        $builder = new AggregationBuilder();
        $result = $builder->addStage('$limit', ['limit' => 10]);

        $this->assertSame($builder, $result);
        $pipeline = $builder->getPipeline();
        $this->assertEquals([['$limit' => ['limit' => 10]]], $pipeline);
    }

    /**
     * Test complex pipeline
     *
     * @return void
     */
    public function testComplexPipeline(): void
    {
        $builder = new AggregationBuilder();
        $builder->match(['status' => 'active'])
            ->lookup('categories')
                ->localField('category_id')
                ->foreignField('_id')
                ->alias('category');
        $builder->unwind('$category');
        $builder->addFields()
            ->field('total', ['$add' => ['$price', '$tax']]);
        $builder->sort(['total' => -1])
            ->addStage('$limit', ['limit' => 10]);

        $pipeline = $builder->getPipeline();
        $this->assertCount(6, $pipeline);
        $this->assertEquals('$match', array_key_first($pipeline[0]));
        $this->assertArrayHasKey('$lookup', $pipeline[1]);
        $this->assertArrayHasKey('$unwind', $pipeline[2]);
        $this->assertArrayHasKey('$addFields', $pipeline[3]);
        $this->assertArrayHasKey('$sort', $pipeline[4]);
        $this->assertArrayHasKey('$limit', $pipeline[5]);
    }
}

<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Aggregation\Stage;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Aggregation\AggregationBuilder;
use Crustum\Mongo\Database\Aggregation\Stage\AddFields;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for AddFields stage
 */
#[CoversClass(AddFields::class)]
class AddFieldsTest extends TestCase
{
    /**
     * Test basic field addition
     *
     * @return void
     */
    public function testBasicField(): void
    {
        $builder = new AggregationBuilder();
        $stage = $builder->addFields();
        $result = $stage->field('total', 100);

        $this->assertSame($stage, $result);
        $expression = $stage->getExpression();
        $this->assertArrayHasKey('$addFields', $expression);
        $this->assertEquals(['total' => 100], $expression['$addFields']);
    }

    /**
     * Test multiple fields
     *
     * @return void
     */
    public function testMultipleFields(): void
    {
        $builder = new AggregationBuilder();
        $stage = $builder->addFields();
        $stage->field('total', ['$add' => ['$price', '$tax']])
            ->field('discounted', ['$multiply' => ['$total', 0.9]]);

        $expression = $stage->getExpression();
        $this->assertCount(2, $expression['$addFields']);
        $this->assertArrayHasKey('total', $expression['$addFields']);
        $this->assertArrayHasKey('discounted', $expression['$addFields']);
    }

    /**
     * Test field with $filter expression (used by HasMany)
     *
     * @return void
     */
    public function testFieldWithFilter(): void
    {
        $builder = new AggregationBuilder();
        $stage = $builder->addFields();
        $stage->field(
            'filtered_comments',
            [
            '$filter' => [
                'input' => '$comments',
                'as' => 'comment',
                'cond' => ['$eq' => ['$$comment.status', 'active']],
            ],
            ],
        );

        $expression = $stage->getExpression();
        $filter = $expression['$addFields']['filtered_comments'];
        $this->assertArrayHasKey('$filter', $filter);
        $this->assertEquals('$comments', $filter['$filter']['input']);
        $this->assertEquals('comment', $filter['$filter']['as']);
    }

    /**
     * Test method chaining
     *
     * @return void
     */
    public function testMethodChaining(): void
    {
        $builder = new AggregationBuilder();
        $stage = $builder->addFields();
        $result = $stage->field('field1', 'value1')
            ->field('field2', 'value2');

        $this->assertSame($stage, $result);
        $expression = $stage->getExpression();
        $this->assertCount(2, $expression['$addFields']);
    }

    /**
     * Test integration with pipeline
     *
     * @return void
     */
    public function testPipelineIntegration(): void
    {
        $builder = new AggregationBuilder();
        $builder->match(['status' => 'active'])
            ->addFields()
            ->field('total', ['$add' => ['$price', '$tax']]);

        $pipeline = $builder->getPipeline();
        $this->assertCount(2, $pipeline);
        $this->assertArrayHasKey('$addFields', $pipeline[1]);
    }
}

<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Aggregation;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Aggregation\Expr;

/**
 * Tests for Aggregation Expr
 */
class ExprTest extends TestCase
{
    /**
     * Test basic field addition
     *
     * @return void
     */
    public function testAddField(): void
    {
        $expr = new Expr();
        $result = $expr->addField('total', 100);

        $this->assertSame($expr, $result);
        $expression = $expr->getExpression();
        $this->assertEquals(['total' => 100], $expression);
    }

    /**
     * Test multiple fields
     *
     * @return void
     */
    public function testMultipleFields(): void
    {
        $expr = new Expr();
        $expr->addField('field1', 'value1')
            ->addField('field2', 'value2');

        $expression = $expr->getExpression();
        $this->assertCount(2, $expression);
        $this->assertEquals('value1', $expression['field1']);
        $this->assertEquals('value2', $expression['field2']);
    }

    /**
     * Test field with expression
     *
     * @return void
     */
    public function testFieldWithExpression(): void
    {
        $expr = new Expr();
        $expr->addField('total', ['$add' => ['$price', '$tax']]);

        $expression = $expr->getExpression();
        $this->assertArrayHasKey('$add', $expression['total']);
    }

    /**
     * Test reset
     *
     * @return void
     */
    public function testReset(): void
    {
        $expr = new Expr();
        $expr->addField('field1', 'value1')
            ->reset();

        $expression = $expr->getExpression();
        $this->assertEmpty($expression);
    }

    /**
     * Test field method
     *
     * @return void
     */
    public function testField(): void
    {
        $expr = new Expr();
        $result = $expr->field('total');

        $this->assertSame($expr, $result);
    }
}

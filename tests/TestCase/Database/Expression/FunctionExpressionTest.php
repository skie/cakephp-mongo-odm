<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Expression;

use Cake\Database\ValueBinder;
use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Expression\FunctionExpression;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests the FunctionExpression class (aggregation operator expression).
 *
 * @rewritten-from \Cake\Test\TestCase\Database\Expression\FunctionExpressionTest
 */
#[CoversClass(FunctionExpression::class)]
class FunctionExpressionTest extends TestCase
{
    /**
     * Test a single argument renders as the operator value.
     *
     * @return void
     */
    public function testSingleArgumentRendersAsValue(): void
    {
        $expression = new FunctionExpression('$sum', ['$price']);
        $this->assertSame(['$sum' => '$price'], $expression->getConditions());
    }

    /**
     * Test multiple arguments render as an array.
     *
     * @return void
     */
    public function testMultipleArgumentsRenderAsArray(): void
    {
        $expression = new FunctionExpression('$add', [1, 2, 3]);
        $this->assertSame(['$add' => [1, 2, 3]], $expression->getConditions());
    }

    /**
     * Test an empty argument list renders an empty document.
     *
     * @return void
     */
    public function testEmptyArgumentsRenderEmptyDocument(): void
    {
        $expression = new FunctionExpression('$rand');
        $this->assertEquals(['$rand' => (object)[]], $expression->getConditions());
    }

    /**
     * Test the operator name is normalised with a leading dollar sign.
     *
     * @return void
     */
    public function testNameIsNormalised(): void
    {
        $expression = new FunctionExpression('sum', ['$price']);
        $this->assertSame('$sum', $expression->getName());
        $this->assertSame(['$sum' => '$price'], $expression->getConditions());
    }

    /**
     * Test nested expressions resolve recursively.
     *
     * @return void
     */
    public function testNestedExpressionsResolve(): void
    {
        $ratio = new FunctionExpression('$divide', [
            new FunctionExpression('$subtract', ['$price', '$cost']),
            '$price',
        ]);

        $this->assertSame(
            ['$divide' => [['$subtract' => ['$price', '$cost']], '$price']],
            $ratio->getConditions(),
        );
    }

    /**
     * Test setter and adder mutate the expression.
     *
     * @return void
     */
    public function testSettersAndAdder(): void
    {
        $expression = new FunctionExpression('$sum');
        $expression->setName('$avg')->add('$price');
        $this->assertSame('$avg', $expression->getName());
        $this->assertSame(1, $expression->count());
        $this->assertSame(['$avg' => '$price'], $expression->getConditions());
    }

    /**
     * Test sql() renders the operator document to JSON.
     *
     * @return void
     */
    public function testSqlRendersJson(): void
    {
        $expression = new FunctionExpression('$sum', ['$price']);
        $this->assertSame('{"$sum":"$price"}', $expression->sql(new ValueBinder()));
    }

    /**
     * Test getExpression aliases getConditions.
     *
     * @return void
     */
    public function testGetExpressionAlias(): void
    {
        $expression = new FunctionExpression('$max', ['$price']);
        $this->assertSame($expression->getConditions(), $expression->getExpression());
    }
}

<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Expression;

use Cake\Database\ValueBinder;
use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Expression\ComparisonExpression;
use Crustum\Mongo\Database\Expression\MongoExpressionInterface;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests the ComparisonExpression class.
 *
 * Adapted from cake50/tests/TestCase/Database/Expression/ComparisonExpressionTest.php
 * for the Mongo operator mapping.
 */
#[CoversClass(ComparisonExpression::class)]
class ComparisonExpressionTest extends TestCase
{
    /**
     * Test equality compiles to a plain field value.
     *
     * @return void
     */
    public function testEquality(): void
    {
        $expression = new ComparisonExpression('author_id', 1, '$eq');
        $this->assertEquals(['author_id' => 1], $expression->getConditions());
    }

    /**
     * Test the `=` operator maps to equality.
     *
     * @return void
     */
    public function testEqualsSymbol(): void
    {
        $expression = new ComparisonExpression('author_id', 1, '=');
        $this->assertEquals(['author_id' => 1], $expression->getConditions());
    }

    /**
     * Test the greater-than operator.
     *
     * @return void
     */
    public function testGreaterThan(): void
    {
        $expression = new ComparisonExpression('age', 18, '>');
        $this->assertEquals(['age' => ['$gt' => 18]], $expression->getConditions());
    }

    /**
     * Test the greater-than-or-equal operator.
     *
     * @return void
     */
    public function testGreaterThanOrEqual(): void
    {
        $expression = new ComparisonExpression('age', 18, '>=');
        $this->assertEquals(['age' => ['$gte' => 18]], $expression->getConditions());
    }

    /**
     * Test the less-than operator.
     *
     * @return void
     */
    public function testLessThan(): void
    {
        $expression = new ComparisonExpression('age', 18, '<');
        $this->assertEquals(['age' => ['$lt' => 18]], $expression->getConditions());
    }

    /**
     * Test the less-than-or-equal operator.
     *
     * @return void
     */
    public function testLessThanOrEqual(): void
    {
        $expression = new ComparisonExpression('age', 18, '<=');
        $this->assertEquals(['age' => ['$lte' => 18]], $expression->getConditions());
    }

    /**
     * Test the not-equal operator.
     *
     * @return void
     */
    public function testNotEqual(): void
    {
        $expression = new ComparisonExpression('author_id', 1, '!=');
        $this->assertEquals(['author_id' => ['$ne' => 1]], $expression->getConditions());
    }

    /**
     * Test an explicit Mongo operator is preserved.
     *
     * @return void
     */
    public function testMongoOperator(): void
    {
        $expression = new ComparisonExpression('age', 5, '$mod');
        $this->assertEquals(['age' => ['$mod' => 5]], $expression->getConditions());
    }

    /**
     * Test a nested expression value is compiled.
     *
     * @return void
     */
    public function testNestedExpressionValue(): void
    {
        $nested = new ComparisonExpression('age', 18, '>');
        $expression = new ComparisonExpression('profile', $nested, '$eq');

        $this->assertEquals([
            'profile' => ['age' => ['$gt' => 18]],
        ], $expression->getConditions());
    }

    /**
     * Test traverse visits the expression.
     *
     * @return void
     */
    public function testTraverse(): void
    {
        $expression = new ComparisonExpression('author_id', 1, '=');

        $visited = [];
        $expression->traverse(function ($node) use (&$visited): void {
            $visited[] = $node;
        });

        $this->assertCount(1, $visited);
        $this->assertInstanceOf(ComparisonExpression::class, $visited[0]);
    }

    /**
     * Test the expression implements the Mongo interface.
     *
     * @return void
     */
    public function testInterface(): void
    {
        $expression = new ComparisonExpression('author_id', 1, '=');
        $this->assertInstanceOf(MongoExpressionInterface::class, $expression);
    }

    /**
     * Test sql() returns the JSON-encoded conditions.
     *
     * @return void
     */
    public function testSql(): void
    {
        $expression = new ComparisonExpression('age', 18, '>');
        $this->assertSame('{"age":{"$gt":18}}', $expression->sql(new ValueBinder()));
    }
}

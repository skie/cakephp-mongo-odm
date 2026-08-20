<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Expression;

use Cake\Database\ValueBinder;
use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Expression\BetweenExpression;
use Crustum\Mongo\Database\Expression\ComparisonExpression;
use Crustum\Mongo\Database\Expression\MongoExpressionInterface;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests the BetweenExpression class.
 *
 * Adapted from cake50/tests/TestCase/Database/Expression/BetweenExpressionTest.php
 * for the Mongo `$gte` / `$lte` range compilation.
 *
 * @inspired-by \Cake\Test\TestCase\Database\Expression\BetweenExpressionTest
 */
#[CoversClass(BetweenExpression::class)]
class BetweenExpressionTest extends TestCase
{
    /**
     * Test a plain between range.
     *
     * @return void
     */
    public function testBetween(): void
    {
        $expression = new BetweenExpression('age', 18, 65);
        $this->assertEquals([
            'age' => [
                '$gte' => 18,
                '$lte' => 65,
            ],
        ], $expression->getConditions());
    }

    /**
     * Test between with a string field.
     *
     * @return void
     */
    public function testBetweenStringField(): void
    {
        $expression = new BetweenExpression('created', '2024-01-01', '2024-12-31');
        $this->assertEquals([
            'created' => [
                '$gte' => '2024-01-01',
                '$lte' => '2024-12-31',
            ],
        ], $expression->getConditions());
    }

    /**
     * Test between with nested expression bounds.
     *
     * @return void
     */
    public function testBetweenWithNestedBounds(): void
    {
        $from = new ComparisonExpression('min_age', 18, '$eq');
        $to = new ComparisonExpression('max_age', 65, '$eq');

        $expression = new BetweenExpression('age', $from, $to);
        $this->assertEquals([
            'age' => [
                '$gte' => ['min_age' => 18],
                '$lte' => ['max_age' => 65],
            ],
        ], $expression->getConditions());
    }

    /**
     * Test the expression implements the Mongo interface.
     *
     * @return void
     */
    public function testInterface(): void
    {
        $expression = new BetweenExpression('age', 18, 65);
        $this->assertInstanceOf(MongoExpressionInterface::class, $expression);
    }

    /**
     * Test sql() returns the JSON-encoded conditions.
     *
     * @return void
     */
    public function testSql(): void
    {
        $expression = new BetweenExpression('age', 18, 65);
        $this->assertSame('{"age":{"$gte":18,"$lte":65}}', $expression->sql(new ValueBinder()));
    }

    /**
     * Test traverse visits the expression.
     *
     * @return void
     */
    public function testTraverse(): void
    {
        $expression = new BetweenExpression('age', 18, 65);

        $visited = [];
        $expression->traverse(function ($node) use (&$visited): void {
            $visited[] = $node;
        });

        $this->assertCount(1, $visited);
        $this->assertInstanceOf(BetweenExpression::class, $visited[0]);
    }
}

<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Expression;

use Cake\Database\ValueBinder;
use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Expression\ComparisonExpression;
use Crustum\Mongo\Database\Expression\MongoExpressionInterface;
use Crustum\Mongo\Database\Expression\QueryExpression;
use Crustum\Mongo\Database\QueryBuilder;

/**
 * Tests the QueryExpression class.
 *
 * Adapted from cake50/tests/TestCase/Database/Expression/QueryExpressionTest.php
 * for the Mongo conjunction semantics (`$and` / `$or`).
 */
class QueryExpressionTest extends TestCase
{
    /**
     * Test the default conjunction is `$and`.
     *
     * @return void
     */
    public function testDefaultConjunction(): void
    {
        $expression = new QueryExpression();
        $this->assertSame([], $expression->getConditions());
    }

    /**
     * Test getConditions with a single condition.
     *
     * @return void
     */
    public function testSingleCondition(): void
    {
        $expression = new QueryExpression();
        $expression->add(['author_id' => 1]);

        $this->assertEquals(['author_id' => 1], $expression->getConditions());
    }

    /**
     * Test getConditions with multiple conditions flatten into one array.
     *
     * @return void
     */
    public function testMultipleConditions(): void
    {
        $expression = new QueryExpression();
        $expression->add(['author_id' => 1]);
        $expression->add(['published' => true]);

        $this->assertEquals([
            'author_id' => 1,
            'published' => true,
        ], $expression->getConditions());
    }

    /**
     * Test an OR conjunction wraps in `$or`.
     *
     * @return void
     */
    public function testOrConjunction(): void
    {
        $expression = new QueryExpression([], '$or');
        $expression->add(['title' => 'foo']);
        $expression->add(['title' => 'bar']);

        $this->assertEquals([
            '$or' => [
                ['title' => 'foo'],
                ['title' => 'bar'],
            ],
        ], $expression->getConditions());
    }

    /**
     * Test nested QueryExpression conditions are compiled.
     *
     * @return void
     */
    public function testNestedExpression(): void
    {
        $nested = new QueryExpression([], '$or');
        $nested->add(['title' => 'foo']);
        $nested->add(['title' => 'bar']);

        $expression = new QueryExpression();
        $expression->add(['author_id' => 1]);
        $expression->add($nested);

        $this->assertEquals([
            'author_id' => 1,
            '$or' => [
                ['title' => 'foo'],
                ['title' => 'bar'],
            ],
        ], $expression->getConditions());
    }

    /**
     * Test a ComparisonExpression is compiled into its Mongo shape.
     *
     * @return void
     */
    public function testComparisonExpressionInside(): void
    {
        $expression = new QueryExpression();
        $expression->add([
            new ComparisonExpression('age', 18, '>'),
        ]);

        $this->assertEquals([
            'age' => ['$gt' => 18],
        ], $expression->getConditions());
    }

    /**
     * Test traverse visits the expression and its children.
     *
     * @return void
     */
    public function testTraverse(): void
    {
        $expression = new QueryExpression();
        $expression->add(['author_id' => 1]);

        $visited = [];
        $expression->traverse(function ($node) use (&$visited): void {
            $visited[] = $node;
        });

        $this->assertNotEmpty($visited);
        $this->assertInstanceOf(QueryExpression::class, $visited[0]);
    }

    /**
     * Test the builder exposes query expressions.
     *
     * @return void
     */
    public function testBuilderNewExpr(): void
    {
        $builder = new QueryBuilder();
        $expression = $builder->newExpr(['author_id' => 1]);

        $this->assertInstanceOf(QueryExpression::class, $expression);
        $this->assertInstanceOf(MongoExpressionInterface::class, $expression);
    }

    /**
     * Test sql() returns the JSON-encoded conditions.
     *
     * @return void
     */
    public function testSql(): void
    {
        $expression = new QueryExpression();
        $expression->add(['author_id' => 1]);

        $sql = $expression->sql(new ValueBinder());
        $this->assertJson($sql);
        $this->assertSame('{"author_id":1}', $sql);
    }

    /**
     * Test the and() helper returns a new AND expression.
     *
     * @return void
     */
    public function testAndHelper(): void
    {
        $expression = new QueryExpression();
        $result = $expression->and(['author_id' => 1]);

        $this->assertInstanceOf(QueryExpression::class, $result);
        $this->assertEquals(['author_id' => 1], $result->getConditions());
    }
}

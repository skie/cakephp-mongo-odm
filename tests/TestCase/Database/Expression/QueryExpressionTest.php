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
        $this->assertSame('$and', $expression->getConjunction());
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
     * Test setConjunction / getConjunction accessors.
     *
     * @return void
     */
    public function testConjunctionAccessors(): void
    {
        $expression = new QueryExpression();
        $this->assertSame('$and', $expression->getConjunction());

        $expression->setConjunction('$or');
        $this->assertSame('$or', $expression->getConjunction());
        $this->assertSame($expression, $expression->setConjunction('$or'));
    }

    /**
     * Test the eq()/notEq() helpers compile comparisons.
     *
     * @return void
     */
    public function testEqAndNotEq(): void
    {
        $expression = new QueryExpression();
        $expression->eq('author_id', 1);
        $this->assertEquals(['author_id' => 1], $expression->getConditions());

        $expression = new QueryExpression();
        $expression->notEq('author_id', 1);
        $this->assertEquals(['author_id' => ['$ne' => 1]], $expression->getConditions());
    }

    /**
     * Test the comparison range helpers compile comparisons.
     *
     * @return void
     */
    public function testComparisonHelpers(): void
    {
        $expression = new QueryExpression();
        $expression->gt('min_age', 18)->gte('from_age', 18)->lt('max_age', 65)->lte('to_age', 65);

        $this->assertEquals([
            'min_age' => ['$gt' => 18],
            'from_age' => ['$gte' => 18],
            'max_age' => ['$lt' => 65],
            'to_age' => ['$lte' => 65],
        ], $expression->getConditions());
    }

    /**
     * Test the in()/notIn() helpers compile `$in` / `$nin`.
     *
     * @return void
     */
    public function testInAndNotIn(): void
    {
        $expression = new QueryExpression();
        $expression->in('status', ['a', 'b']);
        $this->assertEquals([
            'status' => ['$in' => ['a', 'b']],
        ], $expression->getConditions());

        $expression = new QueryExpression();
        $expression->notIn('status', ['a', 'b']);
        $this->assertEquals([
            'status' => ['$nin' => ['a', 'b']],
        ], $expression->getConditions());
    }

    /**
     * Test the between()/notBetween() helpers compile ranges.
     *
     * @return void
     */
    public function testBetweenAndNotBetween(): void
    {
        $expression = new QueryExpression();
        $expression->between('age', 18, 65);
        $this->assertEquals([
            'age' => ['$gte' => 18, '$lte' => 65],
        ], $expression->getConditions());

        $expression = new QueryExpression();
        $expression->notBetween('age', 18, 65);
        $this->assertEquals([
            'age' => ['$not' => ['$gte' => 18, '$lte' => 65]],
        ], $expression->getConditions());
    }

    /**
     * Test the like()/notLike() helpers compile regex conditions.
     *
     * @return void
     */
    public function testLikeAndNotLike(): void
    {
        $expression = new QueryExpression();
        $expression->like('name', '^j');
        $this->assertEquals([
            'name' => ['$regex' => '^j', '$options' => ''],
        ], $expression->getConditions());

        $expression = new QueryExpression();
        $expression->notLike('name', '^j');
        $this->assertEquals([
            'name' => ['$not' => ['$regex' => '^j', '$options' => '']],
        ], $expression->getConditions());
    }

    /**
     * Test the isNull()/isNotNull() helpers compile null comparisons.
     *
     * @return void
     */
    public function testIsNullAndIsNotNull(): void
    {
        $expression = new QueryExpression();
        $expression->isNull('deleted');
        $this->assertEquals(['deleted' => null], $expression->getConditions());

        $expression = new QueryExpression();
        $expression->isNotNull('deleted');
        $this->assertEquals(['deleted' => ['$ne' => null]], $expression->getConditions());
    }

    /**
     * Test the exists()/notExists() helpers compile `$exists`.
     *
     * @return void
     */
    public function testExistsAndNotExists(): void
    {
        $expression = new QueryExpression();
        $expression->exists('deleted');
        $this->assertEquals(['deleted' => ['$exists' => true]], $expression->getConditions());

        $expression = new QueryExpression();
        $expression->notExists('deleted');
        $this->assertEquals(['deleted' => ['$exists' => false]], $expression->getConditions());
    }

    /**
     * Test the not() helper negates a condition group.
     *
     * @return void
     */
    public function testNot(): void
    {
        $expression = new QueryExpression();
        $expression->not(['a' => 1]);
        $this->assertEquals(['$nor' => [['a' => 1]]], $expression->getConditions());

        $expression = new QueryExpression();
        $expression->not(new ComparisonExpression('a', 1, '$eq'));
        $this->assertEquals(['$nor' => [['a' => 1]]], $expression->getConditions());
    }

    /**
     * Test the expression implements Countable.
     *
     * @return void
     */
    public function testCount(): void
    {
        $expression = new QueryExpression();
        $this->assertSame(0, count($expression));

        $expression->eq('author_id', 1)->gt('age', 18);
        $this->assertSame(2, count($expression));
    }

    /**
     * Test iterateParts replaces visited parts.
     *
     * @return void
     */
    public function testIterateParts(): void
    {
        $expression = new QueryExpression();
        $expression->add(['author_id' => 1]);
        $expression->add(['published' => true]);

        $expression->iterateParts(function ($part, &$key) {
            if ($part === ['author_id' => 1]) {
                return ['author_id' => 2];
            }

            return $part;
        });

        $this->assertEquals([
            'author_id' => 2,
            'published' => true,
        ], $expression->getConditions());
    }

    /**
     * Test hasNestedExpression detects nested expression objects.
     *
     * @return void
     */
    public function testHasNestedExpression(): void
    {
        $expression = new QueryExpression();
        $expression->add(['author_id' => 1]);
        $this->assertFalse($expression->hasNestedExpression());

        $expression->add(new ComparisonExpression('age', 18, '>'));
        $this->assertTrue($expression->hasNestedExpression());
    }

    /**
     * Test clone deep-clones nested expressions.
     *
     * @return void
     */
    public function testClone(): void
    {
        $nested = new QueryExpression();
        $nested->add(['a' => 1]);

        $expression = new QueryExpression();
        $expression->add($nested);

        $clone = clone $expression;
        $clone->add(['b' => 2]);

        $this->assertEquals([
            'a' => 1,
        ], $expression->getConditions());
        $this->assertEquals([
            'a' => 1,
            'b' => 2,
        ], $clone->getConditions());
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

    /**
     * Test the or() helper returns a new OR expression.
     *
     * @return void
     */
    public function testOrHelper(): void
    {
        $expression = new QueryExpression();
        $result = $expression->or(['author_id' => 1]);

        $this->assertInstanceOf(QueryExpression::class, $result);
        $this->assertEquals(['$or' => [['author_id' => 1]]], $result->getConditions());
    }
}

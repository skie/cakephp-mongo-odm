<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Query;

use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Aggregation\AggregationBuilder;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Database\Expression\ComparisonExpression;
use Crustum\Mongo\Database\Expression\QueryExpression;
use Crustum\Mongo\Database\Query\SelectQuery;
use Crustum\Mongo\Test\TestCase\Database\QueryAssertsTrait;
use InvalidArgumentException;
use Traversable;

/**
 * Tests the SelectQuery class.
 *
 * Adapted from cake60/tests/TestCase/Database/Query/SelectQueryTest.php for the
 * Mongo find/aggregate semantics.
 */
class SelectQueryTest extends TestCase
{
    use QueryAssertsTrait;

    /**
     * @var \Crustum\Mongo\Database\Connection
     */
    protected Connection $connection;

    /**
     * Set up before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = ConnectionManager::get('test_mongo');
    }

    /**
     * Test the query type constant.
     *
     * @return void
     */
    public function testType(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $this->assertQueryType('find', $query->compile());
    }

    /**
     * Test the query is iterable.
     *
     * @return void
     */
    public function testIterable(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $this->assertInstanceOf(Traversable::class, $query->getIterator());
    }

    /**
     * Test select() with a list of fields.
     *
     * @return void
     */
    public function testSelectFieldsOnly(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->select(['title', 'body']);

        $this->assertOptions(['projection' => ['title' => 1, 'body' => 1]], $query->compile());
    }

    /**
     * Test select() with a single string field.
     *
     * @return void
     */
    public function testSelectStringField(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->select('title');

        $this->assertOptions(['projection' => ['title' => 1]], $query->compile());
    }

    /**
     * Test select() with an exclusion value.
     *
     * @return void
     */
    public function testSelectExclusion(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->select(['body' => 0]);

        $this->assertOptions(['projection' => ['body' => 0]], $query->compile());
    }

    /**
     * Test select() accepts a closure returning fields.
     *
     * @return void
     */
    public function testSelectClosure(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->select(function ($q) use ($query): array {
            $this->assertSame($query, $q);

            return ['title', 'body'];
        });

        $this->assertOptions(['projection' => ['title' => 1, 'body' => 1]], $query->compile());
    }

    /**
     * Test multiple select() calls merge projections.
     *
     * @return void
     */
    public function testSelectMerges(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->select(['title'])->select(['body']);

        $this->assertOptions(['projection' => ['title' => 1, 'body' => 1]], $query->compile());
    }

    /**
     * Test select() overwrite replaces the projection.
     *
     * @return void
     */
    public function testSelectOverwrite(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->select(['title'])->select(['body'], true);

        $this->assertOptions(['projection' => ['body' => 1]], $query->compile());
    }

    /**
     * Test select() is fluent.
     *
     * @return void
     */
    public function testSelectIsFluent(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $this->assertSame($query, $query->select(['title']));
    }

    /**
     * Test a simple equality condition.
     *
     * @return void
     */
    public function testSelectSimpleWhere(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->where(['author_id' => 1]);

        $this->assertFilter(['author_id' => 1], $query->compile());
    }

    /**
     * Test where() is fluent.
     *
     * @return void
     */
    public function testWhereIsFluent(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $this->assertSame($query, $query->where(['author_id' => 1]));
    }

    /**
     * Test the greater-than operator.
     *
     * @return void
     */
    public function testSelectWhereOperatorMoreThan(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->where(['age >' => 18]);

        $this->assertFilter(['age' => ['$gt' => 18]], $query->compile());
    }

    /**
     * Test the less-than operator.
     *
     * @return void
     */
    public function testSelectWhereOperatorLessThan(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->where(['age <' => 18]);

        $this->assertFilter(['age' => ['$lt' => 18]], $query->compile());
    }

    /**
     * Test the less-than-or-equal operator.
     *
     * @return void
     */
    public function testSelectWhereOperatorLessThanEqual(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->where(['age <=' => 18]);

        $this->assertFilter(['age' => ['$lte' => 18]], $query->compile());
    }

    /**
     * Test the greater-than-or-equal operator.
     *
     * @return void
     */
    public function testSelectWhereOperatorMoreThanEqual(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->where(['age >=' => 18]);

        $this->assertFilter(['age' => ['$gte' => 18]], $query->compile());
    }

    /**
     * Test the not-equal operator.
     *
     * @return void
     */
    public function testSelectWhereOperatorNotEqual(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->where(['author_id !=' => 1]);

        $this->assertFilter(['author_id' => ['$ne' => 1]], $query->compile());
    }

    /**
     * Test the not-equal operator with SQL-style `<>`.
     *
     * @return void
     */
    public function testSelectWhereOperatorNotEqualSql(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->where(['author_id <>' => 1]);

        $this->assertFilter(['author_id' => ['$ne' => 1]], $query->compile());
    }

    /**
     * Test the LIKE operator maps to regex.
     *
     * @return void
     */
    public function testSelectWhereOperatorLike(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->where(['name LIKE' => '%foo%']);

        $this->assertFilter(['name' => ['$regex' => '^.*foo.*$']], $query->compile());
    }

    /**
     * Test LIKE with a single-char wildcard.
     *
     * @return void
     */
    public function testSelectWhereOperatorLikeExpansion(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->where(['name LIKE' => 'a_c']);

        $this->assertFilter(['name' => ['$regex' => '^a.c$']], $query->compile());
    }

    /**
     * Test the NOT LIKE operator maps to a negated regex.
     *
     * @return void
     */
    public function testSelectWhereOperatorNotLike(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->where(['name NOT LIKE' => '%foo%']);

        $this->assertFilter(['name' => ['$not' => ['$regex' => '^.*foo.*$']]], $query->compile());
    }

    /**
     * Test a null value compiles to `$exists => false`.
     *
     * @return void
     */
    public function testSelectWhereNull(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->where(['deleted IS' => null]);

        $this->assertFilter(['deleted' => ['$exists' => false]], $query->compile());
    }

    /**
     * Test `IS NOT` with a null value compiles to `$exists => true`.
     *
     * @return void
     */
    public function testSelectWhereNotNull(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->where(['deleted IS NOT' => null]);

        $this->assertFilter(['deleted' => ['$exists' => true]], $query->compile());
    }

    /**
     * Test `IS NOT` with a value compiles to `$ne`.
     *
     * @return void
     */
    public function testSelectWhereIsNotValue(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->where(['deleted IS NOT' => 5]);

        $this->assertFilter(['deleted' => ['$ne' => 5]], $query->compile());
    }

    /**
     * Test an IN list compiles to `$in`.
     *
     * @return void
     */
    public function testWhereInArray(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->where(['id IN' => [1, 2, 3]]);

        $this->assertFilter(['id' => ['$in' => [1, 2, 3]]], $query->compile());
    }

    /**
     * Test a NOT IN list compiles to `$nin`.
     *
     * @return void
     */
    public function testWhereNotInList(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->where(['id NOT IN' => [1, 2]]);

        $this->assertFilter(['id' => ['$nin' => [1, 2]]], $query->compile());
    }

    /**
     * Test a BETWEEN range compiles to `$gte`/`$lte`.
     *
     * @return void
     */
    public function testWhereWithBetween(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->where(['age between' => [1, 10]]);

        $this->assertFilter(['age' => ['$gte' => 1, '$lte' => 10]], $query->compile());
    }

    /**
     * Test multiple conditions on the same field merge their operators.
     *
     * @return void
     */
    public function testWhereSameFieldOperators(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->where(['age >' => 10, 'age <' => 20]);

        $this->assertFilter(['age' => ['$gt' => 10, '$lt' => 20]], $query->compile());
    }

    /**
     * Test a dotted field name is preserved.
     *
     * @return void
     */
    public function testWhereDottedField(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->where(['a.b.c' => 1]);

        $this->assertFilter(['a.b.c' => 1], $query->compile());
    }

    /**
     * Test an empty condition array is a no-op.
     *
     * @return void
     */
    public function testWhereEmptyValues(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->where([]);

        $this->assertFilter([], $query->compile());
    }

    /**
     * Test a null condition is a no-op.
     *
     * @return void
     */
    public function testWhereNull(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->where(null);

        $this->assertFilter([], $query->compile());
    }

    /**
     * Test whereNull() sugar compiles to `$exists => false`.
     *
     * @return void
     */
    public function testWhereNullSugar(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->whereNull('deleted');

        $this->assertFilter(['deleted' => ['$exists' => false]], $query->compile());
    }

    /**
     * Test whereNotNull() sugar compiles to `$exists => true`.
     *
     * @return void
     */
    public function testWhereNotNullSugar(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->whereNotNull('name');

        $this->assertFilter(['name' => ['$exists' => true]], $query->compile());
    }

    /**
     * Test whereInList() sugar compiles to `$in`.
     *
     * @return void
     */
    public function testWhereInListSugar(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->whereInList('status', ['a', 'b']);

        $this->assertFilter(['status' => ['$in' => ['a', 'b']]], $query->compile());
    }

    /**
     * Test whereInList() with an empty list and allowEmpty applies an always-false filter.
     *
     * @return void
     */
    public function testWhereInListEmptyAllowEmpty(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->whereInList('status', [], ['allowEmpty' => true]);

        $this->assertFilter(['$expr' => ['$eq' => [1, 0]]], $query->compile());
    }

    /**
     * Test whereNotInList() sugar compiles to `$nin`.
     *
     * @return void
     */
    public function testWhereNotInListSugar(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->whereNotInList('status', ['a']);

        $this->assertFilter(['status' => ['$nin' => ['a']]], $query->compile());
    }

    /**
     * Test whereNotInListOrNull() sugar compiles to an OR of `$nin` and `$exists => false`.
     *
     * @return void
     */
    public function testWhereNotInListOrNullSugar(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->whereNotInListOrNull('status', ['a']);

        $this->assertFilter(
            ['$or' => [['status' => ['$nin' => ['a']]], ['status' => ['$exists' => false]]]],
            $query->compile(),
        );
    }

    /**
     * Test where() accepts a closure returning a plain array.
     *
     * @return void
     */
    public function testSelectWhereUsingClosure(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->where(fn(): array => ['author_id' => 1]);

        $this->assertFilter(['author_id' => 1], $query->compile());
    }

    /**
     * Test where() accepts a closure returning an expression.
     *
     * The closure receives `(QueryExpression $exp, SelectQuery $query)`.
     *
     * @return void
     */
    public function testSelectWhereClosureExpression(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->where(fn(QueryExpression $exp, SelectQuery $q): QueryExpression => $exp->eq('author_id', 1));

        $this->assertFilter(['author_id' => 1], $query->compile());
    }

    /**
     * Test where() accepts a QueryExpression.
     *
     * @return void
     */
    public function testWhereExpression(): void
    {
        $expression = new QueryExpression();
        $expression->add(['author_id' => 1]);

        $query = new SelectQuery($this->connection, 'articles');
        $query->where($expression);

        $this->assertFilter(['author_id' => 1], $query->compile());
    }

    /**
     * Test where() accepts a ComparisonExpression.
     *
     * @return void
     */
    public function testWhereComparisonExpression(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->where(new ComparisonExpression('age', 18, '>'));

        $this->assertFilter(['age' => ['$gt' => 18]], $query->compile());
    }

    /**
     * Test where() with an OR array.
     *
     * @return void
     */
    public function testWhereOr(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->where(['OR' => ['a' => 1, 'b' => 2]]);

        $this->assertFilter(['$or' => [['a' => 1], ['b' => 2]]], $query->compile());
    }

    /**
     * Test where() with an AND array.
     *
     * @return void
     */
    public function testWhereAnd(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->where(['AND' => ['a' => 1, 'b' => 2]]);

        $this->assertFilter(['$and' => [['a' => 1], ['b' => 2]]], $query->compile());
    }

    /**
     * Test where() with a NOT array compiles to `$nor`.
     *
     * @return void
     */
    public function testSelectWhereNot(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->where(['NOT' => ['a' => 1]]);

        $this->assertFilter(['$nor' => [['a' => 1]]], $query->compile());
    }

    /**
     * Test a nested NOT-of-OR compiles to nested `$nor`/`$or`.
     *
     * @return void
     */
    public function testSelectWhereNotNested(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->where(['NOT' => ['OR' => ['a' => 1, 'b' => 2]]]);

        $this->assertFilter([
            '$nor' => [
                ['$or' => [['a' => 1], ['b' => 2]]],
            ],
        ], $query->compile());
    }

    /**
     * Test a nested OR-of-AND compiles to Mongo list form.
     *
     * @return void
     */
    public function testSelectWhereNestedOrAnd(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->where(['OR' => ['a' => 1, 'AND' => ['b' => 2, 'c' => 3]]]);

        $this->assertFilter([
            '$or' => [
                ['a' => 1],
                ['$and' => [['b' => 2], ['c' => 3]]],
            ],
        ], $query->compile());
    }

    /**
     * Test a raw `$or` array is preserved.
     *
     * @return void
     */
    public function testWhereRawDollarOperator(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->where(['$or' => [['a' => 1], ['b' => 2]]]);

        $this->assertFilter(['$or' => [['a' => 1], ['b' => 2]]], $query->compile());
    }

    /**
     * Test a list of condition arrays merges into one filter.
     *
     * @return void
     */
    public function testWhereListMerges(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->where([['a' => 1], ['b' => 2]]);

        $this->assertFilter(['a' => 1, 'b' => 2], $query->compile());
    }

    /**
     * Test multiple where() calls merge conditions.
     *
     * @return void
     */
    public function testWhereMerges(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->where(['a' => 1])->where(['b' => 2]);

        $this->assertFilter(['a' => 1, 'b' => 2], $query->compile());
    }

    /**
     * Test where() with overwrite replaces previous conditions.
     *
     * @return void
     */
    public function testWhereOverwrite(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->where(['a' => 1])->where(['b' => 2], [], true);

        $this->assertFilter(['b' => 2], $query->compile());
    }

    /**
     * Test andWhere() wraps existing and new conditions in `$and`.
     *
     * @return void
     */
    public function testSelectAndWhere(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->where(['author_id' => 1])->andWhere(['published' => true]);

        $this->assertFilter([
            '$and' => [
                ['author_id' => 1],
                ['published' => true],
            ],
        ], $query->compile());
    }

    /**
     * Test andWhere() without a prior condition acts like where().
     *
     * @return void
     */
    public function testSelectAndWhereNoPreviousCondition(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->andWhere(['published' => true]);

        $this->assertFilter(['published' => true], $query->compile());
    }

    /**
     * Test andWhere() accepts a closure.
     *
     * @return void
     */
    public function testSelectAndWhereUsingClosure(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->where(['a' => 1])->andWhere(fn(): array => ['b' => 2]);

        $this->assertFilter([
            '$and' => [
                ['a' => 1],
                ['b' => 2],
            ],
        ], $query->compile());
    }

    /**
     * Test andWhere() is fluent.
     *
     * @return void
     */
    public function testAndWhereIsFluent(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $this->assertSame($query, $query->andWhere(['a' => 1]));
    }

    /**
     * Test orderBy() with a direction string.
     *
     * @return void
     */
    public function testSelectOrderBy(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->orderBy(['title' => 'desc']);

        $this->assertOptions(['sort' => ['title' => -1]], $query->compile());
    }

    /**
     * Test orderBy() with an uppercase direction.
     *
     * @return void
     */
    public function testSelectOrderByAsc(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->orderBy(['title' => 'ASC']);

        $this->assertOptions(['sort' => ['title' => 1]], $query->compile());
    }

    /**
     * Test orderBy() with numeric directions.
     *
     * @return void
     */
    public function testSelectOrderByDesc(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->orderBy(['title' => -1]);

        $this->assertOptions(['sort' => ['title' => -1]], $query->compile());
    }

    /**
     * Test orderBy() with a single string field sorts ascending.
     *
     * @return void
     */
    public function testSelectOrderByString(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->orderBy('title');

        $this->assertOptions(['sort' => ['title' => 1]], $query->compile());
    }

    /**
     * Test orderBy() with a string field and direction.
     *
     * @return void
     */
    public function testSelectOrderByStringWithDirection(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->orderBy('title desc');

        $this->assertOptions(['sort' => ['title' => -1]], $query->compile());
    }

    /**
     * Test orderBy() accepts a closure.
     *
     * @return void
     */
    public function testSelectOrderByClosure(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->orderBy(fn(): array => ['title' => 'desc']);

        $this->assertOptions(['sort' => ['title' => -1]], $query->compile());
    }

    /**
     * Test multiple orderBy() calls merge.
     *
     * @return void
     */
    public function testOrderByMerges(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->orderBy(['a' => 1])->orderBy(['b' => -1]);

        $this->assertOptions(['sort' => ['a' => 1, 'b' => -1]], $query->compile());
    }

    /**
     * Test orderBy() with overwrite replaces the sort.
     *
     * @return void
     */
    public function testOrderByOverwrite(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->orderBy(['a' => 1])->orderBy(['b' => -1], true);

        $this->assertOptions(['sort' => ['b' => -1]], $query->compile());
    }

    /**
     * Test orderBy() is fluent.
     *
     * @return void
     */
    public function testOrderByIsFluent(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $this->assertSame($query, $query->orderBy(['a' => 1]));
    }

    /**
     * Test limit() sets the limit option.
     *
     * @return void
     */
    public function testSelectLimit(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->limit(10);

        $this->assertOptions(['limit' => 10], $query->compile());
    }

    /**
     * Test limit() with null removes the limit.
     *
     * @return void
     */
    public function testSelectLimitNull(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->limit(10)->limit(null);

        $compiled = $query->compile();
        $this->assertArrayNotHasKey('limit', $compiled['options']);
    }

    /**
     * Test skip() sets the skip option.
     *
     * @return void
     */
    public function testSelectOffset(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->skip(20);

        $this->assertOptions(['skip' => 20], $query->compile());
    }

    /**
     * Test skip() with null removes the skip.
     *
     * @return void
     */
    public function testSelectOffsetNull(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->skip(20)->skip(null);

        $compiled = $query->compile();
        $this->assertArrayNotHasKey('skip', $compiled['options']);
    }

    /**
     * Test limit() and skip() combine.
     *
     * @return void
     */
    public function testLimitAndSkip(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->limit(10)->skip(20);

        $this->assertOptions(['limit' => 10, 'skip' => 20], $query->compile());
    }

    /**
     * Test page() with a limit computes skip.
     *
     * @return void
     */
    public function testPage(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->page(3, 10);

        $this->assertOptions(['limit' => 10, 'skip' => 20], $query->compile());
    }

    /**
     * Test page() defaults the limit to 25.
     *
     * @return void
     */
    public function testPageDefaultLimit(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->page(2);

        $this->assertOptions(['limit' => 25, 'skip' => 25], $query->compile());
    }

    /**
     * Test page() keeps an already-set limit.
     *
     * @return void
     */
    public function testPageKeepsLimit(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->limit(5)->page(2);

        $this->assertOptions(['limit' => 5, 'skip' => 5], $query->compile());
    }

    /**
     * Test page() throws for a page below 1.
     *
     * @return void
     */
    public function testPageShouldStartAtOne(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Pages must start at 1.');

        $query = new SelectQuery($this->connection, 'articles');
        $query->page(0);
    }

    /**
     * Test page() is fluent.
     *
     * @return void
     */
    public function testPageIsFluent(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $this->assertSame($query, $query->page(1, 10));
    }

    /**
     * Test options() sets additional options.
     *
     * @return void
     */
    public function testOptions(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->options(['hint' => ['_id' => 1]]);

        $this->assertOptions(['hint' => ['_id' => 1]], $query->compile());
    }

    /**
     * Test multiple options() calls merge.
     *
     * @return void
     */
    public function testOptionsMerges(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->options(['hint' => ['_id' => 1]])->options(['maxTimeMS' => 1000]);

        $this->assertOptions(['hint' => ['_id' => 1], 'maxTimeMS' => 1000], $query->compile());
    }

    /**
     * Test options() is fluent.
     *
     * @return void
     */
    public function testOptionsIsFluent(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $this->assertSame($query, $query->options(['hint' => ['_id' => 1]]));
    }

    /**
     * Test pipeline() compiles as an aggregate.
     *
     * @return void
     */
    public function testPipeline(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->pipeline([
            ['$group' => ['_id' => '$author_id', 'total' => ['$sum' => 1]]],
        ]);

        $this->assertQueryType('aggregate', $query->compile());
        $this->assertPipeline([
            ['$group' => ['_id' => '$author_id', 'total' => ['$sum' => 1]]],
        ], $query->compile());
    }

    /**
     * Test multiple pipeline() calls append stages.
     *
     * @return void
     */
    public function testPipelineAppends(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->pipeline([
            ['$match' => ['a' => 1]],
        ])->pipeline([
            ['$sort' => ['b' => -1]],
        ]);

        $this->assertPipeline([
            ['$match' => ['a' => 1]],
            ['$sort' => ['b' => -1]],
        ], $query->compile());
    }

    /**
     * Test a single stage array is treated as one stage.
     *
     * @return void
     */
    public function testPipelineSingleStage(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->pipeline(['$match' => ['a' => 1]]);

        $this->assertPipeline([
            ['$match' => ['a' => 1]],
        ], $query->compile());
    }

    /**
     * Test filter/sort/projection fold into an aggregate pipeline.
     *
     * @return void
     */
    public function testPipelineFoldsQueryParts(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query
            ->where(['author_id' => 1])
            ->orderBy(['created' => 'desc'])
            ->select(['title'])
            ->skip(5)
            ->limit(10)
            ->pipeline([
                ['$group' => ['_id' => '$author_id', 'total' => ['$sum' => 1]]],
            ]);

        $this->assertPipeline([
            ['$match' => ['author_id' => 1]],
            ['$group' => ['_id' => '$author_id', 'total' => ['$sum' => 1]]],
            ['$sort' => ['created' => -1]],
            ['$project' => ['title' => 1]],
            ['$skip' => 5],
            ['$limit' => 10],
        ], $query->compile());
    }

    /**
     * Test pipeline() is fluent.
     *
     * @return void
     */
    public function testPipelineIsFluent(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $this->assertSame($query, $query->pipeline([['$match' => ['a' => 1]]]));
    }

    /**
     * Test pipeline() with a builder closure appends compiled stages.
     *
     * @return void
     */
    public function testPipelineClosureAppendsStages(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->pipeline(function (AggregationBuilder $builder): void {
            $builder->match(['status' => 'active'])->unionWith('archives');
        });

        $this->assertPipeline([
            ['$match' => ['status' => 'active']],
            ['$unionWith' => ['coll' => 'archives']],
        ], $query->compile());
    }

    /**
     * Test builder count() appends a $count stage.
     *
     * @return void
     */
    public function testBuilderCountStage(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->getBuilder()->count('total');

        $this->assertQueryType('aggregate', $query->compile());
        $this->assertPipeline([
            ['$count' => 'total'],
        ], $query->compile());
    }

    /**
     * Test builder count() composes with existing pipeline stages.
     *
     * @return void
     */
    public function testBuilderCountStageComposes(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->pipeline([
            ['$match' => ['published' => true]],
        ]);
        $query->getBuilder()->count('total');

        $this->assertPipeline([
            ['$match' => ['published' => true]],
            ['$count' => 'total'],
        ], $query->compile());
    }

    /**
     * Test compile() includes the collection.
     *
     * @return void
     */
    public function testCompileIncludesCollection(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $this->assertSame('articles', $query->compile()['collection']);
    }

    /**
     * Test compile() returns the find type.
     *
     * @return void
     */
    public function testCompileFindType(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $this->assertQueryType('find', $query->compile());
    }

    /**
     * Test sql() returns a JSON string.
     *
     * @return void
     */
    public function testToString(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->where(['author_id' => 1]);

        $sql = $query->sql();
        $this->assertIsString($sql);
        $this->assertJson($sql);
    }

    /**
     * Test all() returns matching documents.
     *
     * @return void
     */
    public function testAll(): void
    {
        $collection = $this->connection->getCollection('select_all_test');
        $collection->deleteMany([]);
        $collection->insertMany([
            ['author_id' => 1],
            ['author_id' => 1],
            ['author_id' => 2],
        ]);

        $query = new SelectQuery($this->connection, 'select_all_test');
        $query->where(['author_id' => 1]);

        $this->assertCount(2, $query->all());
        $collection->deleteMany([]);
    }

    /**
     * Test distinct() returns unique values.
     *
     * @return void
     */
    public function testDistinct(): void
    {
        $collection = $this->connection->getCollection('select_distinct_test');
        $collection->deleteMany([]);
        $collection->insertMany([
            ['author_id' => 1],
            ['author_id' => 1],
            ['author_id' => 2],
        ]);

        $query = new SelectQuery($this->connection, 'select_distinct_test');
        $values = $query->distinctValues('author_id');

        $this->assertSame([1, 2], $values);
        $collection->deleteMany([]);
    }

    /**
     * Test distinct() respects the filter.
     *
     * @return void
     */
    public function testDistinctWithFilter(): void
    {
        $collection = $this->connection->getCollection('select_distinct_filter_test');
        $collection->deleteMany([]);
        $collection->insertMany([
            ['author_id' => 1, 'published' => true],
            ['author_id' => 1, 'published' => false],
            ['author_id' => 2, 'published' => true],
        ]);

        $query = new SelectQuery($this->connection, 'select_distinct_filter_test');
        $query->where(['published' => true]);

        $this->assertSame([1, 2], $query->distinctValues('author_id'));
        $collection->deleteMany([]);
    }

    /**
     * Test distinct() without a connection returns an empty array.
     *
     * @return void
     */
    public function testDistinctNoConnection(): void
    {
        $query = new SelectQuery();
        $query->from('articles');

        $this->assertSame([], $query->distinctValues('author_id'));
    }

    /**
     * Test getIterator() executes the query.
     *
     * @return void
     */
    public function testGetIterator(): void
    {
        $collection = $this->connection->getCollection('select_iterator_test');
        $collection->deleteMany([]);
        $collection->insertMany([
            ['title' => 'One'],
            ['title' => 'Two'],
        ]);

        $query = new SelectQuery($this->connection, 'select_iterator_test');
        $this->assertCount(2, iterator_to_array($query, false));
        $collection->deleteMany([]);
    }

    /**
     * Test all fluent methods chain.
     *
     * @return void
     */
    public function testFluentChaining(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $this->assertSame($query, $query->where(['author_id' => 1]));
        $this->assertSame($query, $query->andWhere(['published' => true]));
        $this->assertSame($query, $query->select(['title']));
        $this->assertSame($query, $query->orderBy(['created' => 'desc']));
        $this->assertSame($query, $query->limit(10));
        $this->assertSame($query, $query->skip(5));
        $this->assertSame($query, $query->options(['hint' => ['_id' => 1]]));
    }
}

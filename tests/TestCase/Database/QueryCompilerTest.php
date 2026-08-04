<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Expression\ComparisonExpression;
use Crustum\Mongo\Database\Query\QueryCompiler;
use Crustum\Mongo\Database\QueryBuilder;

/**
 * Tests the QueryCompiler class.
 *
 * Adapted from cake50/tests/TestCase/Database/QueryCompilerTest.php. Instead of
 * SQL strings, the compiled shape is asserted: `['type', 'filter', 'options']`
 * for find and `['type', 'pipeline', 'options']` for aggregate.
 */
class QueryCompilerTest extends TestCase
{
    use QueryAssertsTrait;

    /**
     * The compiler under test.
     *
     * @var \Crustum\Mongo\Database\Query\QueryCompiler
     */
    protected QueryCompiler $compiler;

    /**
     * Set up before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->compiler = new QueryCompiler();
    }

    /**
     * Tear down after each test.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        parent::tearDown();
        unset($this->compiler);
    }

    /**
     * Test a plain find compiles with the find type.
     *
     * @return void
     */
    public function testSelectFrom(): void
    {
        $this->compiler->select('*');
        $result = $this->compiler->compile();

        $this->assertQueryType('find', $result);
        $this->assertFilter([], $result);
        $this->assertSame(['projection' => ['*' => 1], 'typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']], $result['options']);
    }

    /**
     * Test a where clause compiles into the filter.
     *
     * @return void
     */
    public function testSelectWhere(): void
    {
        $this->compiler->select('*')->where(['author_id' => 1]);
        $result = $this->compiler->compile();

        $this->assertQueryType('find', $result);
        $this->assertFilter(['author_id' => 1], $result);
    }

    /**
     * Test conditions with comparison operators.
     *
     * @return void
     */
    public function testWhereOperators(): void
    {
        $this->compiler->where(['age >' => 18]);
        $result = $this->compiler->compile();

        $this->assertFilter(['age' => ['$gt' => 18]], $result);
    }

    /**
     * Test where with an `$or` array.
     *
     * @return void
     */
    public function testWhereOr(): void
    {
        $this->compiler->where(['OR' => [
            'title' => 'foo',
            'body' => 'bar',
        ]]);
        $result = $this->compiler->compile();

        $this->assertFilter(['$or' => [
            ['title' => 'foo'],
            ['body' => 'bar'],
        ]], $result);
    }

    /**
     * Test multiple where calls merge conditions.
     *
     * @return void
     */
    public function testWhereMergesConditions(): void
    {
        $this->compiler
            ->where(['author_id' => 1])
            ->where(['published' => true]);
        $result = $this->compiler->compile();

        $this->assertFilter(['author_id' => 1, 'published' => true], $result);
    }

    /**
     * Test where with overwrite replaces existing conditions.
     *
     * @return void
     */
    public function testWhereOverwrite(): void
    {
        $this->compiler
            ->where(['author_id' => 1])
            ->where(['published' => true], true);
        $result = $this->compiler->compile();

        $this->assertFilter(['published' => true], $result);
    }

    /**
     * Test andWhere combines conditions with `$and`.
     *
     * @return void
     */
    public function testAndWhere(): void
    {
        $this->compiler
            ->where(['author_id' => 1])
            ->andWhere(['published' => true]);
        $result = $this->compiler->compile();

        $this->assertFilter([
            '$and' => [
                ['author_id' => 1],
                ['published' => true],
            ],
        ], $result);
    }

    /**
     * Test andWhere without a prior condition acts like where.
     *
     * @return void
     */
    public function testAndWhereNoPreviousCondition(): void
    {
        $this->compiler->andWhere(['published' => true]);
        $result = $this->compiler->compile();

        $this->assertFilter(['published' => true], $result);
    }

    /**
     * Test projection builds from a field list.
     *
     * @return void
     */
    public function testSelectFields(): void
    {
        $this->compiler->select(['title', 'body']);
        $result = $this->compiler->compile();

        $this->assertOptions(['projection' => ['title' => 1, 'body' => 1]], $result);
    }

    /**
     * Test projection with exclusion values is preserved.
     *
     * @return void
     */
    public function testSelectWithExclusion(): void
    {
        $this->compiler->select(['body' => 0]);
        $result = $this->compiler->compile();

        $this->assertOptions(['projection' => ['body' => 0]], $result);
    }

    /**
     * Test select overwrites the projection.
     *
     * @return void
     */
    public function testSelectOverwrite(): void
    {
        $this->compiler
            ->select(['title'])
            ->select(['body'], true);
        $result = $this->compiler->compile();

        $this->assertOptions(['projection' => ['body' => 1]], $result);
    }

    /**
     * Test orderBy compiles into sort options.
     *
     * @return void
     */
    public function testOrderBy(): void
    {
        $this->compiler->orderBy(['title' => 'desc']);
        $result = $this->compiler->compile();

        $this->assertOptions(['sort' => ['title' => -1]], $result);
    }

    /**
     * Test orderBy with a direction string.
     *
     * @return void
     */
    public function testOrderByString(): void
    {
        $this->compiler->orderBy('title');
        $result = $this->compiler->compile();

        $this->assertOptions(['sort' => ['title' => 1]], $result);
    }

    /**
     * Test limit and skip options.
     *
     * @return void
     */
    public function testLimitAndSkip(): void
    {
        $this->compiler->limit(10)->skip(20);
        $result = $this->compiler->compile();

        $this->assertOptions(['limit' => 10, 'skip' => 20], $result);
    }

    /**
     * Test additional options are merged.
     *
     * @return void
     */
    public function testOptions(): void
    {
        $this->compiler->options(['hint' => ['_id' => 1]]);
        $result = $this->compiler->compile();

        $this->assertOptions(['hint' => ['_id' => 1]], $result);
    }

    /**
     * Test a pipeline compiles as an aggregate.
     *
     * @return void
     */
    public function testPipelineCompilesAsAggregate(): void
    {
        $this->compiler->pipeline([
            ['$group' => ['_id' => '$author_id', 'total' => ['$sum' => 1]]],
        ]);
        $result = $this->compiler->compile();

        $this->assertQueryType('aggregate', $result);
        $this->assertPipeline([
            ['$group' => ['_id' => '$author_id', 'total' => ['$sum' => 1]]],
        ], $result);
    }

    /**
     * Test multiple pipeline stages are appended.
     *
     * @return void
     */
    public function testPipelineAppendsStages(): void
    {
        $this->compiler->pipeline([
            ['$match' => ['author_id' => 1]],
            ['$sort' => ['created' => -1]],
        ]);
        $result = $this->compiler->compile();

        $this->assertQueryType('aggregate', $result);
        $this->assertPipeline([
            ['$match' => ['author_id' => 1]],
            ['$sort' => ['created' => -1]],
        ], $result);
    }

    /**
     * Test a single stage array is treated as one stage.
     *
     * @return void
     */
    public function testPipelineSingleStage(): void
    {
        $this->compiler->pipeline(['$match' => ['author_id' => 1]]);
        $result = $this->compiler->compile();

        $this->assertPipeline([
            ['$match' => ['author_id' => 1]],
        ], $result);
    }

    /**
     * Test that filter/sort/projection are folded into an aggregate pipeline.
     *
     * @return void
     */
    public function testAggregateFoldsQueryParts(): void
    {
        $this->compiler
            ->where(['author_id' => 1])
            ->orderBy(['created' => 'desc'])
            ->select(['title'])
            ->skip(5)
            ->limit(10)
            ->pipeline([
                ['$group' => ['_id' => '$author_id', 'total' => ['$sum' => 1]]],
            ]);
        $result = $this->compiler->compile();

        $this->assertQueryType('aggregate', $result);
        $this->assertPipeline([
            ['$match' => ['author_id' => 1]],
            ['$group' => ['_id' => '$author_id', 'total' => ['$sum' => 1]]],
            ['$sort' => ['created' => -1]],
            ['$project' => ['title' => 1]],
            ['$skip' => 5],
            ['$limit' => 10],
        ], $result);
    }

    /**
     * Test that the $match prepend is skipped for $search heads.
     *
     * @return void
     */
    public function testMatchPrependSkippedForSearchHead(): void
    {
        $this->compiler
            ->where(['status' => 'active'])
            ->pipeline([['$search' => ['index' => 'default', 'text' => ['path' => 'title', 'query' => 'mongo']]]]);
        $result = $this->compiler->compile();

        $this->assertPipeline([
            ['$search' => ['index' => 'default', 'text' => ['path' => 'title', 'query' => 'mongo']]],
        ], $result);
    }

    /**
     * Test that the $match prepend is skipped for $geoNear heads.
     *
     * @return void
     */
    public function testMatchPrependSkippedForGeoNearHead(): void
    {
        $this->compiler
            ->where(['status' => 'active'])
            ->pipeline([['$geoNear' => ['near' => [1.0, 2.0], 'distanceField' => 'd']]]);
        $result = $this->compiler->compile();

        $this->assertPipeline([
            ['$geoNear' => ['near' => [1.0, 2.0], 'distanceField' => 'd']],
        ], $result);
    }

    /**
     * Test that the $match prepend is skipped for $indexStats heads.
     *
     * @return void
     */
    public function testMatchPrependSkippedForIndexStatsHead(): void
    {
        $this->compiler
            ->where(['status' => 'active'])
            ->pipeline([['$indexStats' => []]]);
        $result = $this->compiler->compile();

        $this->assertPipeline([
            ['$indexStats' => []],
        ], $result);
    }

    /**
     * Test that the $match prepend is kept for ordinary heads.
     *
     * @return void
     */
    public function testMatchPrependKeptForOrdinaryHead(): void
    {
        $this->compiler
            ->where(['status' => 'active'])
            ->pipeline([['$group' => ['_id' => '$author_id']]]);
        $result = $this->compiler->compile();

        $this->assertPipeline([
            ['$match' => ['status' => 'active']],
            ['$group' => ['_id' => '$author_id']],
        ], $result);
    }

    /**
     * Test the expression builder is exposed.
     *
     * @return void
     */
    public function testExpr(): void
    {
        $this->assertInstanceOf(QueryBuilder::class, $this->compiler->expr());
        $this->assertSame($this->compiler->expr(), $this->compiler->expr());
    }

    /**
     * Test reset returns the compiler to a pristine state.
     *
     * @return void
     */
    public function testReset(): void
    {
        $this->compiler
            ->where(['author_id' => 1])
            ->select(['title'])
            ->orderBy(['created' => 'desc'])
            ->limit(10)
            ->skip(5)
            ->pipeline([
                ['$group' => ['_id' => '$author_id']],
            ])
            ->options(['hint' => ['_id' => 1]]);

        $this->assertSame($this->compiler, $this->compiler->reset());

        $result = $this->compiler->compile();
        $this->assertQueryType('find', $result);
        $this->assertFilter([], $result);
        $this->assertSame(['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']], $result['options']);
    }

    /**
     * Test getFilter exposes current conditions.
     *
     * @return void
     */
    public function testGetFilter(): void
    {
        $this->compiler->where(['author_id' => 1]);
        $this->assertSame(['author_id' => 1], $this->compiler->getFilter());
    }

    /**
     * Test getProjection exposes current fields.
     *
     * @return void
     */
    public function testGetProjection(): void
    {
        $this->compiler->select(['title', 'body']);
        $this->assertSame(['title' => 1, 'body' => 1], $this->compiler->getProjection());
    }

    /**
     * Test where accepts a Closure returning conditions.
     *
     * @return void
     */
    public function testWhereClosure(): void
    {
        $this->compiler->where(fn(): array => ['author_id' => 1]);
        $result = $this->compiler->compile();

        $this->assertFilter(['author_id' => 1], $result);
    }

    /**
     * Test where accepts a Closure returning a QueryExpression.
     *
     * @return void
     */
    public function testWhereClosureExpression(): void
    {
        $this->compiler->where(fn($builder) => $builder->comparison('author_id', 1, '$eq'));
        $result = $this->compiler->compile();

        $this->assertFilter(['author_id' => 1], $result);
    }

    /**
     * Test where accepts a MongoExpressionInterface.
     *
     * @return void
     */
    public function testWhereExpression(): void
    {
        $expression = new ComparisonExpression('age', 18, '>');
        $this->compiler->where($expression);
        $result = $this->compiler->compile();

        $this->assertFilter(['age' => ['$gt' => 18]], $result);
    }

    /**
     * Test NOT conditions compile to `$nor`.
     *
     * @return void
     */
    public function testWhereNot(): void
    {
        $this->compiler->where(['NOT' => ['a' => 1]]);
        $result = $this->compiler->compile();

        $this->assertFilter(['$nor' => [['a' => 1]]], $result);
    }

    /**
     * Test nested OR-of-AND compiles to Mongo list form.
     *
     * @return void
     */
    public function testWhereNestedOrAnd(): void
    {
        $this->compiler->where(['OR' => ['a' => 1, 'AND' => ['b' => 2, 'c' => 3]]]);
        $result = $this->compiler->compile();

        $this->assertFilter([
            '$or' => [
                ['a' => 1],
                ['$and' => [['b' => 2], ['c' => 3]]],
            ],
        ], $result);
    }

    /**
     * Test NOT LIKE compiles to a negated regex.
     *
     * @return void
     */
    public function testWhereNotLike(): void
    {
        $this->compiler->where(['name NOT LIKE' => '%foo%']);
        $result = $this->compiler->compile();

        $this->assertFilter(['name' => ['$not' => ['$regex' => '^.*foo.*$']]], $result);
    }

    /**
     * Test select accepts a Closure.
     *
     * @return void
     */
    public function testSelectClosure(): void
    {
        $this->compiler->select(fn(): array => ['title', 'body']);
        $result = $this->compiler->compile();

        $this->assertOptions(['projection' => ['title' => 1, 'body' => 1]], $result);
    }

    /**
     * Test orderBy accepts a Closure.
     *
     * @return void
     */
    public function testOrderByClosure(): void
    {
        $this->compiler->orderBy(fn(): array => ['title' => 'desc']);
        $result = $this->compiler->compile();

        $this->assertOptions(['sort' => ['title' => -1]], $result);
    }

    /**
     * Test orderBy with a string field and direction.
     *
     * @return void
     */
    public function testOrderByStringWithDirection(): void
    {
        $this->compiler->orderBy('title desc');
        $result = $this->compiler->compile();

        $this->assertOptions(['sort' => ['title' => -1]], $result);
    }

    /**
     * Test getLimit exposes the configured limit.
     *
     * @return void
     */
    public function testGetLimit(): void
    {
        $this->compiler->limit(10);
        $this->assertSame(10, $this->compiler->getLimit());

        $this->compiler->limit(null);
        $this->assertNull($this->compiler->getLimit());
    }

    /**
     * Test where with a raw `$or` array is preserved.
     *
     * @return void
     */
    public function testWhereRawDollarOperator(): void
    {
        $this->compiler->where(['$or' => [['a' => 1], ['b' => 2]]]);
        $result = $this->compiler->compile();

        $this->assertFilter(['$or' => [['a' => 1], ['b' => 2]]], $result);
    }

    /**
     * Test a list of condition arrays merges into one filter.
     *
     * @return void
     */
    public function testWhereListMerges(): void
    {
        $this->compiler->where([['a' => 1], ['b' => 2]]);
        $result = $this->compiler->compile();

        $this->assertFilter(['a' => 1, 'b' => 2], $result);
    }

    /**
     * Test same-field operator conditions merge.
     *
     * @return void
     */
    public function testWhereSameFieldOperators(): void
    {
        $this->compiler->where(['age >' => 10, 'age <' => 20]);
        $result = $this->compiler->compile();

        $this->assertFilter(['age' => ['$gt' => 10, '$lt' => 20]], $result);
    }
}

<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Query;

use Cake\Database\ExpressionInterface;
use Cake\Database\ValueBinder;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use Closure;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Database\Expression\FunctionExpression;
use Crustum\Mongo\Database\Expression\QueryExpression;
use Crustum\Mongo\Database\Query\SelectQuery;
use Crustum\Mongo\Database\Query\Window;
use Crustum\Mongo\Database\TypeMap;
use Crustum\Mongo\Test\TestCase\Database\QueryAssertsTrait;
use InvalidArgumentException;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

/**
 * Tests ODM Phase 1.3 database API & closure parity on SelectQuery.
 */
class SelectQueryParityTest extends TestCase
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
     * Test the where() closure receives `(QueryExpression $exp, SelectQuery $query)`.
     *
     * @return void
     */
    public function testWhereClosureReceiver(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->where(function (QueryExpression $exp, SelectQuery $queryArg) use ($query): QueryExpression {
            $this->assertSame($query, $queryArg);

            return $exp->eq('status', 'published')->and($exp->gte('views', 10));
        });

        $this->assertFilter(['status' => 'published', 'views' => ['$gte' => 10]], $query->compile());
    }

    /**
     * Test a where() closure mutating the expression without returning it.
     *
     * @return void
     */
    public function testWhereClosureMutatesExpression(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->where(function (QueryExpression $exp): void {
            $exp->eq('status', 'published');
        });

        $this->assertFilter(['status' => 'published'], $query->compile());
    }

    /**
     * Test where() casts values through the passed type map.
     *
     * @return void
     */
    public function testWhereTypesCasting(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->where(['created' => '2024-01-02 03:04:05'], ['created' => 'date']);

        $filter = $query->compile()['filter'];
        $this->assertArrayHasKey('created', $filter);
        $this->assertInstanceOf(UTCDateTime::class, $filter['created']);
    }

    /**
     * Test where() casts ObjectId strings through the type map.
     *
     * @return void
     */
    public function testWhereTypesCastingObjectId(): void
    {
        $id = (string)new ObjectId();
        $query = new SelectQuery($this->connection, 'articles');
        $query->where(['_id' => $id], ['_id' => 'objectid']);

        $filter = $query->compile()['filter'];
        $this->assertInstanceOf(ObjectId::class, $filter['_id']);
        $this->assertSame($id, (string)$filter['_id']);
    }

    /**
     * Test andWhere() casts values through the passed type map.
     *
     * @return void
     */
    public function testAndWhereTypesCasting(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->where(['author_id' => 1])->andWhere(['created' => '2024-01-02 03:04:05'], ['created' => 'date']);

        $filter = $query->compile()['filter'];
        $this->assertInstanceOf(UTCDateTime::class, $filter['$and'][1]['created']);
    }

    /**
     * Test where() uses the query's default type map when no types are passed.
     *
     * @return void
     */
    public function testWhereUsesDefaultTypes(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->setDefaultTypes(['created' => 'date']);
        $query->where(['created' => '2024-01-02 03:04:05']);

        $filter = $query->compile()['filter'];
        $this->assertInstanceOf(UTCDateTime::class, $filter['created']);
    }

    /**
     * Test select() accepts a numeric field value.
     *
     * @return void
     */
    public function testSelectIntField(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->select(5);

        $this->assertOptions(['projection' => [5 => 1]], $query->compile());
    }

    /**
     * Test select() accepts an aggregation function expression as a field value.
     *
     * @return void
     */
    public function testSelectExpressionValue(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->select(['total' => new FunctionExpression('$sum', ['$x'])]);

        $this->assertOptions(['projection' => ['total' => ['$sum' => '$x']]], $query->compile());
    }

    /**
     * Test select() rejects a non-Mongo expression with a clear exception.
     *
     * @return void
     */
    public function testSelectNonMongoExpressionThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $query = new SelectQuery($this->connection, 'articles');
        $query->select($this->nonMongoExpression());
    }

    /**
     * Test where() rejects a non-Mongo expression with a clear exception.
     *
     * @return void
     */
    public function testWhereNonMongoExpressionThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $query = new SelectQuery($this->connection, 'articles');
        $query->where($this->nonMongoExpression());
    }

    /**
     * Test orderBy() accepts a Mongo expression and extracts its fields.
     *
     * @return void
     */
    public function testOrderByExpression(): void
    {
        $expression = new QueryExpression();
        $expression->add(['title' => 1]);

        $query = new SelectQuery($this->connection, 'articles');
        $query->orderBy($expression);

        $this->assertOptions(['sort' => ['title' => 1]], $query->compile());
    }

    /**
     * Test groupBy() with a single field compiles to a `$group` stage.
     *
     * @return void
     */
    public function testGroupBySingleField(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->groupBy(['author_id']);

        $this->assertQueryType('aggregate', $query->compile());
        $this->assertPipeline([
            ['$group' => ['_id' => '$author_id']],
        ], $query->compile());
    }

    /**
     * Test groupBy() with a string compiles to a `$group` stage.
     *
     * @return void
     */
    public function testGroupByString(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->groupBy('author_id');

        $this->assertPipeline([
            ['$group' => ['_id' => '$author_id']],
        ], $query->compile());
    }

    /**
     * Test groupBy() with multiple fields compiles to an `_id` document.
     *
     * @return void
     */
    public function testGroupByMultipleFields(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->groupBy(['author_id', 'status']);

        $this->assertPipeline([
            ['$group' => ['_id' => ['author_id' => '$author_id', 'status' => '$status']]],
        ], $query->compile());
    }

    /**
     * Test groupBy() with overwrite replaces previous group fields.
     *
     * @return void
     */
    public function testGroupByOverwrite(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->groupBy(['author_id'])->groupBy('status', true);

        $this->assertPipeline([
            ['$group' => ['_id' => '$status']],
        ], $query->compile());
    }

    /**
     * Test groupBy() is fluent.
     *
     * @return void
     */
    public function testGroupByIsFluent(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $this->assertSame($query, $query->groupBy('author_id'));
    }

    /**
     * Test having() compiles to a post-`$group` `$match` stage.
     *
     * @return void
     */
    public function testHavingAfterGroup(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query
            ->where(['published' => true])
            ->groupBy(['author_id'])
            ->having(['total >' => 5]);

        $this->assertPipeline([
            ['$match' => ['published' => true]],
            ['$group' => ['_id' => '$author_id']],
            ['$match' => ['total' => ['$gt' => 5]]],
        ], $query->compile());
    }

    /**
     * Test having() without a prior group still compiles a `$match` stage.
     *
     * @return void
     */
    public function testHavingWithoutGroup(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->having(['total >' => 5]);

        $this->assertPipeline([
            ['$match' => ['total' => ['$gt' => 5]]],
        ], $query->compile());
    }

    /**
     * Test having() accepts a closure with the expression + query receivers.
     *
     * @return void
     */
    public function testHavingClosureReceiver(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->groupBy('author_id')->having(
            fn(QueryExpression $exp, SelectQuery $queryArg): QueryExpression => $exp->gt('total', 5),
        );

        $this->assertPipeline([
            ['$group' => ['_id' => '$author_id']],
            ['$match' => ['total' => ['$gt' => 5]]],
        ], $query->compile());
    }

    /**
     * Test andHaving() merges into the having clause.
     *
     * @return void
     */
    public function testAndHaving(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->groupBy('author_id')->having(['total >' => 5])->andHaving(['published' => true]);

        $this->assertPipeline([
            ['$group' => ['_id' => '$author_id']],
            ['$match' => ['$and' => [['total' => ['$gt' => 5]], ['published' => true]]]],
        ], $query->compile());
    }

    /**
     * Test window() with a raw body array compiles to `$setWindowFields`.
     *
     * @return void
     */
    public function testWindowArray(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->window([
            'partitionBy' => '$department',
            'output' => ['rank' => ['$rank' => []]],
        ]);

        $this->assertPipeline([
            ['$setWindowFields' => [
                'partitionBy' => '$department',
                'output' => ['rank' => ['$rank' => []]],
            ]],
        ], $query->compile());
    }

    /**
     * Test window() with a WindowInterface object compiles to `$setWindowFields`.
     *
     * @return void
     */
    public function testWindowObject(): void
    {
        $window = (new Window())
            ->partitionBy('$department')
            ->sortBy(['date' => 1])
            ->output('cumulative', ['$sum' => '$price']);

        $query = new SelectQuery($this->connection, 'articles');
        $query->window($window);

        $this->assertPipeline([
            ['$setWindowFields' => [
                'partitionBy' => '$department',
                'sortBy' => ['date' => 1],
                'output' => ['cumulative' => ['$sum' => '$price']],
            ]],
        ], $query->compile());
    }

    /**
     * Test window() is fluent.
     *
     * @return void
     */
    public function testWindowIsFluent(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $this->assertSame($query, $query->window(['output' => []]));
    }

    /**
     * Test results-casting toggles.
     *
     * @return void
     */
    public function testResultsCastingToggles(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $this->assertTrue($query->isResultsCastingEnabled());

        $this->assertSame($query, $query->disableResultsCasting());
        $this->assertFalse($query->isResultsCastingEnabled());

        $this->assertSame($query, $query->enableResultsCasting());
        $this->assertTrue($query->isResultsCastingEnabled());
    }

    /**
     * Test decorateResults() applies callbacks to fetched rows.
     *
     * @return void
     */
    public function testDecorateResults(): void
    {
        $collection = $this->connection->getCollection('select_decorate_test');
        $collection->deleteMany([]);
        $collection->insertMany([
            ['price' => 2],
            ['price' => 3],
        ]);

        $query = new SelectQuery($this->connection, 'select_decorate_test');
        $query->decorateResults(static fn(array $row): array => $row + ['total' => $row['price'] * 2]);

        $rows = $query->all()->toArray();
        $this->assertSame(4, $rows[0]['total']);
        $this->assertSame(6, $rows[1]['total']);
        $collection->deleteMany([]);
    }

    /**
     * Test getResultDecorators() returns registered callbacks.
     *
     * @return void
     */
    public function testGetResultDecorators(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $callback = static fn(array $row): array => $row;

        $this->assertSame([], $query->getResultDecorators());
        $query->decorateResults($callback);
        $this->assertSame([$callback], $query->getResultDecorators());
    }

    /**
     * Test decorateResults() with overwrite replaces the decorator stack.
     *
     * @return void
     */
    public function testDecorateResultsOverwrite(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $first = static fn(array $row): array => $row;
        $second = static fn(array $row): array => $row;

        $query->decorateResults($first)->decorateResults($second, true);

        $this->assertSame([$second], $query->getResultDecorators());
    }

    /**
     * Test the select type map accessors.
     *
     * @return void
     */
    public function testSelectTypeMapAccessors(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $this->assertInstanceOf(TypeMap::class, $query->getSelectTypeMap());

        $this->assertSame($query, $query->setSelectTypeMap(['created' => 'date']));
        $this->assertSame('date', $query->getSelectTypeMap()->type('created'));
    }

    /**
     * Test results are cast through the select type map on fetch.
     *
     * @return void
     */
    public function testResultsCastingAppliesTypeMap(): void
    {
        $id = (string)new ObjectId();
        $collection = $this->connection->getCollection('select_casting_test');
        $collection->deleteMany([]);
        $collection->insertOne(['_id' => new ObjectId($id), 'title' => 'One']);

        $query = new SelectQuery($this->connection, 'select_casting_test');
        $query->setSelectTypeMap(['_id' => 'objectid']);

        $row = $query->all()->first();
        $this->assertIsString($row['_id']);
        $this->assertSame($id, $row['_id']);
        $collection->deleteMany([]);
    }

    /**
     * Test disabled results-casting leaves raw values untouched.
     *
     * @return void
     */
    public function testDisabledResultsCastingLeavesRaw(): void
    {
        $id = (string)new ObjectId();
        $collection = $this->connection->getCollection('select_casting_off_test');
        $collection->deleteMany([]);
        $collection->insertOne(['_id' => new ObjectId($id)]);

        $query = new SelectQuery($this->connection, 'select_casting_off_test');
        $query->setSelectTypeMap(['_id' => 'objectid'])->disableResultsCasting();

        $row = $query->all()->first();
        $this->assertInstanceOf(ObjectId::class, $row['_id']);
        $collection->deleteMany([]);
    }

    /**
     * Test __clone() deep-clones the compiler.
     *
     * @return void
     */
    public function testCloneIndependence(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->where(['a' => 1]);

        $clone = clone $query;
        $clone->where(['b' => 2]);

        $this->assertFilter(['a' => 1], $query->compile());
        $this->assertFilter(['a' => 1, 'b' => 2], $clone->compile());
    }

    /**
     * Test __clone() deep-clones the select type map.
     *
     * @return void
     */
    public function testCloneSelectTypeMapIndependent(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->setSelectTypeMap(['created' => 'date']);

        $clone = clone $query;
        $clone->getSelectTypeMap()->setDefaults(['title' => 'string']);

        $this->assertNull($query->getSelectTypeMap()->type('title'));
        $this->assertSame('string', $clone->getSelectTypeMap()->type('title'));
    }

    /**
     * Test __debugInfo() includes the decorator count.
     *
     * @return void
     */
    public function testDebugInfo(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->decorateResults(static fn(array $row): array => $row);

        $info = $query->__debugInfo();
        $this->assertArrayHasKey('decorators', $info);
        $this->assertSame(1, $info['decorators']);
    }

    /**
     * Returns a non-Mongo expression for negative tests.
     *
     * @return \Cake\Database\ExpressionInterface
     */
    protected function nonMongoExpression(): ExpressionInterface
    {
        return new class implements ExpressionInterface {
            public function sql(ValueBinder $binder): string
            {
                return '1 = 1';
            }

            public function traverse(Closure $callback): static
            {
                return $this;
            }
        };
    }
}

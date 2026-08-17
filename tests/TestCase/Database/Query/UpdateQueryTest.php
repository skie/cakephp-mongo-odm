<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Query;

use Cake\Core\Exception\CakeException;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Database\Expression\ComparisonExpression;
use Crustum\Mongo\Database\Query\UpdateQuery;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests the UpdateQuery class.
 *
 * Adapted from cake60/tests/TestCase/Database/Query/UpdateQueryTest.php for the
 * Mongo update operators (`$set`, `$unset`, `$inc`, `$push`, `$pull`, ...).
 */
#[CoversClass(UpdateQuery::class)]
class UpdateQueryTest extends TestCase
{
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
     * Test set() adds a `$set` operator.
     *
     * @return void
     */
    public function testSet(): void
    {
        $query = new UpdateQuery($this->connection, 'articles');
        $this->assertSame($query, $query->set('title', 'New title'));

        $this->assertEquals(['$set' => ['title' => 'New title']], $query->getUpdate());
    }

    /**
     * Test set() with a field map.
     *
     * @return void
     */
    public function testSetArrayFields(): void
    {
        $query = new UpdateQuery($this->connection, 'articles');
        $query->set(['title' => 'New', 'published' => true]);

        $this->assertEquals(['$set' => ['title' => 'New', 'published' => true]], $query->getUpdate());
    }

    /**
     * Test multiple set() calls merge into `$set`.
     *
     * @return void
     */
    public function testSetMerges(): void
    {
        $query = new UpdateQuery($this->connection, 'articles');
        $query->set('title', 'New');
        $query->set('body', 'Body');

        $this->assertEquals(['$set' => ['title' => 'New', 'body' => 'Body']], $query->getUpdate());
    }

    /**
     * Test set() with a nested value.
     *
     * @return void
     */
    public function testSetNestedValue(): void
    {
        $query = new UpdateQuery($this->connection, 'articles');
        $query->set('author', ['name' => 'Jane']);

        $this->assertEquals(['$set' => ['author' => ['name' => 'Jane']]], $query->getUpdate());
    }

    /**
     * Test set() with a dotted field path.
     *
     * @return void
     */
    public function testSetDottedField(): void
    {
        $query = new UpdateQuery($this->connection, 'articles');
        $query->set('author.name', 'Jane');

        $this->assertEquals(['$set' => ['author.name' => 'Jane']], $query->getUpdate());
    }

    /**
     * Test unset() adds a `$unset` operator.
     *
     * @return void
     */
    public function testUnset(): void
    {
        $query = new UpdateQuery($this->connection, 'articles');
        $query->unset('body');

        $this->assertEquals(['$unset' => ['body' => '']], $query->getUpdate());
    }

    /**
     * Test unset() with a list of fields.
     *
     * @return void
     */
    public function testUnsetArrayFields(): void
    {
        $query = new UpdateQuery($this->connection, 'articles');
        $query->unset(['body', 'excerpt']);

        $this->assertEquals(['$unset' => ['body' => '', 'excerpt' => '']], $query->getUpdate());
    }

    /**
     * Test set() with func()->inc() maps to `$inc`.
     *
     * @return void
     */
    public function testSetWithFuncIncExpression(): void
    {
        $query = new UpdateQuery($this->connection, 'articles');
        $query->set(['views' => $query->func()->inc(1)]);

        $this->assertEquals(['$inc' => ['views' => 1]], $query->getUpdate());
    }

    /**
     * Test set() with func()->plus() SQL-style increment sugar.
     *
     * @return void
     */
    public function testSetWithFuncPlusExpression(): void
    {
        $query = new UpdateQuery($this->connection, 'articles');
        $query->set(['views' => $query->func()->plus(5)]);

        $this->assertEquals(['$inc' => ['views' => 5]], $query->getUpdate());
    }

    /**
     * Test increment() adds a `$inc` operator.
     *
     * @return void
     */
    public function testIncrement(): void
    {
        $query = new UpdateQuery($this->connection, 'articles');
        $query->increment('views', 5);

        $this->assertEquals(['$inc' => ['views' => 5]], $query->getUpdate());
    }

    /**
     * Test increment() defaults to 1.
     *
     * @return void
     */
    public function testIncrementDefault(): void
    {
        $query = new UpdateQuery($this->connection, 'articles');
        $query->increment('views');

        $this->assertEquals(['$inc' => ['views' => 1]], $query->getUpdate());
    }

    /**
     * Test increment() with a map.
     *
     * @return void
     */
    public function testIncrementMap(): void
    {
        $query = new UpdateQuery($this->connection, 'articles');
        $query->increment(['views' => 1, 'shares' => 2]);

        $this->assertEquals(['$inc' => ['views' => 1, 'shares' => 2]], $query->getUpdate());
    }

    /**
     * Test decrement() negates the amount.
     *
     * @return void
     */
    public function testDecrement(): void
    {
        $query = new UpdateQuery($this->connection, 'articles');
        $query->decrement('views', 3);

        $this->assertEquals(['$inc' => ['views' => -3]], $query->getUpdate());
    }

    /**
     * Test decrement() defaults to -1.
     *
     * @return void
     */
    public function testDecrementDefault(): void
    {
        $query = new UpdateQuery($this->connection, 'articles');
        $query->decrement('views');

        $this->assertEquals(['$inc' => ['views' => -1]], $query->getUpdate());
    }

    /**
     * Test decrement() with a map negates each amount.
     *
     * @return void
     */
    public function testDecrementMap(): void
    {
        $query = new UpdateQuery($this->connection, 'articles');
        $query->decrement(['views' => 2, 'shares' => 3]);

        $this->assertEquals(['$inc' => ['views' => -2, 'shares' => -3]], $query->getUpdate());
    }

    /**
     * Test push() adds a `$push` operator.
     *
     * @return void
     */
    public function testPush(): void
    {
        $query = new UpdateQuery($this->connection, 'articles');
        $query->push('tags', 'mongo');

        $this->assertEquals(['$push' => ['tags' => 'mongo']], $query->getUpdate());
    }

    /**
     * Test push() with an array value appends the whole value.
     *
     * @return void
     */
    public function testPushArrayValue(): void
    {
        $query = new UpdateQuery($this->connection, 'articles');
        $query->push('tags', ['a', 'b']);

        $this->assertEquals(['$push' => ['tags' => ['a', 'b']]], $query->getUpdate());
    }

    /**
     * Test pull() adds a `$pull` operator.
     *
     * @return void
     */
    public function testPull(): void
    {
        $query = new UpdateQuery($this->connection, 'articles');
        $query->pull('tags', 'mongo');

        $this->assertEquals(['$pull' => ['tags' => 'mongo']], $query->getUpdate());
    }

    /**
     * Test pull() with criteria.
     *
     * @return void
     */
    public function testPullCriteria(): void
    {
        $query = new UpdateQuery($this->connection, 'articles');
        $query->pull('scores', ['$gte' => 90]);

        $this->assertEquals(['$pull' => ['scores' => ['$gte' => 90]]], $query->getUpdate());
    }

    /**
     * Test addToSet() adds a `$addToSet` operator.
     *
     * @return void
     */
    public function testAddToSet(): void
    {
        $query = new UpdateQuery($this->connection, 'articles');
        $query->addToSet('tags', 'mongo');

        $this->assertEquals(['$addToSet' => ['tags' => 'mongo']], $query->getUpdate());
    }

    /**
     * Test addToSet() with a map.
     *
     * @return void
     */
    public function testAddToSetMap(): void
    {
        $query = new UpdateQuery($this->connection, 'articles');
        $query->addToSet(['tags' => 'a', 'roles' => 'admin']);

        $this->assertEquals(['$addToSet' => ['tags' => 'a', 'roles' => 'admin']], $query->getUpdate());
    }

    /**
     * Test pop() adds a `$pop` operator defaulting to -1.
     *
     * @return void
     */
    public function testPop(): void
    {
        $query = new UpdateQuery($this->connection, 'articles');
        $query->pop('tags');

        $this->assertEquals(['$pop' => ['tags' => 1]], $query->getUpdate());
    }

    /**
     * Test pop() with an explicit direction.
     *
     * @return void
     */
    public function testPopLast(): void
    {
        $query = new UpdateQuery($this->connection, 'articles');
        $query->pop('tags', -1);

        $this->assertEquals(['$pop' => ['tags' => -1]], $query->getUpdate());
    }

    /**
     * Test multiply() adds a `$mul` operator.
     *
     * @return void
     */
    public function testMultiply(): void
    {
        $query = new UpdateQuery($this->connection, 'articles');
        $query->multiply('views', 2);

        $this->assertEquals(['$mul' => ['views' => 2]], $query->getUpdate());
    }

    /**
     * Test multiply() with a map.
     *
     * @return void
     */
    public function testMultiplyMap(): void
    {
        $query = new UpdateQuery($this->connection, 'articles');
        $query->multiply(['views' => 2, 'shares' => 3]);

        $this->assertEquals(['$mul' => ['views' => 2, 'shares' => 3]], $query->getUpdate());
    }

    /**
     * Test rename() adds a `$rename` operator.
     *
     * @return void
     */
    public function testRename(): void
    {
        $query = new UpdateQuery($this->connection, 'articles');
        $query->rename('title', 'name');

        $this->assertEquals(['$rename' => ['title' => 'name']], $query->getUpdate());
    }

    /**
     * Test rename() with a map.
     *
     * @return void
     */
    public function testRenameMap(): void
    {
        $query = new UpdateQuery($this->connection, 'articles');
        $query->rename(['title' => 'name', 'body' => 'content']);

        $this->assertEquals(['$rename' => ['title' => 'name', 'body' => 'content']], $query->getUpdate());
    }

    /**
     * Test min() adds a `$min` operator.
     *
     * @return void
     */
    public function testMin(): void
    {
        $query = new UpdateQuery($this->connection, 'articles');
        $query->min('price', 10);

        $this->assertEquals(['$min' => ['price' => 10]], $query->getUpdate());
    }

    /**
     * Test max() adds a `$max` operator.
     *
     * @return void
     */
    public function testMax(): void
    {
        $query = new UpdateQuery($this->connection, 'articles');
        $query->max('price', 100);

        $this->assertEquals(['$max' => ['price' => 100]], $query->getUpdate());
    }

    /**
     * Test currentDate() adds a `$currentDate` operator.
     *
     * @return void
     */
    public function testCurrentDate(): void
    {
        $query = new UpdateQuery($this->connection, 'articles');
        $query->currentDate('updated_at');

        $this->assertEquals(['$currentDate' => ['updated_at' => 'date']], $query->getUpdate());
    }

    /**
     * Test currentDate() with a timestamp type.
     *
     * @return void
     */
    public function testCurrentDateTimestamp(): void
    {
        $query = new UpdateQuery($this->connection, 'articles');
        $query->currentDate('updated_at', 'timestamp');

        $this->assertEquals(['$currentDate' => ['updated_at' => 'timestamp']], $query->getUpdate());
    }

    /**
     * Test multiple operator types combine in one update.
     *
     * @return void
     */
    public function testMultipleOperatorsCombine(): void
    {
        $query = new UpdateQuery($this->connection, 'articles');
        $query
            ->set('title', 'New')
            ->increment('views', 1)
            ->push('tags', 'mongo');

        $this->assertEquals([
            '$set' => ['title' => 'New'],
            '$inc' => ['views' => 1],
            '$push' => ['tags' => 'mongo'],
        ], $query->getUpdate());
    }

    /**
     * Test where() adds the filter.
     *
     * @return void
     */
    public function testWhere(): void
    {
        $query = new UpdateQuery($this->connection, 'articles');
        $query->where(['author_id' => 1])->set('published', true);

        $compiled = $query->compile();
        $this->assertEquals(['author_id' => 1], $compiled['filter']);
        $this->assertEquals(['$set' => ['published' => true]], $compiled['update']);
    }

    /**
     * Test where() accepts an expression.
     *
     * @return void
     */
    public function testWhereExpression(): void
    {
        $query = new UpdateQuery($this->connection, 'articles');
        $query->where(new ComparisonExpression('age', 18, '>'))->set('adult', true);

        $compiled = $query->compile();
        $this->assertEquals(['age' => ['$gt' => 18]], $compiled['filter']);
    }

    /**
     * Test where() accepts a closure.
     *
     * @return void
     */
    public function testWhereClosure(): void
    {
        $query = new UpdateQuery($this->connection, 'articles');
        $query->where(fn(): array => ['author_id' => 1])->set('published', true);

        $compiled = $query->compile();
        $this->assertEquals(['author_id' => 1], $compiled['filter']);
    }

    /**
     * Test where() with operator conditions.
     *
     * @return void
     */
    public function testWhereOperators(): void
    {
        $query = new UpdateQuery($this->connection, 'articles');
        $query->where(['views >' => 10])->set('popular', true);

        $compiled = $query->compile();
        $this->assertEquals(['views' => ['$gt' => 10]], $compiled['filter']);
    }

    /**
     * Test the compiled shape.
     *
     * @return void
     */
    public function testCompile(): void
    {
        $query = new UpdateQuery($this->connection, 'articles');
        $query->set('title', 'New');

        $compiled = $query->compile();
        $this->assertSame('update', $compiled['type']);
        $this->assertSame('articles', $compiled['collection']);
        $this->assertSame([], $compiled['filter']);
        $this->assertEquals(['$set' => ['title' => 'New']], $compiled['update']);
        $this->assertSame([], $compiled['options']);
    }

    /**
     * Test the query type constant.
     *
     * @return void
     */
    public function testType(): void
    {
        $query = new UpdateQuery($this->connection, 'articles');
        $this->assertSame('update', $query->compile()['type']);
    }

    /**
     * Test update() sets the target collection and returns $this.
     *
     * @return void
     */
    public function testUpdate(): void
    {
        $query = new UpdateQuery($this->connection);
        $this->assertSame($query, $query->update('posts'));
        $this->assertSame('posts', $query->getCollection());
        $this->assertSame('posts', $query->compile()['collection']);
    }

    /**
     * Test update() with no table leaves the collection unchanged.
     *
     * @return void
     */
    public function testUpdateNoTable(): void
    {
        $query = new UpdateQuery($this->connection, 'articles');
        $this->assertSame($query, $query->update());
        $this->assertSame('articles', $query->getCollection());
    }

    /**
     * Test from() sets the target collection.
     *
     * @return void
     */
    public function testFrom(): void
    {
        $query = new UpdateQuery($this->connection);
        $this->assertSame($query, $query->from('posts'));
        $this->assertSame('posts', $query->getCollection());
    }

    /**
     * Test execute() updates matching documents.
     *
     * @return void
     */
    public function testExecute(): void
    {
        $collection = $this->connection->getCollection('update_query_test');
        $collection->deleteMany([]);
        $collection->insertMany([
            ['author_id' => 1, 'published' => false],
            ['author_id' => 1, 'published' => false],
            ['author_id' => 2, 'published' => false],
        ]);

        $query = new UpdateQuery($this->connection, 'update_query_test');
        $query->where(['author_id' => 1])->set('published', true);

        $count = $query->execute();
        $this->assertSame(2, $count);

        $this->assertSame(2, $collection->countDocuments(['author_id' => 1, 'published' => true]));
        $collection->deleteMany([]);
    }

    /**
     * Test execute() with a compound operator applies it to documents.
     *
     * @return void
     */
    public function testExecuteIncrement(): void
    {
        $collection = $this->connection->getCollection('update_query_test');
        $collection->deleteMany([]);
        $collection->insertMany([
            ['title' => 'A', 'views' => 10],
            ['title' => 'B', 'views' => 20],
        ]);

        $query = new UpdateQuery($this->connection, 'update_query_test');
        $query->increment('views', 5);

        $this->assertSame(2, $query->execute());

        $this->assertSame(15, $collection->findOne(['title' => 'A'])['views']);
        $this->assertSame(25, $collection->findOne(['title' => 'B'])['views']);
        $collection->deleteMany([]);
    }

    /**
     * Test execute() with push appends to an array field.
     *
     * @return void
     */
    public function testExecutePush(): void
    {
        $collection = $this->connection->getCollection('update_query_test');
        $collection->deleteMany([]);
        $collection->insertOne(['title' => 'A', 'tags' => ['x']]);

        $query = new UpdateQuery($this->connection, 'update_query_test');
        $query->where(['title' => 'A'])->push('tags', 'y');

        $query->execute();

        $tags = iterator_to_array($collection->findOne(['title' => 'A'])['tags']);
        $this->assertEquals(['x', 'y'], $tags);
        $collection->deleteMany([]);
    }

    /**
     * Test execute() with rename renames a field.
     *
     * @return void
     */
    public function testExecuteRename(): void
    {
        $collection = $this->connection->getCollection('update_query_test');
        $collection->deleteMany([]);
        $collection->insertOne(['title' => 'A', 'body' => 'Body']);

        $query = new UpdateQuery($this->connection, 'update_query_test');
        $query->where(['title' => 'A'])->rename('body', 'content');

        $query->execute();

        $stored = $collection->findOne(['title' => 'A']);
        $this->assertArrayNotHasKey('body', $stored);
        $this->assertSame('Body', $stored['content']);
        $collection->deleteMany([]);
    }

    /**
     * Test execute() with no matching documents returns 0.
     *
     * @return void
     */
    public function testExecuteNoMatches(): void
    {
        $collection = $this->connection->getCollection('update_query_test');
        $collection->deleteMany([]);

        $query = new UpdateQuery($this->connection, 'update_query_test');
        $query->where(['missing' => 1])->set('title', 'New');

        $this->assertSame(0, $query->execute());
        $collection->deleteMany([]);
    }

    /**
     * Test execute() with no connection throws.
     *
     * @return void
     */
    public function testExecuteWithoutConnectionThrows(): void
    {
        $this->expectException(CakeException::class);
        $this->expectExceptionMessage('Query has no connection set.');

        $query = new UpdateQuery();
        $query->from('articles')->set('title', 'New')->execute();
    }

    /**
     * Test getConnection/getCollection accessors.
     *
     * @return void
     */
    public function testAccessors(): void
    {
        $query = new UpdateQuery($this->connection, 'articles');
        $this->assertSame($this->connection, $query->getConnection());
        $this->assertSame('articles', $query->getCollection());
    }
}

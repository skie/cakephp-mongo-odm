<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Query;

use Cake\Core\Exception\CakeException;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Database\Expression\ComparisonExpression;
use Crustum\Mongo\Database\Query\DeleteQuery;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests the DeleteQuery class.
 *
 * Adapted from cake60/tests/TestCase/Database/Query/DeleteQueryTest.php for the
 * Mongo deleteMany semantics.
 */
#[CoversClass(DeleteQuery::class)]
class DeleteQueryTest extends TestCase
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
     * Test where() adds the filter.
     *
     * @return void
     */
    public function testWhere(): void
    {
        $query = new DeleteQuery($this->connection, 'articles');
        $this->assertSame($query, $query->where(['author_id' => 1]));

        $compiled = $query->compile();
        $this->assertEquals(['author_id' => 1], $compiled['filter']);
    }

    /**
     * Test where() accepts an expression.
     *
     * @return void
     */
    public function testWhereExpression(): void
    {
        $query = new DeleteQuery($this->connection, 'articles');
        $query->where(new ComparisonExpression('age', 18, '>'));

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
        $query = new DeleteQuery($this->connection, 'articles');
        $query->where(fn(): array => ['author_id' => 1]);

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
        $query = new DeleteQuery($this->connection, 'articles');
        $query->where(['views <' => 10]);

        $compiled = $query->compile();
        $this->assertEquals(['views' => ['$lt' => 10]], $compiled['filter']);
    }

    /**
     * Test an empty filter compiles to delete-everything.
     *
     * @return void
     */
    public function testDeleteNoWhere(): void
    {
        $query = new DeleteQuery($this->connection, 'articles');

        $compiled = $query->compile();
        $this->assertSame('delete', $compiled['type']);
        $this->assertEquals([], $compiled['filter']);
    }

    /**
     * Test cascade() toggles cascade deletion.
     *
     * @return void
     */
    public function testCascade(): void
    {
        $query = new DeleteQuery($this->connection, 'articles');
        $this->assertFalse($query->isCascadeEnabled());

        $this->assertSame($query, $query->cascade());
        $this->assertTrue($query->isCascadeEnabled());

        $query->cascade(false);
        $this->assertFalse($query->isCascadeEnabled());
    }

    /**
     * Test the compiled shape.
     *
     * @return void
     */
    public function testCompile(): void
    {
        $query = new DeleteQuery($this->connection, 'articles');
        $query->where(['author_id' => 1]);

        $compiled = $query->compile();
        $this->assertSame('delete', $compiled['type']);
        $this->assertSame('articles', $compiled['collection']);
        $this->assertEquals(['author_id' => 1], $compiled['filter']);
        $this->assertSame([], $compiled['options']);
    }

    /**
     * Test the query type constant.
     *
     * @return void
     */
    public function testType(): void
    {
        $query = new DeleteQuery($this->connection, 'articles');
        $this->assertSame('delete', $query->compile()['type']);
    }

    /**
     * Test delete() sets the target collection and returns $this.
     *
     * @return void
     */
    public function testDelete(): void
    {
        $query = new DeleteQuery($this->connection);
        $this->assertSame($query, $query->delete('posts'));
        $this->assertSame('posts', $query->getCollection());
        $this->assertSame('posts', $query->compile()['collection']);
    }

    /**
     * Test delete() with no table leaves the collection unchanged.
     *
     * @return void
     */
    public function testDeleteNoTable(): void
    {
        $query = new DeleteQuery($this->connection, 'articles');
        $this->assertSame($query, $query->delete());
        $this->assertSame('articles', $query->getCollection());
    }

    /**
     * Test from() sets the target collection.
     *
     * @return void
     */
    public function testFrom(): void
    {
        $query = new DeleteQuery($this->connection);
        $this->assertSame($query, $query->from('posts'));
        $this->assertSame('posts', $query->getCollection());
    }

    /**
     * Test execute() deletes matching documents.
     *
     * @return void
     */
    public function testExecute(): void
    {
        $collection = $this->connection->getCollection('delete_query_test');
        $collection->deleteMany([]);
        $collection->insertMany([
            ['author_id' => 1],
            ['author_id' => 1],
            ['author_id' => 2],
        ]);

        $query = new DeleteQuery($this->connection, 'delete_query_test');
        $query->where(['author_id' => 1]);

        $count = $query->execute();
        $this->assertSame(2, $count);

        $this->assertSame(1, $collection->countDocuments());
        $collection->deleteMany([]);
    }

    /**
     * Test execute() with an empty filter deletes everything.
     *
     * @return void
     */
    public function testExecuteNoWhere(): void
    {
        $collection = $this->connection->getCollection('delete_query_test');
        $collection->deleteMany([]);
        $collection->insertMany([
            ['author_id' => 1],
            ['author_id' => 2],
        ]);

        $query = new DeleteQuery($this->connection, 'delete_query_test');

        $count = $query->execute();
        $this->assertSame(2, $count);
        $this->assertSame(0, $collection->countDocuments());
        $collection->deleteMany([]);
    }

    /**
     * Test execute() with operator conditions deletes matches.
     *
     * @return void
     */
    public function testExecuteWithOperators(): void
    {
        $collection = $this->connection->getCollection('delete_query_test');
        $collection->deleteMany([]);
        $collection->insertMany([
            ['views' => 5],
            ['views' => 15],
            ['views' => 25],
        ]);

        $query = new DeleteQuery($this->connection, 'delete_query_test');
        $query->where(['views >' => 10]);

        $this->assertSame(2, $query->execute());
        $this->assertSame(1, $collection->countDocuments());
        $collection->deleteMany([]);
    }

    /**
     * Test execute() with no matches returns 0.
     *
     * @return void
     */
    public function testExecuteNoMatches(): void
    {
        $collection = $this->connection->getCollection('delete_query_test');
        $collection->deleteMany([]);

        $query = new DeleteQuery($this->connection, 'delete_query_test');
        $query->where(['missing' => 1]);

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

        $query = new DeleteQuery();
        $query->from('articles')->execute();
    }

    /**
     * Test getConnection/getCollection accessors.
     *
     * @return void
     */
    public function testAccessors(): void
    {
        $query = new DeleteQuery($this->connection, 'articles');
        $this->assertSame($this->connection, $query->getConnection());
        $this->assertSame('articles', $query->getCollection());
    }
}

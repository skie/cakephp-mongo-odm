<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database;

use Cake\Core\Exception\CakeException;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Database\Query\Query;
use Crustum\Mongo\Database\Query\QueryCompiler;
use Crustum\Mongo\Database\Query\SelectQuery;
use Traversable;

/**
 * Tests the base Query class.
 *
 * Adapted from cake50/tests/TestCase/Database/QueryTest.php for the Mongo
 * query lifecycle (connection, collection, compile, sql, execute).
 */
class QueryTest extends TestCase
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
     * Test the constructor binds the connection and collection.
     *
     * @return void
     */
    public function testConstructor(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $this->assertSame($this->connection, $query->getConnection());
        $this->assertSame('articles', $query->getCollection());
    }

    /**
     * Test that a query with no connection has a null connection.
     *
     * @return void
     */
    public function testNoConnection(): void
    {
        $query = new SelectQuery();
        $this->assertNull($query->getConnection());
    }

    /**
     * Test setConnection returns $this and changes the connection.
     *
     * @return void
     */
    public function testSetConnection(): void
    {
        $query = new SelectQuery();
        $this->assertSame($query, $query->setConnection($this->connection));
        $this->assertSame($this->connection, $query->getConnection());
    }

    /**
     * Test from() sets the collection and returns $this.
     *
     * @return void
     */
    public function testFrom(): void
    {
        $query = new SelectQuery();
        $this->assertSame($query, $query->from('articles'));
        $this->assertSame('articles', $query->getCollection());
    }

    /**
     * Test the builder is exposed and shared.
     *
     * @return void
     */
    public function testGetBuilder(): void
    {
        $query = new SelectQuery();
        $this->assertInstanceOf(QueryCompiler::class, $query->getBuilder());
        $this->assertSame($query->getBuilder(), $query->getBuilder());
    }

    /**
     * Test compile() adds the collection to the compiled shape.
     *
     * @return void
     */
    public function testCompile(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $compiled = $query->compile();

        $this->assertQueryType(Query::TYPE_SELECT, $compiled);
        $this->assertSame('articles', $compiled['collection'] ?? null);
    }

    /**
     * Test compile() reflects the configured collection.
     *
     * @return void
     */
    public function testCompileCollectionChanges(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->from('posts');

        $this->assertSame('posts', $query->compile()['collection']);
    }

    /**
     * Test sql() returns a JSON string of the compiled query.
     *
     * @return void
     */
    public function testSql(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->where(['author_id' => 1]);

        $sql = $query->sql();
        $this->assertIsString($sql);
        $this->assertStringContainsString('articles', $sql);
        $this->assertStringContainsString('author_id', $sql);
        $this->assertJson($sql);
    }

    /**
     * Test execute() throws when no connection is set.
     *
     * @return void
     */
    public function testExecuteWithoutConnectionThrows(): void
    {
        $this->expectException(CakeException::class);
        $this->expectExceptionMessage('Query has no connection set.');

        $query = new SelectQuery();
        $query->execute();
    }

    /**
     * Test execute() on a select returns a Mongo cursor.
     *
     * @return void
     */
    public function testExecuteSelect(): void
    {
        $collection = $this->connection->getCollection('query_test');
        $collection->insertMany([
            ['title' => 'One'],
            ['title' => 'Two'],
        ]);

        $query = new SelectQuery($this->connection, 'query_test');
        $result = $query->execute();

        $this->assertInstanceOf(Traversable::class, $result);
        $this->assertCount(2, iterator_to_array($result, false));

        $collection->deleteMany([]);
    }

    /**
     * Test the clone operator produces an independent query.
     *
     * @return void
     */
    public function testClone(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->where(['author_id' => 1]);

        $clone = clone $query;

        $this->assertNotSame($query, $clone);
        $this->assertSame('articles', $clone->getCollection());
        $this->assertEquals($query->compile(), $clone->compile());
    }

    /**
     * Test the clone operator deep-clones the compiler.
     *
     * @return void
     */
    public function testCloneDeepClonesBuilder(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->where(['author_id' => 1]);

        $clone = clone $query;
        $clone->where(['published' => true]);

        // Mutating the clone must not affect the original.
        $this->assertFilter(['author_id' => 1], $query->compile());
        $this->assertFilter(['author_id' => 1, 'published' => true], $clone->compile());

        // The compilers themselves are distinct instances.
        $this->assertNotSame($query->getBuilder(), $clone->getBuilder());
    }

    /**
     * Test the clone operator deep-clones projection state.
     *
     * @return void
     */
    public function testCloneDeepClonesProjection(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->select(['title']);

        $clone = clone $query;
        $clone->select(['body']);

        $this->assertOptions(['projection' => ['title' => 1]], $query->compile());
        $this->assertOptions(['projection' => ['title' => 1, 'body' => 1]], $clone->compile());
    }

    /**
     * Test the clone operator deep-clones the expression builder.
     *
     * @return void
     */
    public function testCloneDeepClonesExpressionBuilder(): void
    {
        $query = new SelectQuery($this->connection, 'articles');

        $clone = clone $query;

        $this->assertNotSame($query->getBuilder()->expr(), $clone->getBuilder()->expr());
    }

    /**
     * Test empty where values do not set filter conditions.
     *
     * @return void
     */
    public function testWhereEmptyValues(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $query->where('');

        $this->assertFilter([], $query->compile());

        $query = new SelectQuery($this->connection, 'articles');
        $query->where([]);

        $this->assertFilter([], $query->compile());
    }

    /**
     * Test __debugInfo exposes the compiled sql.
     *
     * @return void
     */
    public function testDebugInfo(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $result = $query->__debugInfo();

        $this->assertIsString($result['sql']);
        $this->assertJson($result['sql']);
    }

    /**
     * Test the query stringifies to its sql representation.
     *
     * @return void
     */
    public function testToString(): void
    {
        $query = new SelectQuery($this->connection, 'articles');
        $this->assertSame($query->sql(), (string)$query);
    }
}

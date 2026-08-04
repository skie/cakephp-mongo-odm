<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Query;

use Cake\Core\Exception\CakeException;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Database\Query\InsertQuery;
use InvalidArgumentException;
use MongoDB\BSON\ObjectId;

/**
 * Tests the InsertQuery class.
 *
 * Adapted from cake60/tests/TestCase/Database/Query/InsertQueryTest.php for the
 * Mongo insertOne/insertMany semantics.
 */
class InsertQueryTest extends TestCase
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
     * Test values() records a document and returns $this.
     *
     * @return void
     */
    public function testInsertValues(): void
    {
        $query = new InsertQuery($this->connection, 'articles');
        $this->assertSame($query, $query->values(['title' => 'A new article']));
        $this->assertEquals([['title' => 'A new article']], $query->getValues());
    }

    /**
     * Test values() with nested documents.
     *
     * @return void
     */
    public function testInsertNestedDocument(): void
    {
        $query = new InsertQuery($this->connection, 'articles');
        $query->values([
            'title' => 'One',
            'author' => ['name' => 'Jane', 'email' => 'jane@example.com'],
        ]);

        $this->assertEquals([[
            'title' => 'One',
            'author' => ['name' => 'Jane', 'email' => 'jane@example.com'],
        ]], $query->getValues());
    }

    /**
     * Test values() with an ObjectId value.
     *
     * @return void
     */
    public function testInsertObjectIdValue(): void
    {
        $id = (string)new ObjectId();
        $query = new InsertQuery($this->connection, 'articles');
        $query->values(['_id' => new ObjectId($id), 'title' => 'One']);

        $values = $query->getValues();
        $this->assertInstanceOf(ObjectId::class, $values[0]['_id']);
        $this->assertSame($id, (string)$values[0]['_id']);
    }

    /**
     * Test multiple values() calls append documents.
     *
     * @return void
     */
    public function testInsertMultipleValues(): void
    {
        $query = new InsertQuery($this->connection, 'articles');
        $query->values(['title' => 'One']);
        $query->values(['title' => 'Two']);

        $this->assertEquals([
            ['title' => 'One'],
            ['title' => 'Two'],
        ], $query->getValues());
    }

    /**
     * Test values() with overwrite replaces previous documents.
     *
     * @return void
     */
    public function testInsertOverwritesValues(): void
    {
        $query = new InsertQuery($this->connection, 'articles');
        $query->values(['title' => 'One']);
        $query->values(['title' => 'Two'], true);

        $this->assertEquals([['title' => 'Two']], $query->getValues());
    }

    /**
     * Test valuesMany() appends a list of documents.
     *
     * @return void
     */
    public function testInsertValuesMany(): void
    {
        $query = new InsertQuery($this->connection, 'articles');
        $query->valuesMany([
            ['title' => 'One'],
            ['title' => 'Two'],
        ]);

        $this->assertEquals([
            ['title' => 'One'],
            ['title' => 'Two'],
        ], $query->getValues());
    }

    /**
     * Test valuesMany() merges with existing documents.
     *
     * @return void
     */
    public function testInsertValuesManyMerges(): void
    {
        $query = new InsertQuery($this->connection, 'articles');
        $query->values(['title' => 'One']);
        $query->valuesMany([['title' => 'Two']]);

        $this->assertEquals([
            ['title' => 'One'],
            ['title' => 'Two'],
        ], $query->getValues());
    }

    /**
     * Test getValues() defaults to empty.
     *
     * @return void
     */
    public function testInsertNoValues(): void
    {
        $query = new InsertQuery($this->connection, 'articles');
        $this->assertSame([], $query->getValues());
    }

    /**
     * Test the compiled shape for a single document.
     *
     * @return void
     */
    public function testCompile(): void
    {
        $query = new InsertQuery($this->connection, 'articles');
        $query->values(['title' => 'A new article']);

        $compiled = $query->compile();
        $this->assertSame('insert', $compiled['type']);
        $this->assertSame('articles', $compiled['collection']);
        $this->assertEquals([['title' => 'A new article']], $compiled['documents']);
        $this->assertSame([], $compiled['options']);
    }

    /**
     * Test the compiled shape for multiple documents.
     *
     * @return void
     */
    public function testCompileMany(): void
    {
        $query = new InsertQuery($this->connection, 'articles');
        $query->valuesMany([
            ['title' => 'One'],
            ['title' => 'Two'],
        ]);

        $compiled = $query->compile();
        $this->assertEquals([
            ['title' => 'One'],
            ['title' => 'Two'],
        ], $compiled['documents']);
    }

    /**
     * Test compile() reflects an empty collection name.
     *
     * @return void
     */
    public function testCompileEmptyCollection(): void
    {
        $query = new InsertQuery($this->connection);
        $query->values(['title' => 'One']);

        $this->assertSame('', $query->compile()['collection']);
    }

    /**
     * Test from() sets the target collection.
     *
     * @return void
     */
    public function testFrom(): void
    {
        $query = new InsertQuery($this->connection);
        $this->assertSame($query, $query->from('posts'));
        $this->assertSame('posts', $query->getCollection());
    }

    /**
     * Test the query type constant.
     *
     * @return void
     */
    public function testType(): void
    {
        $query = new InsertQuery($this->connection, 'articles');
        $this->assertSame('insert', $query->compile()['type']);
    }

    /**
     * Test insert() records the column list and returns $this.
     *
     * @return void
     */
    public function testInsert(): void
    {
        $query = new InsertQuery($this->connection, 'articles');
        $this->assertSame($query, $query->insert(['title', 'author_id']));
    }

    /**
     * Test insert() with empty columns throws.
     *
     * @return void
     */
    public function testInsertEmptyColumnsThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('At least 1 column is required to perform an insert.');

        $query = new InsertQuery($this->connection, 'articles');
        $query->insert([]);
    }

    /**
     * Test insert() restricts values() documents to the declared columns.
     *
     * @return void
     */
    public function testInsertFiltersValues(): void
    {
        $query = new InsertQuery($this->connection, 'articles');
        $query->insert(['title'])->values(['title' => 'One', 'body' => 'Ignored']);

        $this->assertEquals([['title' => 'One']], $query->getValues());
    }

    /**
     * Test insert() restricts valuesMany() documents to the declared columns.
     *
     * @return void
     */
    public function testInsertFiltersValuesMany(): void
    {
        $query = new InsertQuery($this->connection, 'articles');
        $query->insert(['title'])->valuesMany([
            ['title' => 'One', 'body' => 'Ignored'],
            ['title' => 'Two'],
        ]);

        $this->assertEquals([
            ['title' => 'One'],
            ['title' => 'Two'],
        ], $query->getValues());
    }

    /**
     * Test into() sets the target collection.
     *
     * @return void
     */
    public function testInto(): void
    {
        $query = new InsertQuery($this->connection);
        $this->assertSame($query, $query->into('posts'));
        $this->assertSame('posts', $query->compile()['collection']);
    }

    /**
     * Test execute() inserts a single document and returns its id.
     *
     * @return void
     */
    public function testExecuteSingle(): void
    {
        $this->connection->getCollection('insert_query_test')->deleteMany([]);

        $query = new InsertQuery($this->connection, 'insert_query_test');
        $query->values(['title' => 'One']);

        $ids = $query->execute();
        $this->assertIsArray($ids);
        $this->assertCount(1, $ids);
        $this->assertIsString($ids[0]);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{24}$/', $ids[0]);

        $this->assertSame(1, $this->connection->getCollection('insert_query_test')->countDocuments());
        $this->connection->getCollection('insert_query_test')->deleteMany([]);
    }

    /**
     * Test execute() returns the id matching the stored document.
     *
     * @return void
     */
    public function testExecuteReturnsStoredId(): void
    {
        $this->connection->getCollection('insert_query_test')->deleteMany([]);

        $query = new InsertQuery($this->connection, 'insert_query_test');
        $query->values(['title' => 'One']);

        [$id] = $query->execute();
        $stored = $this->connection->getCollection('insert_query_test')->findOne(['title' => 'One']);

        $this->assertSame($id, (string)$stored['_id']);
        $this->connection->getCollection('insert_query_test')->deleteMany([]);
    }

    /**
     * Test execute() inserts many documents and returns their ids.
     *
     * @return void
     */
    public function testExecuteMany(): void
    {
        $this->connection->getCollection('insert_query_test')->deleteMany([]);

        $query = new InsertQuery($this->connection, 'insert_query_test');
        $query->valuesMany([
            ['title' => 'One'],
            ['title' => 'Two'],
        ]);

        $ids = $query->execute();
        $this->assertIsArray($ids);
        $this->assertCount(2, $ids);

        $this->assertSame(2, $this->connection->getCollection('insert_query_test')->countDocuments());
        $this->connection->getCollection('insert_query_test')->deleteMany([]);
    }

    /**
     * Test execute() preserves a provided ObjectId.
     *
     * @return void
     */
    public function testExecuteCustomObjectId(): void
    {
        $this->connection->getCollection('insert_query_test')->deleteMany([]);

        $id = (string)new ObjectId();
        $query = new InsertQuery($this->connection, 'insert_query_test');
        $query->values(['_id' => new ObjectId($id), 'title' => 'One']);

        [$insertedId] = $query->execute();
        $this->assertSame($id, $insertedId);

        $this->connection->getCollection('insert_query_test')->deleteMany([]);
    }

    /**
     * Test execute() with a nested document round-trips.
     *
     * @return void
     */
    public function testExecuteNestedDocument(): void
    {
        $this->connection->getCollection('insert_query_test')->deleteMany([]);

        $query = new InsertQuery($this->connection, 'insert_query_test');
        $query->values([
            'title' => 'One',
            'author' => ['name' => 'Jane'],
        ]);

        $query->execute();

        $stored = $this->connection->getCollection('insert_query_test')->findOne(['title' => 'One']);

        $this->assertSame('Jane', $stored['author']['name']);
        $this->connection->getCollection('insert_query_test')->deleteMany([]);
    }

    /**
     * Test execute() with a sparse document (missing fields).
     *
     * @return void
     */
    public function testExecuteSparseDocument(): void
    {
        $this->connection->getCollection('insert_query_test')->deleteMany([]);

        $query = new InsertQuery($this->connection, 'insert_query_test');
        $query->values(['title' => 'Only title']);

        $query->execute();

        $stored = $this->connection->getCollection('insert_query_test')->findOne(['title' => 'Only title']);

        $this->assertArrayNotHasKey('body', $stored);
        $this->connection->getCollection('insert_query_test')->deleteMany([]);
    }

    /**
     * Test execute() inserts an empty document.
     *
     * @return void
     */
    public function testExecuteEmptyDocument(): void
    {
        $this->connection->getCollection('insert_query_test')->deleteMany([]);

        $query = new InsertQuery($this->connection, 'insert_query_test');
        $query->values([]);

        $ids = $query->execute();
        $this->assertCount(1, $ids);
        $this->assertSame(1, $this->connection->getCollection('insert_query_test')->countDocuments());
        $this->connection->getCollection('insert_query_test')->deleteMany([]);
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

        $query = new InsertQuery();
        $query->from('articles')->values(['title' => 'One'])->execute();
    }

    /**
     * Test inserting into an existing collection keeps existing documents.
     *
     * @return void
     */
    public function testInsertAppendsToCollection(): void
    {
        $collection = $this->connection->getCollection('insert_query_test');
        $collection->deleteMany([]);
        $collection->insertOne(['title' => 'Existing']);

        $query = new InsertQuery($this->connection, 'insert_query_test');
        $query->values(['title' => 'New']);

        $query->execute();
        $this->assertSame(2, $collection->countDocuments());
        $collection->deleteMany([]);
    }

    /**
     * Test a document with an array value round-trips.
     *
     * @return void
     */
    public function testExecuteArrayValue(): void
    {
        $this->connection->getCollection('insert_query_test')->deleteMany([]);

        $query = new InsertQuery($this->connection, 'insert_query_test');
        $query->values(['tags' => ['a', 'b', 'c']]);

        $query->execute();

        $stored = $this->connection->getCollection('insert_query_test')->findOne();

        $this->assertEquals(['a', 'b', 'c'], iterator_to_array($stored['tags']));
        $this->connection->getCollection('insert_query_test')->deleteMany([]);
    }

    /**
     * Test a document with numeric values round-trips.
     *
     * @return void
     */
    public function testExecuteNumericValues(): void
    {
        $this->connection->getCollection('insert_query_test')->deleteMany([]);

        $query = new InsertQuery($this->connection, 'insert_query_test');
        $query->values(['count' => 42, 'ratio' => 1.5, 'active' => true]);

        $query->execute();

        $stored = $this->connection->getCollection('insert_query_test')->findOne();

        $this->assertSame(42, $stored['count']);
        $this->assertSame(1.5, $stored['ratio']);
        $this->assertTrue($stored['active']);
        $this->connection->getCollection('insert_query_test')->deleteMany([]);
    }

    /**
     * Test getConnection/getCollection accessors.
     *
     * @return void
     */
    public function testAccessors(): void
    {
        $query = new InsertQuery($this->connection, 'articles');
        $this->assertSame($this->connection, $query->getConnection());
        $this->assertSame('articles', $query->getCollection());
    }

    /**
     * Test setConnection replaces the connection.
     *
     * @return void
     */
    public function testSetConnection(): void
    {
        $query = new InsertQuery();
        $this->assertSame($query, $query->setConnection($this->connection));
        $this->assertSame($this->connection, $query->getConnection());
    }
}

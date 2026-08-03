<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Schema;

use Cake\Datasource\ConnectionManager;
use Cake\Datasource\SchemaInterface;
use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Database\Schema\SchemaCollection;

/**
 * Tests the SchemaCollection class.
 *
 * Adapted from cake50/tests/TestCase/Database/Schema/CollectionTest.php for
 * Mongo collection introspection.
 */
class CollectionTest extends TestCase
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
     * Test listCollections returns the existing collections.
     *
     * @return void
     */
    public function testListCollections(): void
    {
        $this->connection->getCollection('schema_test_one')->insertOne(['name' => 'One']);
        $this->connection->getCollection('schema_test_two')->insertOne(['name' => 'Two']);

        $schema = new SchemaCollection($this->connection);
        $names = $schema->listCollections();

        $this->assertContains('schema_test_one', $names);
        $this->assertContains('schema_test_two', $names);

        $this->connection->getDatabase()->dropCollection('schema_test_one');
        $this->connection->getDatabase()->dropCollection('schema_test_two');
    }

    /**
     * Test listTables aliases listCollections.
     *
     * @return void
     */
    public function testListTables(): void
    {
        $this->connection->getCollection('schema_test_tables')->insertOne(['name' => 'One']);

        $schema = new SchemaCollection($this->connection);
        $this->assertSame($schema->listCollections(), $schema->listTables());

        $this->connection->getDatabase()->dropCollection('schema_test_tables');
    }

    /**
     * Test describe builds a CollectionSchema for the given name.
     *
     * @return void
     */
    public function testDescribe(): void
    {
        $schema = new SchemaCollection($this->connection);
        $described = $schema->describe('schema_test_describe');

        $this->assertInstanceOf(SchemaInterface::class, $described);
        $this->assertSame('schema_test_describe', $described->name());
    }

    /**
     * Test clearCache is a no-op.
     *
     * @return void
     */
    public function testClearCache(): void
    {
        $schema = new SchemaCollection($this->connection);
        $schema->clearCache();
        $this->addToAssertionCount(1);
    }
}

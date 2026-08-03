<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database;

use Cake\Cache\Cache;
use Cake\Cache\Engine\FileEngine;
use Cake\Core\Exception\CakeException;
use Cake\Datasource\ConnectionInterface;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Database\Driver\MongoDriver;
use Crustum\Mongo\Database\Query\InsertQuery;
use Crustum\Mongo\Database\Query\SelectQuery;
use Crustum\Mongo\Database\Schema\CachedSchemaCollection;
use Crustum\Mongo\Database\Schema\SchemaCollection;
use Crustum\Mongo\Datasource\SchemaCollectionInterface;
use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Database;
use Psr\SimpleCache\CacheInterface;
use Traversable;

/**
 * Tests the Connection class.
 *
 * Adapted from cake50/tests/TestCase/Database/ConnectionTest.php for the Mongo
 * driver/run/schema-cache lifecycle.
 */
class ConnectionTest extends TestCase
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
     * Test the constructor wires the driver.
     *
     * @return void
     */
    public function testConstructor(): void
    {
        $connection = new Connection([
            'name' => 'my_conn',
            'driver' => MongoDriver::class,
            'host' => '127.0.0.1',
            'database' => 'test_db',
        ]);

        $this->assertSame('my_conn', $connection->configName());
        $this->assertInstanceOf(MongoDriver::class, $connection->getDriver());
    }

    /**
     * Test the constructor throws for a missing database key.
     *
     * @return void
     */
    public function testConstructorMissingDatabase(): void
    {
        $this->expectException(CakeException::class);
        $this->expectExceptionMessage('Mongo driver requires a "database" key.');

        new Connection(['name' => 'broken']);
    }

    /**
     * Test the connection implements ConnectionInterface.
     *
     * @return void
     */
    public function testInterface(): void
    {
        $this->assertInstanceOf(ConnectionInterface::class, $this->connection);
    }

    /**
     * Test configName and config.
     *
     * @return void
     */
    public function testConfig(): void
    {
        $this->assertSame('test_mongo', $this->connection->configName());
        $config = $this->connection->config();
        $this->assertSame('test_mongo_db', $config['database']);
    }

    /**
     * Test getClient / getDatabase / getCollection accessors.
     *
     * @return void
     */
    public function testAccessors(): void
    {
        $this->assertInstanceOf(Client::class, $this->connection->getClient());
        $this->assertInstanceOf(Database::class, $this->connection->getDatabase());
        $this->assertInstanceOf(Collection::class, $this->connection->getCollection('articles'));
    }

    /**
     * Test getSchemaCollection returns a SchemaCollection by default.
     *
     * @return void
     */
    public function testGetSchemaCollection(): void
    {
        $schema = $this->connection->getSchemaCollection();
        $this->assertInstanceOf(SchemaCollectionInterface::class, $schema);
        $this->assertSame($schema, $this->connection->getSchemaCollection());
    }

    /**
     * Test cacheMetadata(false) keeps a plain SchemaCollection.
     *
     * @return void
     */
    public function testCacheMetadataDisabled(): void
    {
        $this->connection->cacheMetadata(false);
        $schema = $this->connection->getSchemaCollection();
        $this->assertInstanceOf(SchemaCollection::class, $schema);
        $this->assertNotInstanceOf(CachedSchemaCollection::class, $schema);
    }

    /**
     * Test cacheMetadata() wraps the collection in the cached decorator.
     *
     * @return void
     */
    public function testCacheMetadataEnabled(): void
    {
        if (!Cache::getConfig('_mongo_test_cache')) {
            Cache::setConfig('_mongo_test_cache', [
                'className' => FileEngine::class,
                'path' => TMP . 'cache/models/',
                'prefix' => 'mongo_test_cache_',
            ]);
        }

        $this->connection->cacheMetadata('_mongo_test_cache');
        $schema = $this->connection->getSchemaCollection();
        $this->assertInstanceOf(CachedSchemaCollection::class, $schema);
    }

    /**
     * Test setSchemaCollection replaces the instance.
     *
     * @return void
     */
    public function testSetSchemaCollection(): void
    {
        $this->connection->cacheMetadata(false);
        $schema = new SchemaCollection($this->connection);
        $this->assertSame($this->connection, $this->connection->setSchemaCollection($schema));
        $this->assertSame($schema, $this->connection->getSchemaCollection());
    }

    /**
     * Test run() dispatches a find query.
     *
     * @return void
     */
    public function testRunFind(): void
    {
        $collection = $this->connection->getCollection('connection_test');
        $collection->deleteMany([]);
        $collection->insertOne(['title' => 'One']);

        $query = new SelectQuery($this->connection, 'connection_test');
        $result = $this->connection->run($query);

        $this->assertInstanceOf(Traversable::class, $result);
        $this->assertCount(1, iterator_to_array($result, false));
        $collection->deleteMany([]);
    }

    /**
     * Test run() dispatches an insert query.
     *
     * @return void
     */
    public function testRunInsert(): void
    {
        $collection = $this->connection->getCollection('connection_test');
        $collection->deleteMany([]);

        $query = new InsertQuery($this->connection, 'connection_test');
        $query->values(['title' => 'One']);

        $ids = $this->connection->run($query);
        $this->assertIsArray($ids);
        $this->assertCount(1, $ids);
        $collection->deleteMany([]);
    }

    /**
     * Test setCacher/getCacher.
     *
     * @return void
     */
    public function testCacher(): void
    {
        $this->connection->setCacher(Cache::pool('_cake_model_'));
        $this->assertInstanceOf(CacheInterface::class, $this->connection->getCacher());
    }

    /**
     * Test getCacher lazily builds a pool from cacheMetadata config.
     *
     * @return void
     */
    public function testGetCacherLazy(): void
    {
        if (!Cache::getConfig('_mongo_test_cache')) {
            Cache::setConfig('_mongo_test_cache', [
                'className' => FileEngine::class,
                'path' => TMP . 'cache/models/',
                'prefix' => 'mongo_test_cache_',
            ]);
        }

        $connection = new Connection([
            'name' => 'cacher_conn',
            'driver' => MongoDriver::class,
            'host' => '127.0.0.1',
            'database' => 'test_mongo_db',
            'cacheMetadata' => '_mongo_test_cache',
        ]);

        $this->assertInstanceOf(CacheInterface::class, $connection->getCacher());
    }
}

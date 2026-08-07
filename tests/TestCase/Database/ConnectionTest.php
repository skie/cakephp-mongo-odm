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
use Crustum\Mongo\Test\TestCase\Database\Log\MemoryLogger;
use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Database;
use MongoDB\Driver\Session;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;
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

    /**
     * Test the log config wires a PSR-3 logger onto the driver.
     *
     * Query logging lives on the driver (matching `Cake\Database\Driver`); the
     * connection only forwards the `log` config when constructing the driver.
     *
     * @return void
     */
    public function testLogConfigWiresDriverLogger(): void
    {
        $logger = new MemoryLogger();
        $connection = new Connection([
            'name' => 'logger_conn',
            'driver' => MongoDriver::class,
            'host' => '127.0.0.1',
            'database' => 'test_mongo_db',
            'log' => $logger,
        ]);

        $driver = $connection->getDriver();
        $this->assertInstanceOf(MongoDriver::class, $driver);
        $this->assertSame($logger, $driver->getLogger());
        $this->assertTrue($driver->isQueryLoggingEnabled());
    }

    /**
     * Skip when the server cannot run real multi-document transactions.
     *
     * @return void
     */
    protected function requireReplicaSet(): void
    {
        if (!$this->connection->supportsTransactions()) {
            $this->markTestSkipped('Server does not support transactions (needs a replica set or mongos).');
        }
    }

    /**
     * Test begin/commit round-trips writes.
     *
     * @return void
     */
    public function testSimpleTransactions(): void
    {
        $this->requireReplicaSet();

        $collection = $this->connection->getCollection('tx_test');
        $collection->deleteMany([]);

        $this->assertFalse($this->connection->inTransaction());
        $this->connection->begin();
        $this->assertTrue($this->connection->inTransaction());
        $this->assertInstanceOf(Session::class, $this->connection->getSession());

        $query = new InsertQuery($this->connection, 'tx_test');
        $query->values(['title' => 'One']);

        $this->connection->run($query);

        $this->assertTrue($this->connection->commit());

        $this->assertFalse($this->connection->inTransaction());
        $this->assertNull($this->connection->getSession());
        $this->assertSame(1, $collection->countDocuments());
        $collection->deleteMany([]);
    }

    /**
     * Test rollback discards writes.
     *
     * @return void
     */
    public function testRollback(): void
    {
        $this->requireReplicaSet();

        $collection = $this->connection->getCollection('tx_test');
        $collection->deleteMany([]);

        $this->connection->begin();

        $query = new InsertQuery($this->connection, 'tx_test');
        $query->values(['title' => 'One']);

        $this->connection->run($query);

        $this->assertTrue($this->connection->rollback());

        $this->assertFalse($this->connection->inTransaction());
        $this->assertSame(0, $collection->countDocuments());
        $collection->deleteMany([]);
    }

    /**
     * Test commit without a transaction returns false.
     *
     * @return void
     */
    public function testCommitWithoutTransaction(): void
    {
        $this->assertFalse($this->connection->commit());
        $this->assertFalse($this->connection->rollback());
    }

    /**
     * Test nested begin/commit emulates savepoints.
     *
     * @return void
     */
    public function testNestedTransaction(): void
    {
        $this->connection->begin();
        $this->connection->begin();
        $this->assertTrue($this->connection->inTransaction());

        $this->assertTrue($this->connection->commit());
        $this->assertTrue($this->connection->inTransaction());

        $this->assertTrue($this->connection->commit());
        $this->assertFalse($this->connection->inTransaction());
    }

    /**
     * Test transactional() commits on success.
     *
     * @return void
     */
    public function testTransactionalSuccess(): void
    {
        $this->requireReplicaSet();

        $collection = $this->connection->getCollection('tx_test');
        $collection->deleteMany([]);

        $result = $this->connection->transactional(function (Connection $connection) {
            $query = new InsertQuery($connection, 'tx_test');
            $query->values(['title' => 'One']);

            return $connection->run($query);
        });

        $this->assertIsArray($result);
        $this->assertSame(1, $collection->countDocuments());
        $collection->deleteMany([]);
    }

    /**
     * Test transactional() returns false without committing on false result.
     *
     * @return void
     */
    public function testTransactionalFalseResult(): void
    {
        $collection = $this->connection->getCollection('tx_test');
        $collection->deleteMany([]);

        $result = $this->connection->transactional(fn(): false => false);

        $this->assertFalse($result);
        $this->assertSame(0, $collection->countDocuments());
        $collection->deleteMany([]);
    }

    /**
     * Test transactional() rolls back and rethrows on exception.
     *
     * @return void
     */
    public function testTransactionalWithException(): void
    {
        $this->requireReplicaSet();

        $collection = $this->connection->getCollection('tx_test');
        $collection->deleteMany([]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');

        try {
            $this->connection->transactional(function (Connection $connection): void {
                $query = new InsertQuery($connection, 'tx_test');
                $query->values(['title' => 'One']);

                $connection->run($query);

                throw new RuntimeException('boom');
            });
        } finally {
            $this->assertFalse($this->connection->inTransaction());
            $this->assertSame(0, $collection->countDocuments());
            $collection->deleteMany([]);
        }
    }

    /**
     * Test afterCommit() runs immediately outside a transaction.
     *
     * @return void
     */
    public function testAfterCommitExecutesImmediatelyOutsideTransaction(): void
    {
        $executed = [];
        $this->connection->afterCommit(function () use (&$executed): void {
            $executed[] = 'called';
        });

        $this->assertSame(['called'], $executed);
    }

    /**
     * Test afterCommit() fires on outer commit.
     *
     * @return void
     */
    public function testAfterCommitFiresOnCommit(): void
    {
        $executed = [];
        $this->connection->begin();
        $this->connection->afterCommit(function () use (&$executed): void {
            $executed[] = 'called';
        });

        $this->assertSame([], $executed);
        $this->assertTrue($this->connection->commit());

        $this->assertSame(['called'], $executed);
    }

    /**
     * Test afterCommit() callbacks are discarded on rollback.
     *
     * @return void
     */
    public function testAfterCommitDiscardedOnRollback(): void
    {
        $executed = [];
        $this->connection->begin();
        $this->connection->afterCommit(function () use (&$executed): void {
            $executed[] = 'called';
        });

        $this->assertTrue($this->connection->rollback());
        $this->assertSame([], $executed);
    }

    /**
     * Test afterCommit() callbacks fire in registration order.
     *
     * @return void
     */
    public function testAfterCommitCallbacksFireInRegistrationOrder(): void
    {
        $executed = [];
        $this->connection->begin();
        $this->connection->afterCommit(function () use (&$executed): void {
            $executed[] = 'first';
        });
        $this->connection->afterCommit(function () use (&$executed): void {
            $executed[] = 'second';
        });

        $this->connection->commit();

        $this->assertSame(['first', 'second'], $executed);
    }

    /**
     * Test afterCommit() callbacks fire once for nested transactions.
     *
     * @return void
     */
    public function testAfterCommitFiresOnceForNestedTransactions(): void
    {
        $executed = [];
        $this->connection->begin();
        $this->connection->afterCommit(function () use (&$executed): void {
            $executed[] = 'called';
        });

        $this->connection->begin();
        $this->connection->commit();
        $this->assertSame([], $executed);

        $this->connection->commit();
        $this->assertSame(['called'], $executed);
    }

    /**
     * Test a callback that throws stops the remaining callbacks.
     *
     * @return void
     */
    public function testAfterCommitCallbackExceptionStopsExecution(): void
    {
        $executed = [];
        $this->connection->begin();
        $this->connection->afterCommit(function () use (&$executed): void {
            $executed[] = 'first';
        });
        $this->connection->afterCommit(function (): void {
            throw new RuntimeException('boom');
        });
        $this->connection->afterCommit(function () use (&$executed): void {
            $executed[] = 'third';
        });

        $this->expectException(RuntimeException::class);
        $this->connection->commit();
    }
}

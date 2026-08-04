<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Driver;

use Cake\Core\Exception\CakeException;
use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Driver\MongoDriver;
use Crustum\Mongo\Database\Enum\DriverFeature;
use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Database;
use MongoDB\Driver\Manager;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Tests the MongoDriver class.
 *
 * Adapted from cake50/tests/TestCase/Database/DriverTest.php for the Mongo
 * connection-string/config/capability surface.
 */
class MongoDriverTest extends TestCase
{
    /**
     * Test the constructor throws for a missing database key.
     *
     * @return void
     */
    public function testConstructorException(): void
    {
        $this->expectException(CakeException::class);
        $this->expectExceptionMessage('Mongo driver requires a "database" key.');

        new MongoDriver(['host' => '127.0.0.1']);
    }

    /**
     * Test the constructor accepts a valid config.
     *
     * @return void
     */
    public function testConstructor(): void
    {
        $driver = new MongoDriver(['database' => 'test_db']);
        $this->assertSame(['database' => 'test_db'], $driver->config());
    }

    /**
     * Test the driver builds a DSN from host/port.
     *
     * @return void
     */
    public function testBuildDsnFromHostPort(): void
    {
        $driver = new MongoDriver([
            'host' => '127.0.0.1',
            'port' => 27018,
            'database' => 'test_db',
        ]);

        $dsn = $this->invokeBuildDsn($driver);
        $this->assertSame('mongodb://127.0.0.1:27018', $dsn);
    }

    /**
     * Test the driver builds a DSN with credentials.
     *
     * @return void
     */
    public function testBuildDsnWithCredentials(): void
    {
        $driver = new MongoDriver([
            'host' => 'localhost',
            'port' => 27017,
            'username' => 'user',
            'password' => 'pass@word',
            'database' => 'test_db',
        ]);

        $dsn = $this->invokeBuildDsn($driver);
        $this->assertSame('mongodb://user:pass%40word@localhost:27017', $dsn);
    }

    /**
     * Test the driver uses an explicit url when given.
     *
     * @return void
     */
    public function testBuildDsnWithUrl(): void
    {
        $driver = new MongoDriver([
            'url' => 'mongodb://custom:27017',
            'host' => 'ignored',
            'database' => 'test_db',
        ]);

        $dsn = $this->invokeBuildDsn($driver);
        $this->assertSame('mongodb://custom:27017', $dsn);
    }

    /**
     * Test supports() reports the documented capabilities.
     *
     * @return void
     */
    public function testSupports(): void
    {
        $driver = new MongoDriver(['database' => 'test_db']);

        $this->assertTrue($driver->supports(DriverFeature::Aggregation));
        $this->assertTrue($driver->supports(DriverFeature::Sessions));
        $this->assertTrue($driver->supports(DriverFeature::Transactions));
        $this->assertTrue($driver->supports(DriverFeature::ChangeStreams));
        $this->assertFalse($driver->supports(DriverFeature::SearchIndex));
        $this->assertFalse($driver->supports(DriverFeature::VectorSearch));
    }

    /**
     * Test connect() creates a client and getDatabase() selects it.
     *
     * @return void
     */
    public function testConnectAndGetDatabase(): void
    {
        $driver = new MongoDriver([
            'host' => '127.0.0.1',
            'port' => 27017,
            'database' => 'test_mongo_db',
        ]);

        $this->assertInstanceOf(Client::class, $driver->getClient());
        $this->assertInstanceOf(Database::class, $driver->getDatabase());
        $this->assertInstanceOf(Manager::class, $driver->getManager());
    }

    /**
     * Test getCollection returns a Mongo collection.
     *
     * @return void
     */
    public function testGetCollection(): void
    {
        $driver = new MongoDriver([
            'host' => '127.0.0.1',
            'port' => 27017,
            'database' => 'test_mongo_db',
        ]);

        $this->assertInstanceOf(Collection::class, $driver->getCollection('articles'));
    }

    /**
     * Test disconnect resets client and database.
     *
     * @return void
     */
    public function testDisconnect(): void
    {
        $driver = new MongoDriver([
            'host' => '127.0.0.1',
            'port' => 27017,
            'database' => 'test_mongo_db',
        ]);

        $driver->getClient();
        $driver->disconnect();

        $reflection = new ReflectionProperty(MongoDriver::class, 'client');
        $this->assertNull($reflection->getValue($driver));
    }

    /**
     * Test isConnected reports the client state.
     *
     * @return void
     */
    public function testIsConnected(): void
    {
        $driver = new MongoDriver([
            'host' => '127.0.0.1',
            'port' => 27017,
            'database' => 'test_mongo_db',
        ]);

        $this->assertFalse($driver->isConnected());

        $driver->getClient();
        $this->assertTrue($driver->isConnected());

        $driver->disconnect();
        $this->assertFalse($driver->isConnected());
    }

    /**
     * Test the destructor disconnects the driver.
     *
     * @return void
     */
    public function testDestructor(): void
    {
        $driver = new MongoDriver([
            'host' => '127.0.0.1',
            'port' => 27017,
            'database' => 'test_mongo_db',
        ]);

        $driver->getClient();
        $this->assertTrue($driver->isConnected());

        $driver->__destruct();
        $this->assertFalse($driver->isConnected());
    }

    /**
     * Invokes the protected buildDsn() method.
     *
     * @param \Crustum\Mongo\Database\Driver\MongoDriver $driver The driver.
     * @return string
     */
    protected function invokeBuildDsn(MongoDriver $driver): string
    {
        $method = new ReflectionMethod(MongoDriver::class, 'buildDsn');

        return $method->invoke($driver);
    }
}

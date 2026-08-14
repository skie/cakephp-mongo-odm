<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Migration;

use Cake\Console\ConsoleIo;
use Cake\Console\TestSuite\StubConsoleInput;
use Cake\Console\TestSuite\StubConsoleOutput;
use Crustum\Mongo\Migration\Adapter\CakeMongoAdapter;
use Crustum\Mongo\Migration\Config\Config;
use Crustum\Mongo\Migration\ManagerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests the ManagerFactory (ported from cakephp/migrations, DSN parsing
 * dropped — Mongo connections are resolved by name only).
 */
#[CoversClass(ManagerFactory::class)]
class ManagerFactoryTest extends TestCase
{
    /**
     * Builds a ConsoleIo with stubbed output.
     *
     * @return \Cake\Console\ConsoleIo
     */
    protected function buildIo(): ConsoleIo
    {
        $out = new StubConsoleOutput();
        $out->setOutputAs(StubConsoleOutput::PLAIN);

        return new ConsoleIo($out, $out, new StubConsoleInput([]));
    }

    /**
     * Test the connection name flows through createManager into the config.
     *
     * @return void
     */
    public function testConnection(): void
    {
        $factory = new ManagerFactory(['connection' => 'test_mongo']);
        $result = $factory->createManager($this->buildIo());

        $this->assertInstanceOf(Config::class, $result->getConfig());
        $this->assertSame('test_mongo', $result->getConfig()->getConnection());
    }

    /**
     * Test createConfig defaults the connection to `mongo`.
     *
     * @return void
     */
    public function testCreateConfigDefaultConnection(): void
    {
        $factory = new ManagerFactory([]);
        $config = $factory->createConfig();

        $this->assertSame('mongo', $config->getConnection());
    }

    /**
     * Test createConfig records the plugin and source.
     *
     * @return void
     */
    public function testCreateConfigPlugin(): void
    {
        $factory = new ManagerFactory([
            'connection' => 'test_mongo',
            'plugin' => 'TestPlugin',
            'source' => 'MongoMigrations',
        ]);

        $config = $factory->createConfig();

        $this->assertSame('TestPlugin', $config['plugin']);
        $this->assertSame('MongoMigrations', $config['source']);
        $this->assertSame('_migrations', $config['environment']['migration_table']);
    }

    /**
     * Test createAdapter resolves a Mongo connection.
     *
     * @return void
     */
    public function testCreateAdapter(): void
    {
        $factory = new ManagerFactory([]);
        $adapter = $factory->createAdapter(['connection' => 'test_mongo']);

        $this->assertInstanceOf(CakeMongoAdapter::class, $adapter);
    }

    /**
     * Test createAdapter throws when no connection is defined.
     *
     * @return void
     */
    public function testCreateAdapterWithoutConnection(): void
    {
        $factory = new ManagerFactory([]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No connection defined');

        $factory->createAdapter([]);
    }

    /**
     * Test createAdapter throws when the connection is not a Crustum Mongo connection.
     *
     * @return void
     */
    public function testCreateAdapterWithNonMongoConnection(): void
    {
        $factory = new ManagerFactory([]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Connection `test` is not a Crustum\Mongo\Database\Connection instance.');

        $factory->createAdapter(['connection' => 'test']);
    }
}

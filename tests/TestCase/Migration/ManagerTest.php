<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Migration;

use Cake\Console\ConsoleIo;
use Cake\Console\TestSuite\StubConsoleInput;
use Cake\Console\TestSuite\StubConsoleOutput;
use Cake\Datasource\ConnectionManager;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Database\Schema\SchemaManager;
use Crustum\Mongo\Migration\Config\Config;
use Crustum\Mongo\Migration\Manager;
use DateTime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests the migration Manager against a real Mongo database.
 *
 * Adapted from cakephp/migrations `ManagerTest` вЂ” the SQL-driven fixtures are
 * replaced by Mongo migrations under `tests/test_app/TestApp/config/ManagerMigrations`.
 */
#[CoversClass(Manager::class)]
class ManagerTest extends TestCase
{
    /**
     * @var \Crustum\Mongo\Migration\Config\Config
     */
    protected Config $config;

    /**
     * @var \Cake\Console\ConsoleIo
     */
    protected ConsoleIo $io;

    /**
     * @var \Cake\Console\TestSuite\StubConsoleOutput
     */
    protected StubConsoleOutput $out;

    /**
     * @var \Cake\Console\TestSuite\StubConsoleInput
     */
    protected StubConsoleInput $in;

    /**
     * @var \Crustum\Mongo\Migration\Manager|null
     */
    private ?Manager $manager = null;

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
        $this->config = new Config($this->getConfigArray());

        $this->out = new StubConsoleOutput();
        $this->out->setOutputAs(StubConsoleOutput::PLAIN);

        $this->in = new StubConsoleInput([]);

        $this->io = new ConsoleIo($this->out, $this->out, $this->in);
        $this->manager = new Manager($this->config, $this->io);
        $this->connection = ConnectionManager::get('test_mongo');

        $this->cleanup();
    }

    /**
     * Tear down after each test.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->cleanup();
        $this->manager = null;
        parent::tearDown();
    }

    /**
     * Drops the products collection and clears the migration journal.
     *
     * @return void
     */
    protected function cleanup(): void
    {
        if ($this->connection === null) {
            return;
        }

        $collections = $this->connection->getSchemaCollection()->listCollections();
        if (in_array('products', $collections, true)) {
            $this->connection->getDatabase()->dropCollection('products');
        }
        $this->connection->getCollection('_migrations')->deleteMany([]);
    }

    /**
     * Returns the joined plain output of the stub console.
     *
     * @return string
     */
    protected function getOutput(): string
    {
        $lines = $this->out->messages();
        $lines = array_map(strip_tags(...), $lines);

        return implode("\n", $lines);
    }

    /**
     * Returns a sample configuration array pointing at the test migrations.
     *
     * @return array<string, mixed>
     */
    public static function getConfigArray(): array
    {
        return [
            'paths' => [
                'migrations' => ROOT . '/tests/test_app/TestApp/config/ManagerMigrations',
                'seeds' => ROOT . '/tests/test_app/TestApp/config/ManagerSeeds',
            ],
            'environment' => [
                'connection' => 'test_mongo',
                'adapter' => 'mongo',
                'migration_table' => '_migrations',
            ],
        ];
    }

    /**
     * Test getMigrations loads and orders the fixture migrations.
     *
     * @return void
     */
    public function testGetMigrations(): void
    {
        $migrations = $this->manager->getMigrations();

        $this->assertSame([20260811000000, 20260812000000], array_keys($migrations));
    }

    /**
     * Test printStatus reports every migration as down initially.
     *
     * @return void
     */
    public function testPrintStatusDown(): void
    {
        $status = $this->manager->printStatus();

        $this->assertCount(2, $status);
        foreach ($status as $entry) {
            $this->assertSame('down', $entry['status']);
        }
    }

    /**
     * Test migrate applies all migrations and creates the collection.
     *
     * @return void
     */
    public function testMigrate(): void
    {
        $this->manager->migrate();

        $this->assertSame([20260811000000, 20260812000000], $this->manager->getEnvironment()->getVersions());

        $manager = new SchemaManager($this->connection);
        $this->assertContains('products', $manager->listCollections());
        $indexes = $manager->listIndexes('products');
        $this->assertArrayHasKey('tags_index', $indexes);

        $status = $this->manager->printStatus();
        $this->assertSame(['up', 'up'], array_column($status, 'status'));
    }

    /**
     * Test migrate to a target version stops at that migration.
     *
     * @return void
     */
    public function testMigrateToVersion(): void
    {
        $this->manager->migrate(20260811000000);

        $this->assertSame([20260811000000], $this->manager->getEnvironment()->getVersions());

        $status = $this->manager->printStatus();
        $this->assertSame('up', $status[0]['status']);
        $this->assertSame('down', $status[1]['status']);
    }

    /**
     * Test migrateToDateTime stops at the latest version before the date.
     *
     * @return void
     */
    public function testMigrateToDateTime(): void
    {
        $this->manager->migrateToDateTime(new DateTime('2026-08-11 12:00:00'));

        $this->assertSame([20260811000000], $this->manager->getEnvironment()->getVersions());
    }

    /**
     * Test rollback reverts the last batch.
     *
     * @return void
     */
    public function testRollback(): void
    {
        $this->manager->migrate();
        $this->manager->rollback();

        $this->assertSame([20260811000000], $this->manager->getEnvironment()->getVersions());

        $status = $this->manager->printStatus();
        $this->assertSame('up', $status[0]['status']);
        $this->assertSame('down', $status[1]['status']);
    }

    /**
     * Test rollbackByCount reverts exactly N migrations.
     *
     * @return void
     */
    public function testRollbackByCount(): void
    {
        $this->manager->migrate();
        $this->manager->rollbackByCount(1);

        $this->assertSame([20260811000000], $this->manager->getEnvironment()->getVersions());
    }

    /**
     * Test rollbackToDateTime reverts to the state at that date.
     *
     * @return void
     */
    public function testRollbackToDateTime(): void
    {
        $this->manager->migrate();
        $this->manager->rollbackToDateTime(new DateTime('2026-08-11 12:00:00'));

        $this->assertSame([20260811000000], $this->manager->getEnvironment()->getVersions());
    }

    /**
     * Test markMigrated records a version without running it.
     *
     * @return void
     */
    public function testMarkMigrated(): void
    {
        $path = ROOT . '/tests/test_app/TestApp/config/ManagerMigrations';

        $this->manager->markMigrated(20260811000000, $path);

        $this->assertSame([20260811000000], $this->manager->getEnvironment()->getVersions());
        $this->assertFalse(
            in_array('products', $this->connection->getSchemaCollection()->listCollections(), true),
        );
    }

    /**
     * Test a breakpoint stops further rollbacks.
     *
     * @return void
     */
    public function testBreakpointPreventsRollback(): void
    {
        $this->manager->migrate();
        $this->manager->setBreakpoint(20260812000000);

        $this->manager->rollback();

        $this->assertSame([20260811000000, 20260812000000], $this->manager->getEnvironment()->getVersions());
        $this->assertStringContainsString('Breakpoint reached', $this->getOutput());
    }

    /**
     * Test rollback with force ignores the breakpoint.
     *
     * @return void
     */
    public function testRollbackForceBypassesBreakpoint(): void
    {
        $this->manager->migrate();
        $this->manager->setBreakpoint(20260812000000);

        $this->manager->rollback(null, true);

        $this->assertSame([20260811000000], $this->manager->getEnvironment()->getVersions());
    }

    /**
     * Test removeBreakpoints clears all breakpoints.
     *
     * @return void
     */
    public function testRemoveBreakpoints(): void
    {
        $this->manager->migrate();
        $this->manager->setBreakpoint(20260811000000);
        $this->manager->setBreakpoint(20260812000000);

        $this->manager->removeBreakpoints();

        foreach ($this->manager->getEnvironment()->getVersionLog() as $entry) {
            $this->assertSame(0, $entry['breakpoint']);
        }
    }

    /**
     * Test isMigrated reflects the journal.
     *
     * @return void
     */
    public function testIsMigrated(): void
    {
        $this->assertFalse($this->manager->isMigrated(20260811000000));

        $this->manager->migrate();

        $this->assertTrue($this->manager->isMigrated(20260811000000));
        $this->assertTrue($this->manager->isMigrated(20260812000000));
    }
}


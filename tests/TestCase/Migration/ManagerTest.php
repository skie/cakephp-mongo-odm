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
use Crustum\Mongo\Migration\Migration\Environment;
use Crustum\Mongo\Migration\Migration\Manager;
use Crustum\Mongo\Migration\MigrationInterface;
use Crustum\Mongo\Test\TestCase\Migration\Stub\FakeAdapter;
use DateTime;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

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
     * @var \Crustum\Mongo\Migration\Migration\Manager|null
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

    /**
     * Test getEnvironment returns an Environment instance.
     *
     * @return void
     */
    public function testGettingAValidEnvironment(): void
    {
        $this->assertInstanceOf(Environment::class, $this->manager->getEnvironment());
    }

    /**
     * Test printStatus in json format.
     *
     * @return void
     */
    public function testPrintStatusMethodJsonFormat(): void
    {
        $envStub = $this->getMockBuilder(Environment::class)
            ->setConstructorArgs(['mockenv', []])
            ->getMock();
        $envStub->expects($this->once())
            ->method('getVersionLog')
            ->willReturn([
                '20260811000000' => [
                    'version' => '20260811000000',
                    'start_time' => '2026-08-11 00:00:00',
                    'end_time' => '2026-08-11 00:00:01',
                    'migration_name' => '',
                    'breakpoint' => '0',
                ],
                '20260812000000' => [
                    'version' => '20260812000000',
                    'start_time' => '2026-08-12 00:00:00',
                    'end_time' => '2026-08-12 00:00:01',
                    'migration_name' => '',
                    'breakpoint' => '0',
                ],
            ]);
        $this->manager->setEnvironment($envStub);

        $return = $this->manager->printStatus('json');

        $this->assertSame([
            ['status' => 'up', 'id' => 20260811000000, 'name' => 'CreateProducts'],
            ['status' => 'up', 'id' => 20260812000000, 'name' => 'AddTagsIndex'],
        ], $return);
    }

    /**
     * Test printStatus with a breakpoint set on the journal.
     *
     * @return void
     */
    public function testPrintStatusMethodWithBreakpointSet(): void
    {
        $envStub = $this->getMockBuilder(Environment::class)
            ->setConstructorArgs(['mockenv', []])
            ->getMock();
        $envStub->expects($this->once())
            ->method('getVersionLog')
            ->willReturn([
                '20260811000000' => [
                    'version' => '20260811000000',
                    'start_time' => '2026-08-11 00:00:00',
                    'end_time' => '2026-08-11 00:00:01',
                    'migration_name' => '',
                    'breakpoint' => '1',
                ],
                '20260812000000' => [
                    'version' => '20260812000000',
                    'start_time' => '2026-08-12 00:00:00',
                    'end_time' => '2026-08-12 00:00:01',
                    'migration_name' => '',
                    'breakpoint' => '0',
                ],
            ]);
        $this->manager->setEnvironment($envStub);

        $return = $this->manager->printStatus();

        $this->assertSame([
            ['status' => 'up', 'id' => 20260811000000, 'name' => 'CreateProducts'],
            ['status' => 'up', 'id' => 20260812000000, 'name' => 'AddTagsIndex'],
        ], $return);
    }

    /**
     * Test printStatus with an empty migrations directory.
     *
     * @return void
     */
    public function testPrintStatusMethodWithNoMigrations(): void
    {
        $configArray = $this->getConfigArray();
        $configArray['paths']['migrations'] = ROOT . '/tests/test_app/TestApp/config/Nomigrations';
        $config = new Config($configArray);

        $this->manager->setConfig($config);
        $this->manager->setEnvironment($this->getMockBuilder(Environment::class)
            ->setConstructorArgs(['mockenv', []])
            ->getMock());

        $this->assertSame([], $this->manager->printStatus());
    }

    /**
     * Test printStatus flags migrations recorded in the journal but missing
     * from the migration files.
     *
     * @return void
     */
    public function testPrintStatusMethodWithMissingMigrations(): void
    {
        $envStub = $this->getMockBuilder(Environment::class)
            ->setConstructorArgs(['mockenv', []])
            ->getMock();
        $envStub->expects($this->once())
            ->method('getVersionLog')
            ->willReturn([
                '20260101000000' => [
                    'version' => '20260101000000',
                    'start_time' => '2026-01-01 00:00:00',
                    'end_time' => '2026-01-01 00:00:01',
                    'migration_name' => 'GhostMigration',
                    'breakpoint' => '0',
                ],
            ]);
        $this->manager->setEnvironment($envStub);

        $return = $this->manager->printStatus();

        $this->assertCount(3, $return);
        $this->assertSame(['missing' => true, 'status' => 'up', 'id' => 20260101000000, 'name' => 'GhostMigration'], $return[0]);
        $this->assertSame('down', $return[1]['status']);
        $this->assertSame('down', $return[2]['status']);
    }

    /**
     * Test getMigrations throws on duplicate migration versions.
     *
     * @return void
     */
    public function testGetMigrationsWithDuplicateMigrationVersions(): void
    {
        $config = new Config(['paths' => ['migrations' => ROOT . '/tests/test_app/TestApp/config/Duplicateversions']]);
        $manager = new Manager($config, $this->io);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Duplicate migration/');
        $this->expectExceptionMessageMatches('/has the same version as/');

        $manager->getMigrations();
    }

    /**
     * Test getMigrations throws on duplicate migration class names.
     *
     * @return void
     */
    public function testGetMigrationsWithDuplicateMigrationNames(): void
    {
        $config = new Config(['paths' => ['migrations' => ROOT . '/tests/test_app/TestApp/config/Duplicatenames']]);
        $manager = new Manager($config, $this->io);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Migration "20120111235331_duplicate_migration_name.php" has the same name as "20120111235330_duplicate_migration_name.php"');

        $manager->getMigrations();
    }

    /**
     * Test getMigrations throws when the file class name does not match.
     *
     * @return void
     */
    public function testGetMigrationsWithInvalidMigrationClassName(): void
    {
        $config = new Config(['paths' => ['migrations' => ROOT . '/tests/test_app/TestApp/config/Invalidclassname']]);
        $manager = new Manager($config, $this->io);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Could not find class/');
        $this->expectExceptionMessageMatches('/20120111235330_invalid_class.php/');

        $manager->getMigrations();
    }

    /**
     * Test getMigrations throws for legacy `Migrations\AbstractMigration` files.
     *
     * @return void
     */
    public function testGetMigrationsWithLegacyAbstractMigrationClass(): void
    {
        $config = new Config(['paths' => ['migrations' => ROOT . '/tests/test_app/TestApp/config/LegacyAbstractMigration']]);
        $manager = new Manager($config, $this->io);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/legacy/');
        $this->expectExceptionMessageMatches('/20260327000000_LegacyAbstractMigration.php/');

        $manager->getMigrations();
    }

    /**
     * Test getMigrations supports anonymous-class migration files.
     *
     * @return void
     */
    public function testGetMigrationsWithAnonymousClass(): void
    {
        $config = new Config(['paths' => ['migrations' => ROOT . '/tests/test_app/TestApp/config/AnonymousMigrations']]);
        $manager = new Manager($config, $this->io);

        $migrations = $manager->getMigrations();

        $this->assertCount(1, $migrations);
        $migration = reset($migrations);
        $this->assertInstanceOf(MigrationInterface::class, $migration);
        $this->assertSame(20241208150000, $migration->getVersion());
    }

    /**
     * Test seed() runs every seeder.
     *
     * @return void
     */
    public function testExecuteSeedWorksAsExpected(): void
    {
        $envStub = $this->getMockBuilder(Environment::class)
            ->setConstructorArgs(['mockenv', []])
            ->getMock();
        $envStub->expects($this->any())
            ->method('getAdapter')
            ->willReturn(new FakeAdapter());
        $this->manager->setEnvironment($envStub);

        $this->manager->seed();

        $output = $this->getOutput();
        $this->assertStringContainsString('UserSeeder seed', $output);
        $this->assertStringContainsString('GSeeder seed', $output);
        $this->assertStringContainsString('PostSeeder seed', $output);
    }

    /**
     * Test seed() runs a single named seeder.
     *
     * @return void
     */
    public function testExecuteASingleSeedWorksAsExpected(): void
    {
        $envStub = $this->getMockBuilder(Environment::class)
            ->setConstructorArgs(['mockenv', []])
            ->getMock();
        $envStub->expects($this->any())
            ->method('getAdapter')
            ->willReturn(new FakeAdapter());
        $this->manager->setEnvironment($envStub);

        $this->manager->seed('UserSeeder');

        $output = $this->getOutput();
        $this->assertStringContainsString('UserSeeder seed', $output);
    }

    /**
     * Test seed() throws for an unknown seeder name.
     *
     * @return void
     */
    public function testExecuteANonExistentSeedWorksAsExpected(): void
    {
        $envStub = $this->getMockBuilder(Environment::class)
            ->setConstructorArgs(['mockenv', []])
            ->getMock();
        $this->manager->setEnvironment($envStub);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The seed `NonExistentSeeder` does not exist');

        $this->manager->seed('NonExistentSeeder');
    }

    /**
     * Test getSeeds orders seeds by their dependencies.
     *
     * @return void
     */
    public function testOrderSeeds(): void
    {
        $seeds = array_values($this->manager->getSeeds());

        $this->assertSame('UserSeeder', $seeds[0]->getName());
        $this->assertSame('GSeeder', $seeds[1]->getName());
        $this->assertSame('PostSeeder', $seeds[2]->getName());
    }

    /**
     * Test a seeder whose shouldExecute() returns false is skipped.
     *
     * @return void
     */
    public function testSeedWillNotBeExecuted(): void
    {
        $envStub = $this->getMockBuilder(Environment::class)
            ->setConstructorArgs(['mockenv', []])
            ->getMock();
        $envStub->expects($this->any())
            ->method('getAdapter')
            ->willReturn(new FakeAdapter());
        $this->manager->setEnvironment($envStub);

        $this->manager->seed('UserSeederNotExecuted');

        $output = $this->getOutput();
        $this->assertStringContainsString('skipped', $output);
    }

    /**
     * Test migrations and seeds receive the ConsoleIo instance.
     *
     * @return void
     */
    public function testGettingIo(): void
    {
        $migrations = $this->manager->getMigrations();
        $seeds = $this->manager->getSeeds();
        $io = $this->manager->getIo();

        $this->assertInstanceOf(ConsoleIo::class, $io);

        foreach ($migrations as $migration) {
            $this->assertInstanceOf(ConsoleIo::class, $migration->getIo());
        }
        foreach ($seeds as $seed) {
            $this->assertInstanceOf(ConsoleIo::class, $seed->getIo());
        }
    }

    /**
     * Test setting a breakpoint on an invalid version prints a warning.
     *
     * @return void
     */
    public function testInvalidVersionBreakpoint(): void
    {
        $envStub = $this->getMockBuilder(Environment::class)
            ->setConstructorArgs(['mockenv', []])
            ->getMock();
        $envStub->expects($this->once())
            ->method('getVersionLog')
            ->willReturn([
                '20120111235330' => [
                    'version' => '20120111235330',
                    'start_time' => '2012-01-11 23:53:36',
                    'end_time' => '2012-01-11 23:53:37',
                    'migration_name' => '',
                    'breakpoint' => '0',
                ],
            ]);
        $this->manager->setEnvironment($envStub);

        $this->manager->setBreakpoint(20120133235330);

        $output = implode("\n", $this->out->messages());
        $this->assertStringContainsString('20120133235330 is not a valid version', $output);
    }

    /**
     * Test a migration whose shouldExecute() returns false is not run.
     *
     * @return void
     */
    public function testMigrationWillNotBeExecuted(): void
    {
        $configArray = $this->getConfigArray();
        $configArray['paths']['migrations'] = ROOT . '/tests/test_app/TestApp/config/ShouldExecute';
        $this->manager->setConfig(new Config($configArray));

        $this->connection->getDatabase()->dropCollection('should_execute_info');
        $this->connection->getCollection('_migrations')->deleteMany([]);

        $this->manager->migrate(20201207205056);
        $this->assertFalse(in_array('should_execute_info', $this->connection->getSchemaCollection()->listCollections(), true));

        $this->manager->migrate(20201207205057);
        $this->assertTrue(in_array('should_execute_info', $this->connection->getSchemaCollection()->listCollections(), true));

        $this->connection->getDatabase()->dropCollection('should_execute_info');
        $this->connection->getCollection('_migrations')->deleteMany([]);
    }

    /**
     * Test change()-based migrations are reversed by rollback (end to end).
     *
     * @return void
     */
    public function testReversibleMigrationsWorkAsExpected(): void
    {
        $configArray = $this->getConfigArray();
        $configArray['paths']['migrations'] = ROOT . '/tests/test_app/TestApp/config/ReversibleMigrations';
        $this->manager->setConfig(new Config($configArray));

        $this->connection->getDatabase()->dropCollection('info');
        $this->connection->getDatabase()->dropCollection('users');
        $this->connection->getCollection('_migrations')->deleteMany([]);

        $manager = new SchemaManager($this->connection);

        $this->manager->migrate();
        $this->assertTrue(in_array('info', $manager->listCollections(), true));
        $this->assertTrue(in_array('users', $manager->listCollections(), true));

        $this->manager->rollback('20260813000000');
        $this->assertTrue(in_array('info', $manager->listCollections(), true));
        $this->assertFalse(in_array('users', $manager->listCollections(), true));

        $this->manager->rollback('0');
        $this->assertFalse(in_array('info', $manager->listCollections(), true));
        $this->assertFalse(in_array('users', $manager->listCollections(), true));

        $this->connection->getCollection('_migrations')->deleteMany([]);
    }
}


<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Migration\Adapter;

use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Database\Schema\SchemaManager;
use Crustum\Mongo\Migration\BaseMigration;
use Crustum\Mongo\Migration\BaseSeed;
use Crustum\Mongo\Migration\Db\Adapter\CakeMongoAdapter;
use Crustum\Mongo\Migration\MigrationInterface;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests the Mongo migration adapter against a real database.
 *
 * Covers DDL through the SchemaManager and the `cake_migrations` journal.
 */
#[CoversClass(CakeMongoAdapter::class)]
class CakeMongoAdapterTest extends TestCase
{
    /**
     * @var \Crustum\Mongo\Database\Connection
     */
    protected Connection $connection;

    /**
     * @var \Crustum\Mongo\Database\Schema\SchemaManager
     */
    protected SchemaManager $manager;

    /**
     * @var \Crustum\Mongo\Migration\Db\Adapter\CakeMongoAdapter
     */
    protected CakeMongoAdapter $adapter;

    /**
     * Collections created during the test run.
     *
     * @var list<string>
     */
    protected array $created = [];

    /**
     * Set up before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = ConnectionManager::get('test_mongo');
        $this->manager = new SchemaManager($this->connection);
        $this->adapter = new CakeMongoAdapter($this->connection);
        $this->connection->getCollection('cake_migrations')->deleteMany([]);
        $this->connection->getCollection('_seeds')->deleteMany([]);
    }

    /**
     * Tear down after each test.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->created as $name) {
            if (in_array($name, $this->manager->listCollections(), true)) {
                $this->manager->dropCollection($name);
            }
        }
        $this->connection->getCollection('cake_migrations')->deleteMany([]);
        $this->connection->getCollection('_seeds')->deleteMany([]);
        parent::tearDown();
    }

    /**
     * Test createCollection and dropCollection round-trip.
     *
     * @return void
     */
    public function testCreateAndDropCollection(): void
    {
        $name = 'mig_adapter_create';
        $this->created[] = $name;

        $this->assertFalse($this->adapter->hasCollection($name));

        $this->adapter->createCollection($name);
        $this->assertTrue($this->adapter->hasCollection($name));
        $this->assertContains($name, $this->adapter->listCollections());

        $this->adapter->dropCollection($name);
        $this->assertFalse($this->adapter->hasCollection($name));
    }

    /**
     * Test createCollection with a validator.
     *
     * @return void
     */
    public function testCreateCollectionWithValidator(): void
    {
        $name = 'mig_adapter_validator';
        $this->created[] = $name;

        $validator = [
            '$jsonSchema' => [
                'bsonType' => 'object',
                'properties' => ['title' => ['bsonType' => 'string']],
                'additionalProperties' => true,
            ],
        ];
        $this->adapter->createCollection($name, ['validator' => $validator]);

        $this->assertSame($validator, $this->manager->getValidator($name));
    }

    /**
     * Test createIndex and dropIndex.
     *
     * @return void
     */
    public function testCreateAndDropIndex(): void
    {
        $name = 'mig_adapter_index';
        $this->created[] = $name;
        $this->adapter->createCollection($name);

        $indexName = $this->adapter->createIndex($name, ['author_id' => 1]);
        $this->assertSame('author_id_1', $indexName);

        $indexes = $this->manager->listIndexes($name);
        $this->assertArrayHasKey('author_id_1', $indexes);

        $this->adapter->dropIndex($name, 'author_id_1');
        $indexes = $this->manager->listIndexes($name);
        $this->assertArrayNotHasKey('author_id_1', $indexes);
    }

    /**
     * Test createIndex with options and an explicit name.
     *
     * @return void
     */
    public function testCreateIndexWithOptions(): void
    {
        $name = 'mig_adapter_index_opts';
        $this->created[] = $name;
        $this->adapter->createCollection($name);

        $indexName = $this->adapter->createIndex($name, ['email' => 1], ['unique' => true, 'name' => 'email_unique']);
        $this->assertSame('email_unique', $indexName);

        $indexes = $this->manager->listIndexes($name);
        $this->assertArrayHasKey('email_unique', $indexes);
    }

    /**
     * Test setValidator updates the collection validator.
     *
     * @return void
     */
    public function testSetValidator(): void
    {
        $name = 'mig_adapter_set_validator';
        $this->created[] = $name;
        $this->adapter->createCollection($name);

        $validator = [
            '$jsonSchema' => [
                'bsonType' => 'object',
                'properties' => ['status' => ['bsonType' => 'string']],
                'additionalProperties' => true,
            ],
        ];
        $this->adapter->setValidator($name, $validator);
        $this->assertSame($validator, $this->manager->getValidator($name));

        $this->adapter->setValidator($name, null);
        $this->assertNull($this->manager->getValidator($name));
    }

    /**
     * Test renameCollection.
     *
     * @return void
     */
    public function testRenameCollection(): void
    {
        $name = 'mig_adapter_rename_from';
        $renamed = 'mig_adapter_rename_to';
        $this->created[] = $name;
        $this->created[] = $renamed;
        $this->adapter->createCollection($name);

        $this->adapter->renameCollection($name, $renamed);

        $this->assertFalse($this->adapter->hasCollection($name));
        $this->assertTrue($this->adapter->hasCollection($renamed));
    }

    /**
     * Test migrated() writes a journal entry for the up direction.
     *
     * @return void
     */
    public function testMigratedUp(): void
    {
        $migration = new class (20260811000000) extends BaseMigration {
        };

        $this->adapter->migrated($migration, MigrationInterface::UP, '2026-08-11 00:00:00', '2026-08-11 00:00:01');

        $this->assertSame([20260811000000], $this->adapter->getVersions());

        $log = $this->adapter->getVersionLog();
        $this->assertArrayHasKey(20260811000000, $log);
        $this->assertSame(20260811000000, $log[20260811000000]['version']);
        $this->assertSame(0, $log[20260811000000]['breakpoint']);
    }

    /**
     * Test migrated() removes the journal entry for the down direction.
     *
     * @return void
     */
    public function testMigratedDown(): void
    {
        $migration = new class (20260811000000) extends BaseMigration {
        };

        $this->adapter->migrated($migration, MigrationInterface::UP, 'a', 'b');
        $this->assertSame([20260811000000], $this->adapter->getVersions());

        $this->adapter->migrated($migration, MigrationInterface::DOWN, 'a', 'b');
        $this->assertSame([], $this->adapter->getVersions());
    }

    /**
     * Test unmigrated() removes the journal entry.
     *
     * @return void
     */
    public function testUnmigrated(): void
    {
        $migration = new class (20260811000000) extends BaseMigration {
        };

        $this->adapter->migrated($migration, MigrationInterface::UP, 'a', 'b');
        $this->adapter->unmigrated($migration);

        $this->assertSame([], $this->adapter->getVersions());
    }

    /**
     * Test breakpoint methods round-trip through the journal.
     *
     * @return void
     */
    public function testBreakpoints(): void
    {
        $migration = new class (20260811000000) extends BaseMigration {
        };
        $migration2 = new class (20260811000001) extends BaseMigration {
        };

        $this->adapter->migrated($migration, MigrationInterface::UP, 'a', 'b');
        $this->adapter->migrated($migration2, MigrationInterface::UP, 'a', 'b');

        $this->adapter->setBreakpoint($migration);
        $log = $this->adapter->getVersionLog();
        $this->assertSame(1, $log[20260811000000]['breakpoint']);
        $this->assertSame(0, $log[20260811000001]['breakpoint']);

        $this->adapter->toggleBreakpoint($migration);
        $this->assertSame(0, $this->adapter->getVersionLog()[20260811000000]['breakpoint']);

        $this->adapter->setBreakpoint($migration);
        $this->adapter->setBreakpoint($migration2);
        $this->assertSame(2, $this->adapter->resetAllBreakpoints());

        foreach ($this->adapter->getVersionLog() as $entry) {
            $this->assertSame(0, $entry['breakpoint']);
        }
    }

    /**
     * Test the journal records and filters by plugin (unified-ledger behavior).
     *
     * Entries written under one plugin context are invisible to another, so a
     * plugin's migration history does not pollute the app's (or another
     * plugin's) status, mirrors the reference `cake_migrations` plugin column.
     *
     * @return void
     */
    public function testPluginIsolation(): void
    {
        $pluginAdapter = new CakeMongoAdapter($this->connection, 'Migrator');
        $appAdapter = new CakeMongoAdapter($this->connection, null);

        $pluginMigration = new class (20260811000000) extends BaseMigration {
        };
        $appMigration = new class (20260811000001) extends BaseMigration {
        };

        $pluginAdapter->migrated($pluginMigration, MigrationInterface::UP, 'a', 'b');
        $appAdapter->migrated($appMigration, MigrationInterface::UP, 'a', 'b');

        $this->assertSame([20260811000000], $pluginAdapter->getVersions());
        $this->assertSame([20260811000001], $appAdapter->getVersions());

        $pluginLog = $pluginAdapter->getVersionLog();
        $this->assertArrayHasKey(20260811000000, $pluginLog);
        $this->assertSame('Migrator', $pluginLog[20260811000000]['plugin']);

        $appLog = $appAdapter->getVersionLog();
        $this->assertArrayHasKey(20260811000001, $appLog);
        $this->assertNull($appLog[20260811000001]['plugin']);

        // A plugin adapter cannot breakpoint or remove the app entry.
        $pluginAdapter->setBreakpoint($appMigration);
        $this->assertSame(0, $appLog[20260811000001]['breakpoint']);

        $pluginAdapter->unmigrated($appMigration);
        $this->assertSame([20260811000001], $appAdapter->getVersions());

        // And vice versa.
        $appAdapter->unmigrated($pluginMigration);
        $this->assertSame([20260811000000], $pluginAdapter->getVersions());
    }

    /**
     * Test the seed execution log records and removes entries.
     *
     * @return void
     */
    public function testSeedLog(): void
    {
        $seed = new SeedLogSeed();

        $this->adapter->seedExecuted($seed, '2026-08-14 12:00:00');

        $log = $this->adapter->getSeedLog();
        $this->assertCount(1, $log);
        $this->assertSame($seed->getName(), $log[0]['seed_name']);
        $this->assertNull($log[0]['plugin']);
        $this->assertSame('2026-08-14 12:00:00', $log[0]['executed_at']);

        $this->adapter->removeSeedFromLog($seed);
        $this->assertSame([], $this->adapter->getSeedLog());
    }

    /**
     * Test the seed log is recorded per plugin.
     *
     * The raw log contains every entry (plugin filtering happens in
     * `Manager::isSeedExecuted` via `Util::matchesSeedPlugin`), each carrying
     * its own plugin.
     *
     * @return void
     */
    public function testSeedLogPluginIsolation(): void
    {
        $pluginAdapter = new CakeMongoAdapter($this->connection, 'Migrator');
        $appAdapter = new CakeMongoAdapter($this->connection, null);
        $seed = new SeedLogSeed();

        $pluginAdapter->seedExecuted($seed, 'a');
        $appAdapter->seedExecuted($seed, 'b');

        $log = $this->adapter->getSeedLog();
        $this->assertCount(2, $log);
        $plugins = array_column($log, 'plugin');
        sort($plugins);
        $this->assertSame([null, 'Migrator'], $plugins);

        // App context (plugin null) only removes its own entry.
        $appAdapter->removeSeedFromLog($seed);
        $log = $this->adapter->getSeedLog();
        $this->assertCount(1, $log);
        $this->assertSame('Migrator', $log[0]['plugin']);
    }
}

/**
 * Named seed used by the adapter seed-log tests.
 */
class SeedLogSeed extends BaseSeed
{
    public function run(): void
    {
    }
}

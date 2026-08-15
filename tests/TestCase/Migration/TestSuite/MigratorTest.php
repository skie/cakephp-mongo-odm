<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Migration\TestSuite;

use Cake\Core\Plugin;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Database\Schema\SchemaManager;
use Crustum\Mongo\Migration\Migrations;
use Crustum\Mongo\Migration\TestSuite\Migrator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Depends;

/**
 * Tests the TestSuite Migrator helper.
 *
 * Ported from cakephp/migrations `MigratorTest`: the SQL table/query
 * primitives are replaced by Mongo collections and `countDocuments()`. The
 * Migrator owns the test connection and intentionally drops/truncates every
 * non-journal collection, exactly as in the reference.
 *
 * The journal is the unified-ledger analog of `cake_migrations`: every entry
 * carries a `plugin` field (null for app migrations) and reads are filtered by
 * it, so the two-sets test below relies on set A (plugin `Migrator`) and set B
 * (plain source, no plugin) not seeing each other's history.
 */
#[CoversClass(Migrator::class)]
class MigratorTest extends TestCase
{
    /**
     * The value to restore for the PHPUnit bootstrap guard.
     *
     * @var mixed
     */
    protected mixed $restore = null;

    /**
     * @var \Crustum\Mongo\Database\Connection
     */
    protected Connection $connection;

    /**
     * @var \Crustum\Mongo\Database\Schema\SchemaManager
     */
    protected SchemaManager $manager;

    /**
     * Collections the fixtures may create, cleaned between tests.
     *
     * @var list<string>
     */
    protected array $clean = ['mig_migrator', 'mig_migrator2', 'mig_skipme', 'mig_sample'];

    /**
     * Set up before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (isset($GLOBALS['__PHPUNIT_BOOTSTRAP'])) {
            $this->restore = $GLOBALS['__PHPUNIT_BOOTSTRAP'];
            unset($GLOBALS['__PHPUNIT_BOOTSTRAP']);
        }

        $this->registerMigratorPlugin();

        $this->connection = ConnectionManager::get('migrator');
        $this->manager = new SchemaManager($this->connection);

        $this->cleanup();
    }

    /**
     * Tear down after each test.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        if ($this->restore !== null) {
            $GLOBALS['__PHPUNIT_BOOTSTRAP'] = $this->restore;
            $this->restore = null;
        }

        $this->cleanup();
        parent::tearDown();
    }

    /**
     * Registers the Migrator test plugin so `Plugin::path('Migrator')` resolves.
     *
     * @return void
     */
    protected function registerMigratorPlugin(): void
    {
        if (Plugin::getCollection()->has('Migrator')) {
            return;
        }

        require_once ROOT . DS . 'tests' . DS . 'test_app' . DS . 'Plugin' . DS . 'Migrator' . DS . 'src' . DS . 'MigratorPlugin.php';

        Plugin::getCollection()->add(new \Migrator\MigratorPlugin([
            'name' => 'Migrator',
            'path' => ROOT . DS . 'tests' . DS . 'test_app' . DS . 'Plugin' . DS . 'Migrator' . DS,
        ]));
    }

    /**
     * Drops the fixture collections and clears the migration journal.
     *
     * @return void
     */
    protected function cleanup(): void
    {
        foreach ($this->clean as $name) {
            if (in_array($name, $this->manager->listCollections(), true)) {
                $this->manager->dropCollection($name);
            }
        }
        $this->connection->getCollection('cake_migrations')->deleteMany([]);
        $this->connection->getCollection('_seeds')->deleteMany([]);
    }

    /**
     * Builds an inspectable Migrator exposing the protected collection helpers.
     *
     * @return \Crustum\Mongo\Migration\TestSuite\Migrator
     */
    protected function makeInspectableMigrator(): Migrator
    {
        return new class () extends Migrator {
            /**
             * @param string $connection Connection name
             * @return array<int, string>
             */
            public function exposedGetJournalCollections(string $connection): array
            {
                return array_values($this->getJournalCollections($connection));
            }

            /**
             * @param string $connection Connection name
             * @param array<int, string> $skip Skip patterns
             * @return array<int, string>
             */
            public function exposedGetNonJournalCollections(string $connection, array $skip = []): array
            {
                return array_values($this->getNonJournalCollections($connection, $skip));
            }
        };
    }

    /**
     * Test run() migrates, drops and truncates on repeat runs.
     *
     * @return void
     */
    public function testMigrateDropTruncate(): void
    {
        $migrator = new Migrator();
        $migrator->run(['plugin' => 'Migrator', 'connection' => 'migrator']);

        $this->assertContains('mig_migrator', $this->manager->listCollections());

        $migrator->run(['plugin' => 'Migrator', 'connection' => 'migrator']);

        $this->assertContains('mig_migrator', $this->manager->listCollections());
        $this->assertSame(0, $this->connection->getCollection('mig_migrator')->countDocuments());
    }

    /**
     * Test run() with truncate disabled keeps the seeded document.
     *
     * @return void
     */
    public function testMigrateDropNoTruncate(): void
    {
        $migrator = new Migrator();
        $migrator->run(['plugin' => 'Migrator', 'connection' => 'migrator'], false);

        $this->assertContains('mig_migrator', $this->manager->listCollections());
        $this->assertSame(1, $this->connection->getCollection('mig_migrator')->countDocuments());
    }

    /**
     * Test truncate() clears migrated data after the fact.
     *
     * @return void
     */
    #[Depends('testMigrateDropNoTruncate')]
    public function testTruncateAfterMigrations(): void
    {
        $this->testMigrateDropNoTruncate();

        $migrator = new Migrator();
        $migrator->truncate('migrator');

        $this->assertSame(0, $this->connection->getCollection('mig_migrator')->countDocuments());
    }

    /**
     * Test run() honours the skip patterns.
     *
     * @return void
     */
    public function testMigrateSkipTables(): void
    {
        $this->connection->getCollection('mig_skipme')->insertOne(['name' => 'Ron']);

        $migrator = new Migrator();
        $migrator->run([
            'plugin' => 'Migrator',
            'connection' => 'migrator',
            'skip' => ['mig_skipme'],
        ]);

        $this->assertContains('mig_migrator', $this->manager->listCollections());
        $this->assertContains('mig_skipme', $this->manager->listCollections());
        $this->assertSame(1, $this->connection->getCollection('mig_skipme')->countDocuments());
    }

    /**
     * Test runMany() migrates multiple source folders and truncates.
     *
     * @return void
     */
    public function testRunManyDropTruncate(): void
    {
        $migrator = new Migrator();
        $migrator->runMany([
            ['plugin' => 'Migrator', 'connection' => 'migrator'],
            ['connection' => 'migrator', 'source' => 'Migrations2'],
        ]);

        $this->assertContains('mig_migrator', $this->manager->listCollections());
        $this->assertContains('mig_migrator2', $this->manager->listCollections());
        $this->assertSame(0, $this->connection->getCollection('mig_migrator')->countDocuments());
        $this->assertSame(2, $this->connection->getCollection('cake_migrations')->countDocuments());
    }

    /**
     * Test the journal/non-journal collection helpers.
     *
     * @return void
     */
    public function testGetJournalAndNonJournalCollections(): void
    {
        $this->connection->getCollection('mig_sample')->insertOne(['x' => 1]);
        $this->connection->getCollection('cake_migrations')->insertOne(['version' => 1, 'migration_name' => 'x']);

        $migrator = $this->makeInspectableMigrator();

        $this->assertContains('cake_migrations', $migrator->exposedGetJournalCollections('migrator'));

        $nonJournal = $migrator->exposedGetNonJournalCollections('migrator');
        $this->assertContains('mig_sample', $nonJournal);
        $this->assertNotContains('cake_migrations', $nonJournal);
        $this->assertNotContains('_seeds', $nonJournal);

        $skipped = $migrator->exposedGetNonJournalCollections('migrator', ['mig_sample*']);
        $this->assertNotContains('mig_sample', $skipped);
    }

    /**
     * Test up-only migrations are not dropped and re-run on repeat runs.
     *
     * @return void
     */
    public function testSkipMigrationDroppingIfOnlyUpMigrations(): void
    {
        $migrator = new Migrator();
        $migrator->run(['plugin' => 'Migrator', 'connection' => 'migrator']);

        $yesterday = date('Y-m-d H:i:s', strtotime('-1 day'));
        $this->connection->getCollection('cake_migrations')->updateMany([], ['$set' => ['end_time' => $yesterday]]);

        $migrator->run(['plugin' => 'Migrator', 'connection' => 'migrator']);

        $entry = $this->connection->getCollection('cake_migrations')->findOne(['version' => 20211001000000]);
        $this->assertNotNull($entry);
        $this->assertSame($yesterday, $entry['end_time']);
    }

    /**
     * Test up-only migrations with two sets are not dropped and re-run.
     *
     * Set A runs under the `Migrator` plugin, set B is a plain source with no
     * plugin. Each status check only sees its own plugin's journal entries (the
     * unified-ledger filter), so no migration is reported missing and the
     * second run is a no-op.
     *
     * @return void
     */
    public function testSkipMigrationDroppingIfOnlyUpMigrationsWithTwoSetsOfMigrations(): void
    {
        $options = [
            ['plugin' => 'Migrator', 'connection' => 'migrator'],
            ['connection' => 'migrator', 'source' => 'Migrations2'],
        ];

        $migrator = new Migrator();
        $migrator->runMany($options, false);

        $yesterday = date('Y-m-d H:i:s', strtotime('-1 day'));
        $this->connection->getCollection('cake_migrations')->updateMany([], ['$set' => ['end_time' => $yesterday]]);

        $migrator->runMany($options, false);

        foreach ([20211001000000, 20211002000000] as $version) {
            $entry = $this->connection->getCollection('cake_migrations')->findOne(['version' => $version]);
            $this->assertNotNull($entry);
            $this->assertSame($yesterday, $entry['end_time']);
        }
    }

    /**
     * Test down migrations force a drop and re-apply.
     *
     * @return void
     */
    public function testDropMigrationsIfDownMigrations(): void
    {
        $options = [
            ['plugin' => 'Migrator', 'connection' => 'migrator'],
            ['connection' => 'migrator', 'source' => 'Migrations2'],
        ];

        $migrator = new Migrator();
        $migrator->runMany($options, false);

        $yesterday = date('Y-m-d H:i:s', strtotime('-1 day'));
        $this->connection->getCollection('cake_migrations')->updateMany([], ['$set' => ['end_time' => $yesterday]]);

        // Roll back the second set (no plugin) so its migration is down again.
        (new Migrations(['connection' => 'migrator']))->rollback(['source' => 'Migrations2']);

        $migrator->runMany($options, false);

        $entry = $this->connection->getCollection('cake_migrations')->findOne(['version' => 20211002000000]);
        $this->assertNotNull($entry);
        $this->assertNotSame($yesterday, $entry['end_time']);
    }
}

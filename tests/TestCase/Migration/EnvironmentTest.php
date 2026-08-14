<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Migration;

use Cake\Console\ConsoleIo;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Database\Schema\SchemaManager;
use Crustum\Mongo\Migration\Db\Adapter\CakeMongoAdapter;
use Crustum\Mongo\Migration\BaseMigration;
use Crustum\Mongo\Migration\BaseSeed;
use Crustum\Mongo\Migration\Config\Config;
use Crustum\Mongo\Migration\Migration\Environment;
use Crustum\Mongo\Migration\MigrationInterface;
use Crustum\Mongo\Test\TestCase\Migration\Stub\FakeAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

/**
 * Tests the migration Environment dispatch.
 *
 * Adapted from cakephp/migrations `EnvironmentTest`: the SQL
 * `AbstractAdapter` mocks are replaced by the in-memory `FakeAdapter` and a
 * real-database round-trip for `change()`.
 */
#[CoversClass(Environment::class)]
class EnvironmentTest extends TestCase
{
    /**
     * @var \Crustum\Mongo\Migration\Migration\Environment
     */
    protected Environment $environment;

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
        $this->environment = new Environment('test', []);
        $this->connection = ConnectionManager::get('test_mongo');
        $this->manager = new SchemaManager($this->connection);
        $this->adapter = new CakeMongoAdapter($this->connection);
        $this->connection->getCollection('_migrations')->deleteMany([]);
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
        $this->connection->getCollection('_migrations')->deleteMany([]);
        $this->connection->getCollection('_seeds')->deleteMany([]);
        parent::tearDown();
    }

    /**
     * Test constructor stores the name and options.
     *
     * @return void
     */
    public function testConstructorWorksAsExpected(): void
    {
        $env = new Environment('testenv', ['foo' => 'bar']);

        $this->assertSame('testenv', $env->getName());
        $this->assertSame(['foo' => 'bar'], $env->getOptions());
    }

    /**
     * Test setting the name.
     *
     * @return void
     */
    public function testSettingTheName(): void
    {
        $this->environment->setName('prod123');

        $this->assertSame('prod123', $this->environment->getName());
    }

    /**
     * Test setting the options.
     *
     * @return void
     */
    public function testSettingOptions(): void
    {
        $this->environment->setOptions(['foo' => 'bar']);

        $this->assertArrayHasKey('foo', $this->environment->getOptions());
    }

    /**
     * Test getAdapter without config throws.
     *
     * @return void
     */
    public function testNoAdapter(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No config defined for the environment.');

        $this->environment->getAdapter();
    }

    /**
     * Test getAdapter with a bad connection name throws.
     *
     * @return void
     */
    public function testGetAdapterWithBadConnectionName(): void
    {
        $this->environment->setConfig(new Config([]));
        $this->environment->setOptions(['connection' => 'lolnope']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The datasource configuration `lolnope` was not found');

        $this->environment->getAdapter();
    }

    /**
     * Test getAdapter builds a CakeMongoAdapter for a Mongo connection.
     *
     * @return void
     */
    public function testGetAdapter(): void
    {
        $this->environment->setConfig(new Config([]));
        $this->environment->setOptions(['connection' => 'test_mongo']);

        $adapter = $this->environment->getAdapter();

        $this->assertInstanceOf(CakeMongoAdapter::class, $adapter);
    }

    /**
     * Test getCurrentVersion reads from the adapter.
     *
     * @return void
     */
    public function testCurrentVersion(): void
    {
        $stub = new FakeAdapter();
        $stub->versions = [20110301080000];
        $this->environment->setAdapter($stub);

        $this->assertSame(20110301080000, $this->environment->getCurrentVersion());
    }

    /**
     * Test executeMigration up() dispatch.
     *
     * @return void
     */
    public function testExecutingAMigrationUp(): void
    {
        $this->environment->setAdapter(new FakeAdapter());

        $upMigration = new class (20110301080000) extends BaseMigration {
            public bool $executed = false;

            public function up(): void
            {
                $this->executed = true;
            }
        };

        $this->environment->executeMigration($upMigration, MigrationInterface::UP);

        $this->assertTrue($upMigration->executed);
        $this->assertSame([20110301080000], $this->environment->getVersions());
    }

    /**
     * Test executeMigration down() dispatch.
     *
     * @return void
     */
    public function testExecutingAMigrationDown(): void
    {
        $this->environment->setAdapter(new FakeAdapter());

        $downMigration = new class (20110301080000) extends BaseMigration {
            public bool $executed = false;

            public function down(): void
            {
                $this->executed = true;
            }
        };

        $this->environment->executeMigration($downMigration, MigrationInterface::DOWN);

        $this->assertTrue($downMigration->executed);
    }

    /**
     * Test executeMigration opens a transaction when the migration uses them.
     *
     * @return void
     */
    public function testExecutingAMigrationWithTransactions(): void
    {
        $stub = new FakeAdapter();
        $stub->transactionSupport = true;
        $this->environment->setAdapter($stub);

        $migration = new class (20110301080000) extends BaseMigration {
            public bool $executed = false;

            public function up(): void
            {
                $this->executed = true;
            }
        };

        $this->environment->executeMigration($migration, MigrationInterface::UP);

        $this->assertTrue($migration->executed);
        $methods = array_column($stub->calls, 0);
        $this->assertContains('beginTransaction', $methods);
        $this->assertContains('commitTransaction', $methods);
    }

    /**
     * Test executeMigration skips transactions when the migration opts out.
     *
     * @return void
     */
    public function testExecutingAMigrationWithUseTransactions(): void
    {
        $stub = new FakeAdapter();
        $stub->transactionSupport = true;
        $this->environment->setAdapter($stub);

        $migration = new class (20110301080000) extends BaseMigration {
            public bool $executed = false;

            public function useTransactions(): bool
            {
                return false;
            }

            public function up(): void
            {
                $this->executed = true;
            }
        };

        $this->environment->executeMigration($migration, MigrationInterface::UP);

        $this->assertTrue($migration->executed);
        $methods = array_column($stub->calls, 0);
        $this->assertNotContains('beginTransaction', $methods);
        $this->assertNotContains('commitTransaction', $methods);
    }

    /**
     * Test executeMigration change() dispatch up.
     *
     * @return void
     */
    public function testExecutingAChangeMigrationUp(): void
    {
        $this->environment->setAdapter(new FakeAdapter());

        $migration = new class (20130301080000) extends BaseMigration {
            public bool $executed = false;

            public function change(): void
            {
                $this->executed = true;
            }
        };

        $this->environment->executeMigration($migration, MigrationInterface::UP);

        $this->assertTrue($migration->executed);
    }

    /**
     * Test executeMigration change() dispatch down runs the inverse.
     *
     * @return void
     */
    public function testExecutingAChangeMigrationDown(): void
    {
        $stub = new FakeAdapter();
        $stub->createCollection('mig_env_change');
        $this->environment->setAdapter($stub);

        $migration = new class (20130301080000) extends BaseMigration {
            public bool $executed = false;

            public function change(): void
            {
                $this->executed = true;
                $this->collection('mig_env_change')->drop()->create();
            }
        };

        $this->environment->executeMigration($migration, MigrationInterface::DOWN);

        $this->assertTrue($migration->executed);
        $this->assertArrayHasKey('mig_env_change', $stub->collections);
    }

    /**
     * Test a fake migration records the run but does not execute it.
     *
     * @return void
     */
    public function testExecutingAFakeMigration(): void
    {
        $this->environment->setAdapter(new FakeAdapter());

        $migration = new class (20130301080000) extends BaseMigration {
            public bool $executed = false;

            public function change(): void
            {
                $this->executed = true;
            }
        };

        $this->environment->executeMigration($migration, MigrationInterface::UP, true);

        $this->assertFalse($migration->executed);
        $this->assertSame([20130301080000], $this->environment->getVersions());
    }

    /**
     * Test executeMigration calls init before the migration body.
     *
     * @return void
     */
    public function testExecuteMigrationCallsInit(): void
    {
        $this->environment->setAdapter(new FakeAdapter());

        $upMigration = new class (20110301080000) extends BaseMigration {
            public bool $initExecuted = false;

            public bool $upExecuted = false;

            public function init(): void
            {
                $this->initExecuted = true;
            }

            public function up(): void
            {
                $this->upExecuted = true;
            }
        };

        $this->environment->executeMigration($upMigration, MigrationInterface::UP);

        $this->assertTrue($upMigration->initExecuted);
        $this->assertTrue($upMigration->upExecuted);
    }

    /**
     * Test executeSeed calls init and run.
     *
     * @return void
     */
    public function testExecuteSeedInit(): void
    {
        $this->environment->setAdapter(new FakeAdapter());

        $seed = new class extends BaseSeed {
            public bool $initExecuted = false;

            public bool $runExecuted = false;

            public function init(): void
            {
                $this->initExecuted = true;
            }

            public function run(): void
            {
                $this->runExecuted = true;
            }
        };

        $this->environment->executeSeed($seed);

        $this->assertTrue($seed->initExecuted);
        $this->assertTrue($seed->runExecuted);
    }

    /**
     * Test a change() migration creates the collection against a real database.
     *
     * @return void
     */
    public function testChangeMigrationCreatesCollection(): void
    {
        $name = 'mig_env_change';
        $this->created[] = $name;
        $this->environment->setAdapter($this->adapter);

        $migration = new class (20130301080000) extends BaseMigration {
            public function change(): void
            {
                $this->collection('mig_env_change')->addField('name', 'string')->create();
            }
        };

        $this->environment->executeMigration($migration, MigrationInterface::UP);

        $this->assertTrue($this->adapter->hasCollection($name));
        $this->assertSame([20130301080000], $this->adapter->getVersions());
    }

    /**
     * Test a change() migration down drops the created collection.
     *
     * @return void
     */
    public function testChangeMigrationDownDropsCollection(): void
    {
        $name = 'mig_env_change_down';
        $this->created[] = $name;
        $this->environment->setAdapter($this->adapter);

        $migration = new class (20130301080000) extends BaseMigration {
            public function change(): void
            {
                $this->collection('mig_env_change_down')->addField('name', 'string')->create();
            }
        };

        $this->environment->executeMigration($migration, MigrationInterface::UP);
        $this->assertTrue($this->adapter->hasCollection($name));

        $this->environment->executeMigration($migration, MigrationInterface::DOWN);
        $this->assertFalse($this->adapter->hasCollection($name));
        $this->assertSame([], $this->adapter->getVersions());
    }

    /**
     * Test executeSeed inserts data through the adapter.
     *
     * @return void
     */
    public function testExecuteSeedInsertsData(): void
    {
        $name = 'mig_env_seed';
        $this->created[] = $name;
        $this->adapter->createCollection($name);
        $this->environment->setAdapter($this->adapter);

        $seed = new class extends BaseSeed {
            public function run(): void
            {
                $this->insert('mig_env_seed', ['slug' => 'hello', 'count' => 1]);
            }
        };

        $this->environment->executeSeed($seed);

        $doc = $this->connection->getCollection($name)->findOne(['slug' => 'hello']);
        $this->assertNotNull($doc);
    }

    /**
     * Test executeSeed records the execution in the `_seeds` log.
     *
     * @return void
     */
    public function testExecuteSeedRecordsExecution(): void
    {
        $this->environment->setAdapter($this->adapter);

        $seed = new RecordedSeed();

        $this->environment->executeSeed($seed);

        $log = $this->adapter->getSeedLog();
        $this->assertCount(1, $log);
        $this->assertSame($seed->getName(), $log[0]['seed_name']);
    }

    /**
     * Test an idempotent seed re-records its execution instead of duplicating.
     *
     * @return void
     */
    public function testExecuteSeedIdempotentReRecords(): void
    {
        $this->environment->setAdapter($this->adapter);

        $seed = new IdempotentRecordedSeed();

        $this->environment->executeSeed($seed);
        $this->environment->executeSeed($seed);

        $this->assertCount(1, $this->adapter->getSeedLog());
    }

    /**
     * Test getting the io object.
     *
     * @return void
     */
    public function testGettingInputObject(): void
    {
        $mock = $this->getMockBuilder(ConsoleIo::class)->getMock();
        $this->environment->setIo($mock);

        $this->assertInstanceOf(ConsoleIo::class, $this->environment->getIo());
    }
}

/**
 * Named seed used by the environment seed-recording tests.
 */
class RecordedSeed extends BaseSeed
{
    public function run(): void
    {
    }
}

/**
 * Named idempotent seed used by the environment seed-recording tests.
 */
class IdempotentRecordedSeed extends RecordedSeed
{
    public function isIdempotent(): bool
    {
        return true;
    }
}

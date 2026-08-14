<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Migration;

use Cake\Datasource\ConnectionManager;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Database\Schema\SchemaManager;
use Crustum\Mongo\Migration\Migrations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests the Migrations facade (status/migrate/rollback/markMigrated) end to end
 * against the `MongoMigrations` fixture folder.
 *
 * Adapted from cakephp/migrations `MigrationsTest`.
 */
#[CoversClass(Migrations::class)]
class MigrationsTest extends TestCase
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
     * @var \Crustum\Mongo\Migration\Migrations
     */
    protected Migrations $migrations;

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
        $this->migrations = new Migrations([
            'connection' => 'test_mongo',
            'source' => 'MongoMigrations',
        ]);

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
        parent::tearDown();
    }

    /**
     * Drops the articles collection and clears the migration journal.
     *
     * @return void
     */
    protected function cleanup(): void
    {
        if (in_array('articles', $this->manager->listCollections(), true)) {
            $this->manager->dropCollection('articles');
        }
        $this->connection->getCollection('_migrations')->deleteMany([]);
    }

    /**
     * Test status reports pending migrations as down.
     *
     * @return void
     */
    public function testStatus(): void
    {
        $status = $this->migrations->status(['format' => 'json']);

        $this->assertCount(2, $status);
        $this->assertSame(['down', 'down'], array_column($status, 'status'));
        $this->assertSame([20260812000000, 20260812000001], array_column($status, 'id'));
    }

    /**
     * Test migrate applies all migrations and returns success.
     *
     * @return void
     */
    public function testMigrate(): void
    {
        $this->assertTrue($this->migrations->migrate());

        $this->assertContains('articles', $this->manager->listCollections());

        $status = $this->migrations->status(['format' => 'json']);
        $this->assertSame(['up', 'up'], array_column($status, 'status'));
    }

    /**
     * Test rollback reverts the applied migrations.
     *
     * @return void
     */
    public function testRollback(): void
    {
        $this->migrations->migrate();
        $this->assertTrue($this->migrations->rollback());

        $status = $this->migrations->status(['format' => 'json']);
        $this->assertSame('down', $status[1]['status']);
    }

    /**
     * Test markMigrated records versions without executing them.
     *
     * @return void
     */
    public function testMarkMigrated(): void
    {
        $this->assertTrue($this->migrations->markMigrated(null));

        $status = $this->migrations->status(['format' => 'json']);
        $this->assertSame(['up', 'up'], array_column($status, 'status'));

        $this->assertFalse(in_array('articles', $this->manager->listCollections(), true));
    }

    /**
     * Test seed runs a named seeder against the database.
     *
     * @return void
     */
    public function testSeed(): void
    {
        $this->connection->getDatabase()->dropCollection('seed_products');

        $seeds = new Migrations([
            'connection' => 'test_mongo',
            'source' => 'MongoSeeds',
        ]);

        $this->assertTrue($seeds->seed(['seed' => 'ProductsSeed', 'force' => true]));

        $doc = $this->connection->getCollection('seed_products')->findOne(['name' => 'widget']);
        $this->assertNotNull($doc);
        $this->assertSame(1, $doc['qty']);

        $this->connection->getDatabase()->dropCollection('seed_products');
    }
}

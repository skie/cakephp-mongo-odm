<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Migration;

use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Database\Schema\SchemaManager;
use Crustum\Mongo\Migration\Migration\SchemaDumper;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests the SchemaDumper that produces the `schema-dump-mongo.lock` shape.
 */
#[CoversClass(SchemaDumper::class)]
class SchemaDumperTest extends TestCase
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
     * @var \Crustum\Mongo\Migration\Migration\SchemaDumper
     */
    protected SchemaDumper $dumper;

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
        $this->dumper = new SchemaDumper($this->connection);
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

        parent::tearDown();
    }

    /**
     * Test dumpCollection returns the validator and indexes of a collection.
     *
     * @return void
     */
    public function testDumpCollection(): void
    {
        $name = 'mig_dump';
        $this->created[] = $name;

        $validator = [
            '$jsonSchema' => [
                'bsonType' => 'object',
                'properties' => [
                    'title' => ['bsonType' => 'string'],
                    'author_id' => ['bsonType' => 'objectId'],
                ],
                'additionalProperties' => true,
            ],
        ];
        $this->manager->createCollection($name, ['validator' => $validator]);
        $this->manager->createIndex($name, ['author_id' => 1]);

        $definition = $this->dumper->dumpCollection($name);

        $this->assertArrayHasKey('validator', $definition);
        $this->assertSame($validator, $definition['validator']);
        $this->assertArrayHasKey('indexes', $definition);
        $this->assertArrayHasKey('author_id_1', $definition['indexes']);
        $this->assertSame(['author_id' => 1], $definition['indexes']['author_id_1']['key']);
        $this->assertArrayNotHasKey('_id', $definition['indexes']);
    }

    /**
     * Test dumpAll excludes the migration journal collections.
     *
     * @return void
     */
    public function testDumpAllExcludesJournal(): void
    {
        $name = 'mig_dump_all';
        $this->created[] = $name;
        $this->manager->createCollection($name);
        $this->connection->getCollection('cake_migrations')->insertOne(['version' => 1, 'migration_name' => 'x']);

        $schema = $this->dumper->dumpAll();

        $this->assertArrayHasKey($name, $schema);
        $this->assertArrayNotHasKey('cake_migrations', $schema);
        $this->assertArrayNotHasKey('_seeds', $schema);
    }

    /**
     * Test dumpCollection for a collection without a validator.
     *
     * @return void
     */
    public function testDumpCollectionWithoutValidator(): void
    {
        $name = 'mig_dump_novalidator';
        $this->created[] = $name;
        $this->manager->createCollection($name);

        $definition = $this->dumper->dumpCollection($name);

        $this->assertArrayNotHasKey('indexes', $definition);
    }
}

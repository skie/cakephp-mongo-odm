<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Schema;

use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Database\Schema\SchemaManager;

/**
 * Tests for SchemaManager against a real Mongo connection.
 */
class SchemaManagerTest extends TestCase
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
     * Set up before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = ConnectionManager::get('test_mongo');
        $this->manager = new SchemaManager($this->connection);
    }

    /**
     * Test createCollection and dropCollection round-trip.
     *
     * @return void
     */
    public function testCreateAndDropCollection(): void
    {
        $name = 'sm_create_test';
        $this->connection->getDatabase()->dropCollection($name);

        $this->assertTrue($this->manager->createCollection($name));
        $this->assertContains($name, $this->manager->listCollections());

        $this->assertTrue($this->manager->dropCollection($name));
        $this->assertNotContains($name, $this->manager->listCollections());
    }

    /**
     * Test createCollection with a validator option.
     *
     * @return void
     */
    public function testCreateCollectionWithValidator(): void
    {
        $name = 'sm_validator_test';
        $this->connection->getDatabase()->dropCollection($name);

        $this->manager->createCollection($name, [
            'validator' => [
                '$jsonSchema' => [
                    'bsonType' => 'object',
                    'required' => ['title'],
                    'properties' => ['title' => ['bsonType' => 'string']],
                ],
            ],
        ]);

        $validator = $this->manager->getValidator($name);
        $this->assertIsArray($validator);
        $this->assertArrayHasKey('$jsonSchema', $validator);

        $this->connection->getDatabase()->dropCollection($name);
    }

    /**
     * Test setValidator updates collection validation.
     *
     * @return void
     */
    public function testSetValidator(): void
    {
        $name = 'sm_set_validator_test';
        $this->connection->getDatabase()->dropCollection($name);
        $this->manager->createCollection($name);

        $this->manager->setValidator($name, [
            '$jsonSchema' => [
                'bsonType' => 'object',
                'properties' => ['status' => ['bsonType' => 'string']],
            ],
        ]);

        $validator = $this->manager->getValidator($name);
        $this->assertArrayHasKey('$jsonSchema', $validator);

        $this->manager->setValidator($name, null);
        $this->assertNull($this->manager->getValidator($name));

        $this->connection->getDatabase()->dropCollection($name);
    }

    /**
     * Test createIndex and listIndexes.
     *
     * @return void
     */
    public function testCreateIndex(): void
    {
        $name = 'sm_index_test';
        $this->connection->getDatabase()->dropCollection($name);
        $this->manager->createCollection($name);

        $indexName = $this->manager->createIndex($name, ['title' => 1]);
        $indexes = $this->manager->listIndexes($name);

        $this->assertArrayHasKey($indexName, $indexes);
        $this->assertSame(['title' => 1], $indexes[$indexName]['key']);

        $this->manager->dropIndex($name, $indexName);
        $this->assertArrayNotHasKey($indexName, $this->manager->listIndexes($name));

        $this->connection->getDatabase()->dropCollection($name);
    }

    /**
     * Test dropCollection returns false for a missing collection.
     *
     * @return void
     */
    public function testDropMissingCollection(): void
    {
        $name = 'sm_missing_' . uniqid();
        $this->connection->getDatabase()->dropCollection($name);

        $this->assertFalse($this->manager->dropCollection($name));
    }

    /**
     * Test listCollections excludes system collections by default.
     *
     * @return void
     */
    public function testListCollectionsExcludesSystem(): void
    {
        $collections = $this->manager->listCollections();
        $this->assertNotContains('system.version', $collections);

        $all = $this->manager->listCollections(true);
        $this->assertIsArray($all);
    }

    /**
     * Test renameCollection.
     *
     * @return void
     */
    public function testRenameCollection(): void
    {
        $from = 'sm_rename_from';
        $to = 'sm_rename_to';
        $this->connection->getDatabase()->dropCollection($from);
        $this->connection->getDatabase()->dropCollection($to);
        $this->manager->createCollection($from);

        $this->manager->renameCollection($from, $to);

        $collections = $this->manager->listCollections();
        $this->assertNotContains($from, $collections);
        $this->assertContains($to, $collections);

        $this->connection->getDatabase()->dropCollection($to);
    }
}

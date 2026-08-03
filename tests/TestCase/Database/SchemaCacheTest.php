<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database;

use Cake\Cache\Cache;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Database\Schema\CachedSchemaCollection;
use Crustum\Mongo\Database\Schema\CollectionSchema;
use Crustum\Mongo\Database\SchemaCache;

/**
 * Test case for SchemaCache
 */
class SchemaCacheTest extends TestCase
{
    /**
     * @var \Crustum\Mongo\Database\Connection
     */
    protected Connection $connection;

    /**
     * @var \Psr\SimpleCache\CacheInterface
     */
    protected $cache;

    /**
     * Set up before each test
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        if (Cache::getConfig('test_schema_cache') !== null) {
            Cache::drop('test_schema_cache');
        }
        Cache::setConfig('test_schema_cache', ['className' => 'Array']);
        $this->cache = Cache::pool('test_schema_cache');

        $this->connection = ConnectionManager::get('test_mongo');
        $this->connection->cacheMetadata('test_schema_cache');
    }

    /**
     * Tear down after each test
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->connection->cacheMetadata(false);
        Cache::drop('test_schema_cache');
        parent::tearDown();
    }

    /**
     * Test that clear enables the cache if it was disabled
     *
     * @return void
     */
    public function testClearEnablesMetadataCache(): void
    {
        $this->connection->cacheMetadata(false);

        $schemaCache = new SchemaCache($this->connection);
        $schemaCache->clear();

        $this->assertInstanceOf(CachedSchemaCollection::class, $this->connection->getSchemaCollection());
    }

    /**
     * Test that build enables the cache if it was disabled
     *
     * @return void
     */
    public function testBuildEnablesMetadataCache(): void
    {
        $this->connection->cacheMetadata(false);

        $schemaCache = new SchemaCache($this->connection);
        $schemaCache->build();

        $this->assertInstanceOf(CachedSchemaCollection::class, $this->connection->getSchemaCollection());
    }

    /**
     * Test build() with no args
     *
     * @return void
     */
    public function testBuildNoArgs(): void
    {
        $collectionName = 'test_build_collection';
        $this->connection->getCollection($collectionName)->insertOne(['test' => 'data']);

        $schemaCache = new SchemaCache($this->connection);
        $collections = $schemaCache->build();

        $this->assertIsArray($collections);
        $this->assertContains($collectionName, $collections);

        $cacheKey = 'test_mongo_' . $collectionName;
        $this->assertTrue($this->cache->has($cacheKey));
    }

    /**
     * Test build() with collection name
     *
     * @return void
     */
    public function testBuildNamedCollection(): void
    {
        $collectionName = 'test_build_named';
        $this->connection->getCollection($collectionName)->insertOne(['test' => 'data']);

        $schemaCache = new SchemaCache($this->connection);
        $collections = $schemaCache->build($collectionName);

        $this->assertIsArray($collections);
        $this->assertContains($collectionName, $collections);
        $this->assertCount(1, $collections);

        $cacheKey = 'test_mongo_' . $collectionName;
        $this->assertTrue($this->cache->has($cacheKey));
    }

    /**
     * Test build() overwrites cached data
     *
     * @return void
     */
    public function testBuildOverwritesExistingData(): void
    {
        $collectionName = 'test_build_overwrite';
        $this->connection->getCollection($collectionName)->insertOne(['test' => 'data']);

        $cacheKey = 'test_mongo_' . $collectionName;
        $this->cache->set($cacheKey, 'dummy data');

        $schemaCache = new SchemaCache($this->connection);
        $schemaCache->build($collectionName);

        $cached = $this->cache->get($cacheKey);
        $this->assertNotSame('dummy data', $cached);
        $this->assertInstanceOf(CollectionSchema::class, $cached);
    }

    /**
     * Test clear() with no args
     *
     * @return void
     */
    public function testClearNoArgs(): void
    {
        $collectionName = 'test_clear_collection';
        $this->connection->getCollection($collectionName)->insertOne(['test' => 'data']);

        $cacheKey = 'test_mongo_' . $collectionName;
        $this->cache->set($cacheKey, 'dummy data');

        $schemaCache = new SchemaCache($this->connection);
        $collections = $schemaCache->clear();

        $this->assertIsArray($collections);
        $this->assertFalse($this->cache->has($cacheKey));
    }

    /**
     * Test clear() with collection name
     *
     * @return void
     */
    public function testClearNamedCollection(): void
    {
        $collectionName = 'test_clear_named';
        $this->connection->getCollection($collectionName)->insertOne(['test' => 'data']);

        $cacheKey = 'test_mongo_' . $collectionName;
        $this->cache->set($cacheKey, 'dummy data');

        $schemaCache = new SchemaCache($this->connection);
        $collections = $schemaCache->clear($collectionName);

        $this->assertIsArray($collections);
        $this->assertContains($collectionName, $collections);
        $this->assertCount(1, $collections);
        $this->assertFalse($this->cache->has($cacheKey));
    }
}

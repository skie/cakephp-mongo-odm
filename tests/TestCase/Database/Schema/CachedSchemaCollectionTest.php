<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Schema;

use Cake\Cache\Cache;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Database\Schema\CachedSchemaCollection;
use Crustum\Mongo\Database\Schema\CollectionSchema;
use Crustum\Mongo\Database\Schema\SchemaCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\SimpleCache\CacheInterface;

/**
 * Test case for CachedSchemaCollection
 */
#[CoversClass(CachedSchemaCollection::class)]
class CachedSchemaCollectionTest extends TestCase
{
    /**
     * @var \Crustum\Mongo\Database\Connection
     */
    protected Connection $connection;

    /**
     * @var \Crustum\Mongo\Database\Schema\SchemaCollection
     */
    protected SchemaCollection $collection;

    /**
     * @var \Psr\SimpleCache\CacheInterface
     */
    protected CacheInterface $cacher;

    /**
     * @var \Crustum\Mongo\Database\Schema\CachedSchemaCollection
     */
    protected CachedSchemaCollection $cachedCollection;

    /**
     * Set up before each test
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = ConnectionManager::get('test_mongo');
        $this->collection = new SchemaCollection($this->connection);

        if (Cache::getConfig('test_schema_cache') !== null) {
            Cache::drop('test_schema_cache');
        }

        Cache::setConfig('test_schema_cache', ['className' => 'Array']);
        $this->cacher = Cache::pool('test_schema_cache');

        $this->cachedCollection = new CachedSchemaCollection(
            $this->collection,
            'test',
            $this->cacher,
        );
    }

    /**
     * Tear down after each test
     *
     * @return void
     */
    protected function tearDown(): void
    {
        Cache::drop('test_schema_cache');
        parent::tearDown();
    }

    /**
     * Test listTables() delegates to wrapped collection
     *
     * @return void
     */
    public function testListTables(): void
    {
        $tables = $this->cachedCollection->listTables();
        $this->assertIsArray($tables);
    }

    /**
     * Test describe() caches CollectionSchema
     *
     * @return void
     */
    public function testDescribeCachesSchema(): void
    {
        $collectionName = 'test_cached_collection';
        $this->connection->getCollection($collectionName)->insertOne(['test' => 'data']);

        $schema1 = $this->cachedCollection->describe($collectionName);
        $this->assertInstanceOf(CollectionSchema::class, $schema1);

        $cacheKey = $this->cachedCollection->cacheKey($collectionName);
        $cached = $this->cacher->get($cacheKey);
        $this->assertNotNull($cached);
        $this->assertInstanceOf(CollectionSchema::class, $cached);
        $this->assertEquals($schema1->name(), $cached->name());
    }

    /**
     * Test describe() returns cached schema on second call
     *
     * @return void
     */
    public function testDescribeReturnsCachedSchema(): void
    {
        $collectionName = 'test_cached_collection_2';
        $this->connection->getCollection($collectionName)->insertOne(['test' => 'data']);

        $schema1 = $this->cachedCollection->describe($collectionName);
        $schema2 = $this->cachedCollection->describe($collectionName);

        $this->assertSame($schema1, $schema2);
    }

    /**
     * Test describe() with forceRefresh bypasses cache
     *
     * @return void
     */
    public function testDescribeForceRefresh(): void
    {
        $collectionName = 'test_cached_collection_3';
        $this->connection->getCollection($collectionName)->insertOne(['test' => 'data']);

        $schema1 = $this->cachedCollection->describe($collectionName);
        $schema2 = $this->cachedCollection->describe($collectionName, ['forceRefresh' => true]);

        $this->assertNotSame($schema1, $schema2);
    }

    /**
     * Test cacheKey() generates correct key
     *
     * @return void
     */
    public function testCacheKey(): void
    {
        $key = $this->cachedCollection->cacheKey('posts');
        $this->assertSame('test_posts', $key);
    }

    /**
     * Test getCacher() returns cacher instance
     *
     * @return void
     */
    public function testGetCacher(): void
    {
        $cacher = $this->cachedCollection->getCacher();
        $this->assertSame($this->cacher, $cacher);
    }

    /**
     * Test setCacher() updates cacher instance
     *
     * @return void
     */
    public function testSetCacher(): void
    {
        Cache::setConfig('test_schema_cache_2', ['className' => 'Array']);
        $newCacher = Cache::pool('test_schema_cache_2');

        $result = $this->cachedCollection->setCacher($newCacher);
        $this->assertSame($this->cachedCollection, $result);

        $cacher = $this->cachedCollection->getCacher();
        $this->assertSame($newCacher, $cacher);

        Cache::drop('test_schema_cache_2');
    }
}

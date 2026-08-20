<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database;

use Cake\Cache\Cache;
use Crustum\Mongo\Database\Schema\CachedSchemaCollection;
use RuntimeException;

/**
 * Schema Cache.
 *
 * This tool is intended to be used by deployment scripts so that you
 * can prevent thundering herd effects on the metadata cache when new
 * versions of your application are deployed, or when migrations
 * requiring updated metadata are required.
 *
 * @link https://en.wikipedia.org/wiki/Thundering_herd_problem About the thundering herd problem
 * @ported-from \Cake\Database\SchemaCache
 */
class SchemaCache
{
    /**
     * Schema
     *
     * @var \Crustum\Mongo\Database\Schema\CachedSchemaCollection
     */
    protected CachedSchemaCollection $schema;

    /**
     * Constructor
     *
     * @param \Crustum\Mongo\Database\Connection $connection Connection instance
     */
    public function __construct(Connection $connection)
    {
        $this->schema = $this->getSchema($connection);
    }

    /**
     * Build metadata.
     *
     * @param string|null $name The name of the collection to build cache data for.
     * @return array<string> Returns a list of built collection caches
     */
    public function build(?string $name = null): array
    {
        $collections = $name ? [$name] : $this->schema->listTables();

        foreach ($collections as $collection) {
            $this->schema->describe($collection, ['forceRefresh' => true]);
        }

        return $collections;
    }

    /**
     * Clear metadata.
     *
     * @param string|null $name The name of the collection to clear cache data for.
     * @return array<string> Returns a list of cleared collection caches
     */
    public function clear(?string $name = null): array
    {
        $collections = $name ? [$name] : $this->schema->listTables();

        $cacher = $this->schema->getCacher();

        foreach ($collections as $collection) {
            $key = $this->schema->cacheKey($collection);
            $cacher->delete($key);
        }

        return $collections;
    }

    /**
     * Helper method to get the schema collection.
     *
     * @param \Crustum\Mongo\Database\Connection $connection Connection object
     * @return \Crustum\Mongo\Database\Schema\CachedSchemaCollection
     * @throws \RuntimeException If given connection object is not compatible with schema caching
     */
    protected function getSchema(Connection $connection): CachedSchemaCollection
    {
        $config = $connection->config();
        if (empty($config['cacheMetadata'])) {
            $cacheConfigName = '_cake_mongo_schema_';
            if (!class_exists(Cache::class)) {
                throw new RuntimeException(
                    'To use schema caching you must require the cakephp/cache package in your composer config.',
                );
            }

            if (Cache::getConfig($cacheConfigName) === null) {
                Cache::setConfig($cacheConfigName, ['className' => 'Array']);
            }

            $connection->cacheMetadata($cacheConfigName);
        }

        $schema = $connection->getSchemaCollection();
        if (!$schema instanceof CachedSchemaCollection) {
            throw new RuntimeException(
                'Schema caching must be enabled for SchemaCache to work',
            );
        }

        return $schema;
    }
}

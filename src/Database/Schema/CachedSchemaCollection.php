<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Schema;

use Crustum\Mongo\Datasource\SchemaCollectionInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Decorates a schema collection and adds caching
 */
class CachedSchemaCollection implements SchemaCollectionInterface
{
    /**
     * Cacher instance.
     *
     * @var \Psr\SimpleCache\CacheInterface
     */
    protected CacheInterface $cacher;

    /**
     * The decorated schema collection
     *
     * @var \Crustum\Mongo\Database\Schema\SchemaCollection
     */
    protected SchemaCollection $collection;

    /**
     * The cache key prefix
     *
     * @var string
     */
    protected string $prefix;

    /**
     * Constructor.
     *
     * @param \Crustum\Mongo\Database\Schema\SchemaCollection $collection The collection to wrap.
     * @param string $prefix The cache key prefix to use. Typically the connection name.
     * @param \Psr\SimpleCache\CacheInterface $cacher Cacher instance.
     */
    public function __construct(SchemaCollection $collection, string $prefix, CacheInterface $cacher)
    {
        $this->collection = $collection;
        $this->prefix = $prefix;
        $this->cacher = $cacher;
    }

    /**
     * Returns list of collections in the database
     *
     * @return array<int, string> List of collection names
     */
    public function listTables(): array
    {
        return $this->collection->listTables();
    }

    /**
     * @inheritDoc
     */
    public function listCollections(): array
    {
        return $this->collection->listCollections();
    }

    /**
     * @inheritDoc
     */
    public function clearCache(?string $name = null): void
    {
        if ($name === null) {
            return;
        }

        $this->cacher->delete($this->cacheKey($name));
    }

    /**
     * Get the schema for a collection.
     *
     * Caching will be applied if `cacheMetadata` key is present in the Connection
     * configuration options.
     *
     * ### Options
     *
     * - `forceRefresh` - Set to true to force rebuilding the cached metadata.
     *   Defaults to false.
     *
     * @param string $name The name of the collection to describe.
     * @param array<string, mixed> $options The options to use, see above.
     * @return \Crustum\Mongo\Database\Schema\CollectionSchema Object with collection metadata.
     */
    public function describe(string $name, array $options = []): CollectionSchema
    {
        $options += ['forceRefresh' => false];
        $cacheKey = $this->cacheKey($name);

        if (!$options['forceRefresh']) {
            $cached = $this->cacher->get($cacheKey);
            if ($cached instanceof CollectionSchema) {
                return $cached;
            }
        }

        $schema = $this->collection->describe($name);
        assert($schema instanceof CollectionSchema);
        $this->cacher->set($cacheKey, $schema);

        return $schema;
    }

    /**
     * Get the cache key for a given name.
     *
     * @param string $name The name to get a cache key for.
     * @return string The cache key.
     */
    public function cacheKey(string $name): string
    {
        return $this->prefix . '_' . $name;
    }

    /**
     * Set a cacher.
     *
     * @param \Psr\SimpleCache\CacheInterface $cacher Cacher object
     * @return $this
     */
    public function setCacher(CacheInterface $cacher): static
    {
        $this->cacher = $cacher;

        return $this;
    }

    /**
     * Get a cacher.
     *
     * @return \Psr\SimpleCache\CacheInterface Cacher object
     */
    public function getCacher(): CacheInterface
    {
        return $this->cacher;
    }
}

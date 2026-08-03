<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database;

use Cake\Cache\Cache;
use Cake\Core\Exception\CakeException;
use Cake\Datasource\ConnectionInterface;
use Crustum\Mongo\Database\Driver\DriverInterface;
use Crustum\Mongo\Database\Driver\MongoDriver;
use Crustum\Mongo\Database\Query\Query;
use Crustum\Mongo\Database\Schema\CachedSchemaCollection;
use Crustum\Mongo\Database\Schema\SchemaCollection;
use Crustum\Mongo\Datasource\SchemaCollectionInterface;
use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Database;
use Psr\SimpleCache\CacheInterface;

/**
 * Mongo connection implementing `Cake\Datasource\ConnectionInterface`.
 *
 * Owns the driver and dispatches compiled queries to the underlying MongoDB
 * collection operations.
 *
 * @see cake50/src/Database/Connection.php
 */
class Connection implements ConnectionInterface
{
    public const string ROLE_READ = 'read';

    public const string ROLE_WRITE = 'write';

    /**
     * The driver.
     *
     * @var \Crustum\Mongo\Database\Driver\DriverInterface
     */
    protected DriverInterface $driver;

    /**
     * Connection name.
     *
     * @var string
     */
    protected string $name;

    /**
     * Connection config.
     *
     * @var array<string, mixed>
     */
    protected array $config;

    /**
     * Result cache cacher.
     *
     * @var \Psr\SimpleCache\CacheInterface|null
     */
    protected ?CacheInterface $cacher = null;

    /**
     * The schema collection.
     *
     * @var \Crustum\Mongo\Datasource\SchemaCollectionInterface|null
     */
    protected ?SchemaCollectionInterface $schemaCollection = null;

    /**
     * Constructor.
     *
     * Signature matches `Cake\Database\Connection` (constructed by
     * `Cake\Datasource\ConnectionManager` with the connection config, where the
     * `name` key carries the connection name).
     *
     * @param array<string, mixed> $config Connection config.
     */
    public function __construct(array $config = [])
    {
        $this->name = (string)($config['name'] ?? '');
        $this->config = $config;

        $driverClass = $config['driver'] ?? MongoDriver::class;
        $this->driver = new $driverClass($config);
    }

    /**
     * @inheritDoc
     */
    public function getDriver(string $role = self::ROLE_WRITE): object
    {
        return $this->driver;
    }

    /**
     * @inheritDoc
     */
    public function setCacher(CacheInterface $cacher): static
    {
        $this->cacher = $cacher;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getCacher(): CacheInterface
    {
        if ($this->cacher instanceof CacheInterface) {
            return $this->cacher;
        }

        $configName = $this->config['cacheMetadata'] ?? '_cake_model_';
        if (!is_string($configName)) {
            $configName = '_cake_model_';
        }

        return $this->cacher = Cache::pool($configName);
    }

    /**
     * @inheritDoc
     */
    public function configName(): string
    {
        return $this->name;
    }

    /**
     * @inheritDoc
     */
    public function config(): array
    {
        return $this->config;
    }

    /**
     * Returns the Mongo client.
     *
     * @return \MongoDB\Client
     */
    public function getClient(): Client
    {
        return $this->driver->getClient();
    }

    /**
     * Returns the configured database.
     *
     * @return \MongoDB\Database
     */
    public function getDatabase(): Database
    {
        return $this->driver->getDatabase();
    }

    /**
     * Returns a collection by name.
     *
     * @param string $name Collection name.
     * @return \MongoDB\Collection
     */
    public function getCollection(string $name): Collection
    {
        return $this->driver->getCollection($name);
    }

    /**
     * Returns the schema collection (introspection) for this connection.
     *
     * When schema caching is enabled via `cacheMetadata()`, a
     * `CachedSchemaCollection` decorator is returned. The wrapper is built per
     * call so toggling `cacheMetadata()` (e.g. across tests on a shared
     * connection) takes effect immediately.
     *
     * @return \Crustum\Mongo\Datasource\SchemaCollectionInterface
     */
    public function getSchemaCollection(): SchemaCollectionInterface
    {
        $this->schemaCollection ??= new SchemaCollection($this);
        assert($this->schemaCollection instanceof SchemaCollection);

        $cacheName = $this->config['cacheMetadata'] ?? null;
        if ($cacheName !== null) {
            return new CachedSchemaCollection($this->schemaCollection, $this->name, Cache::pool((string)$cacheName));
        }

        return $this->schemaCollection;
    }

    /**
     * Enables or disables metadata caching for schema introspection.
     *
     * @param string|false $cacheConfigName The cache config name to use, or `false` to disable.
     * @return $this
     */
    public function cacheMetadata(string|false $cacheConfigName): static
    {
        if ($cacheConfigName === false) {
            unset($this->config['cacheMetadata']);
        } else {
            $this->config['cacheMetadata'] = $cacheConfigName;
        }

        return $this;
    }

    /**
     * Replaces the schema collection instance.
     *
     * @param \Crustum\Mongo\Datasource\SchemaCollectionInterface $schemaCollection The schema collection.
     * @return $this
     */
    public function setSchemaCollection(SchemaCollectionInterface $schemaCollection): static
    {
        $this->schemaCollection = $schemaCollection;

        return $this;
    }

    /**
     * Executes a compiled query.
     *
     * @param \Crustum\Mongo\Database\Query\Query $query The query to run.
     * @return mixed The result of the underlying MongoDB operation.
     * @throws \Cake\Core\Exception\CakeException On unsupported query types.
     */
    public function run(Query $query): mixed
    {
        $compiled = $query->compile();
        $collection = $this->getCollection((string)($compiled['collection'] ?? ''));

        return match ((string)$compiled['type']) {
            'find' => $collection->find($compiled['filter'] ?? [], $compiled['options'] ?? []),
            'aggregate' => $collection->aggregate($compiled['pipeline'] ?? [], $compiled['options'] ?? []),
            Query::TYPE_INSERT => $this->doInsert($collection, $compiled['documents'] ?? [], $compiled['options'] ?? []),
            Query::TYPE_UPDATE => $collection
                ->updateMany($compiled['filter'] ?? [], $compiled['update'] ?? [], $compiled['options'] ?? [])
                ->getModifiedCount(),
            Query::TYPE_DELETE => $collection
                ->deleteMany($compiled['filter'] ?? [], $compiled['options'] ?? [])
                ->getDeletedCount(),
            default => throw new CakeException(sprintf('Unsupported query type `%s`.', $compiled['type'])),
        };
    }

    /**
     * Inserts one or many documents and returns the inserted identifiers.
     *
     * @param \MongoDB\Collection $collection The collection.
     * @param list<array<string, mixed>> $documents The documents.
     * @param array<string, mixed> $options Insert options.
     * @return list<string>
     */
    protected function doInsert(Collection $collection, array $documents, array $options): array
    {
        if (count($documents) === 1) {
            $result = $collection->insertOne($documents[0], $options);

            return [(string)$result->getInsertedId()];
        }

        $result = $collection->insertMany($documents, $options);

        return array_map(strval(...), $result->getInsertedIds());
    }
}

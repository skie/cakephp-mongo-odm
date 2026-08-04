<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database;

use Cake\Cache\Cache;
use Cake\Core\Exception\CakeException;
use Cake\Datasource\ConnectionInterface;
use Closure;
use Crustum\Mongo\Database\Driver\DriverInterface;
use Crustum\Mongo\Database\Driver\MongoDriver;
use Crustum\Mongo\Database\Query\Query;
use Crustum\Mongo\Database\Schema\CachedSchemaCollection;
use Crustum\Mongo\Database\Schema\SchemaCollection;
use Crustum\Mongo\Datasource\SchemaCollectionInterface;
use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Database;
use MongoDB\Driver\Session;
use Psr\SimpleCache\CacheInterface;
use Throwable;

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
     * The active transaction session.
     *
     * @var \MongoDB\Driver\Session|null
     */
    protected ?Session $session = null;

    /**
     * Whether a transaction has been started.
     *
     * @var bool
     */
    protected bool $transactionStarted = false;

    /**
     * Nested transaction depth (Mongo has no savepoints; nesting is emulated).
     *
     * @var int
     */
    protected int $transactionLevel = 0;

    /**
     * Callbacks to run after the outermost commit.
     *
     * @var list<\Closure>
     */
    protected array $afterCommitCallbacks = [];

    /**
     * Whether the server supports transactions (probed once).
     *
     * @var bool|null
     */
    protected ?bool $transactionsSupported = null;

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

        $options = $compiled['options'] ?? [];
        if ($this->session instanceof Session) {
            $options['session'] = $this->session;
        }

        return match ((string)$compiled['type']) {
            'find' => $collection->find($compiled['filter'] ?? [], $options),
            'aggregate' => $collection->aggregate($compiled['pipeline'] ?? [], $options),
            Query::TYPE_INSERT => $this->doInsert($collection, $compiled['documents'] ?? [], $options),
            Query::TYPE_UPDATE => $collection
                ->updateMany($compiled['filter'] ?? [], $compiled['update'] ?? [], $options)
                ->getModifiedCount(),
            Query::TYPE_DELETE => $collection
                ->deleteMany($compiled['filter'] ?? [], $options)
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

    /**
     * Starts a new transaction.
     *
     * Nested `begin()` calls increment the transaction level; only the
     * outermost level maps to a real Mongo session transaction.
     *
     * On servers without replica-set/mongos support (e.g. a standalone) the
     * transaction is emulated: state is tracked so `commit()`/`rollback()` /
     * `afterCommit()` keep their semantics, but writes are not atomic.
     *
     * @return void
     */
    public function begin(): void
    {
        if ($this->transactionStarted) {
            $this->transactionLevel++;

            return;
        }

        $this->transactionStarted = true;
        $this->transactionLevel = 0;

        if (!$this->supportsTransactions()) {
            return;
        }

        $session = $this->getClient()->startSession();
        $session->startTransaction();
        $this->session = $session;
    }

    /**
     * Commits the current transaction and runs registered after-commit callbacks.
     *
     * Nested commits decrement the level without committing.
     *
     * @return bool true on success, false otherwise.
     */
    public function commit(): bool
    {
        if (!$this->transactionStarted) {
            return false;
        }

        if ($this->transactionLevel > 0) {
            $this->transactionLevel--;

            return true;
        }

        $session = $this->session;
        $this->transactionStarted = false;
        $this->session = null;

        if ($session instanceof Session) {
            $session->commitTransaction();
        }

        $callbacks = $this->afterCommitCallbacks;
        $this->afterCommitCallbacks = [];
        foreach ($callbacks as $callback) {
            $callback();
        }

        return true;
    }

    /**
     * Rolls back the current transaction and discards after-commit callbacks.
     *
     * @return bool true on success, false otherwise.
     */
    public function rollback(): bool
    {
        if (!$this->transactionStarted) {
            return false;
        }

        if ($this->transactionLevel > 0) {
            $this->transactionLevel--;

            return true;
        }

        $session = $this->session;
        $this->transactionStarted = false;
        $this->session = null;

        if ($session instanceof Session) {
            $session->abortTransaction();
        }

        $this->afterCommitCallbacks = [];

        return true;
    }

    /**
     * Returns whether the server supports multi-document transactions.
     *
     * The result is probed once per connection instance and cached.
     *
     * @return bool
     */
    public function supportsTransactions(): bool
    {
        if ($this->transactionsSupported !== null) {
            return $this->transactionsSupported;
        }

        try {
            $database = $this->getDatabase();
            $session = $this->getClient()->startSession();
            $session->startTransaction();
            $database->command(['ping' => 1], ['session' => $session]);
            $session->abortTransaction();

            return $this->transactionsSupported = true;
        } catch (Throwable) {
            return $this->transactionsSupported = false;
        }
    }

    /**
     * Registers a callback to run after the outermost transaction commits.
     *
     * When no transaction is active the callback runs immediately.
     *
     * @param \Closure $callback Callback to execute after commit.
     * @return void
     */
    public function afterCommit(Closure $callback): void
    {
        if (!$this->transactionStarted) {
            $callback();

            return;
        }

        $this->afterCommitCallbacks[] = $callback;
    }

    /**
     * Returns whether a transaction is currently active.
     *
     * @return bool
     */
    public function inTransaction(): bool
    {
        return $this->transactionStarted;
    }

    /**
     * Executes a callable within a transaction.
     *
     * @param \Closure $callback Callable that will be executed in a transaction.
     * @return mixed The return value of the callback.
     * @throws \Throwable Rethrows the exception after rolling back.
     */
    public function transactional(Closure $callback): mixed
    {
        $this->begin();

        try {
            $result = $callback($this);
            if ($result === false) {
                $this->rollback();

                return false;
            }

            $this->commit();

            return $result;
        } catch (Throwable $throwable) {
            $this->rollback();

            throw $throwable;
        }
    }

    /**
     * Returns the active transaction session, if any.
     *
     * @return \MongoDB\Driver\Session|null
     */
    public function getSession(): ?Session
    {
        return $this->session;
    }
}

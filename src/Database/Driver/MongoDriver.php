<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Driver;

use Cake\Core\Exception\CakeException;
use Crustum\Mongo\Database\Enum\DriverFeature;
use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Database;
use MongoDB\Driver\Manager;

/**
 * MongoDB driver for Crustum\Mongo.
 *
 * Reused conceptually from the old `Cake\Mongo\Database\Driver\MongoDriver`,
 * rebuilt as a Mongo-native driver: it no longer extends `Cake\Database\Driver`
 * (PDO machinery). Config **requires** the `database` key (no privileged
 * defaults per the migration-guide convention); `url` may be given directly,
 * otherwise it is assembled from `host`/`port`/`username`/`password`.
 *
 * Not final: tests and drivers mock/extend it.
 */
class MongoDriver implements DriverInterface
{
    /**
     * Driver configuration.
     *
     * @var array<string, mixed>
     */
    protected array $config;

    /**
     * The MongoDB client.
     *
     * @var \MongoDB\Client|null
     */
    protected ?Client $client = null;

    /**
     * The selected database.
     *
     * @var \MongoDB\Database|null
     */
    protected ?Database $database = null;

    /**
     * Constructor.
     *
     * @param array<string, mixed> $config Driver configuration.
     * @throws \Cake\Core\Exception\CakeException When the database key is missing.
     */
    public function __construct(array $config = [])
    {
        if (empty($config['database'])) {
            throw new CakeException('Mongo driver requires a "database" key.');
        }

        $this->config = $config;
    }

    /**
     * @inheritDoc
     */
    public function getClient(): Client
    {
        if (!$this->client instanceof Client) {
            $this->connect();
        }

        return $this->client;
    }

    /**
     * @inheritDoc
     */
    public function getDatabase(): Database
    {
        if (!$this->database instanceof Database) {
            $this->database = $this->getClient()->selectDatabase((string)$this->config['database']);
        }

        return $this->database;
    }

    /**
     * @inheritDoc
     */
    public function getManager(): Manager
    {
        return $this->getClient()->getManager();
    }

    /**
     * @inheritDoc
     */
    public function getCollection(string $name): Collection
    {
        return $this->getDatabase()->selectCollection($name);
    }

    /**
     * @inheritDoc
     */
    public function supports(DriverFeature $feature): bool
    {
        return match ($feature) {
            DriverFeature::Aggregation => true,
            DriverFeature::Sessions => true,
            DriverFeature::Transactions,
            DriverFeature::ChangeStreams => true,
            DriverFeature::SearchIndex,
            DriverFeature::VectorSearch => false,
        };
    }

    /**
     * @inheritDoc
     */
    public function config(): array
    {
        return $this->config;
    }

    /**
     * @inheritDoc
     */
    public function connect(): void
    {
        $dsn = $this->buildDsn();
        $this->client = new Client($dsn, $this->connectionOptions());
    }

    /**
     * @inheritDoc
     */
    public function disconnect(): void
    {
        $this->client = null;
        $this->database = null;
    }

    /**
     * Builds the MongoDB connection string from config.
     *
     * @return string
     */
    protected function buildDsn(): string
    {
        if (!empty($this->config['url'])) {
            return (string)$this->config['url'];
        }

        $host = $this->config['host'] ?? 'localhost';
        $port = $this->config['port'] ?? 27017;
        $credentials = '';
        if (!empty($this->config['username']) || !empty($this->config['password'])) {
            $credentials = rawurlencode((string)($this->config['username'] ?? ''))
                . ':' . rawurlencode((string)($this->config['password'] ?? '')) . '@';
        }

        return sprintf('mongodb://%s%s:%s', $credentials, $host, $port);
    }

    /**
     * Returns the MongoDB client constructor options.
     *
     * @return array<string, mixed>
     */
    protected function connectionOptions(): array
    {
        $options = $this->config['options'] ?? [];
        if (is_array($options)) {
            return $options;
        }

        return [];
    }
}

<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Driver;

use Cake\Core\App;
use Cake\Core\Exception\CakeException;
use Cake\Database\Log\QueryLogger;
use Crustum\Mongo\Database\Enum\DriverFeature;
use Crustum\Mongo\Database\Log\CommandSubscriber;
use Crustum\Mongo\Database\Log\MongoLogger;
use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Database;
use MongoDB\Driver\Command;
use MongoDB\Driver\Manager;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Stringable;
use Throwable;

/**
 * MongoDB driver for Crustum\Mongo.
 *
 * Mongo-native driver. Config
 * **requires** the `database` key (no privileged defaults per the
 * migration-guide convention); `url` may be given directly, otherwise it is
 * assembled from `host`/`port`/`username`/`password`.
 */
class MongoDriver implements DriverInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

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
     * Whether to log commands generated during this connection.
     *
     * @var bool
     */
    protected bool $logQueries = false;

    /**
     * The command subscriber forwarding driver commands to the query logger.
     *
     * @var \Crustum\Mongo\Database\Log\CommandSubscriber|null
     */
    protected ?CommandSubscriber $commandSubscriber = null;

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

        $config += ['log' => false];
        $this->config = $config;

        if ($config['log'] instanceof LoggerInterface) {
            $this->logQueries = true;
            $this->logger = $config['log'];
        } elseif ($config['log'] !== false) {
            $this->logQueries = true;
            $this->logger = $this->createLogger($config['log'] === true ? null : $config['log']);
        }
    }

    /**
     * Sets the logger used to log commands.
     *
     * Enables query logging and re-points an already-registered subscriber at
     * the new logger, matching `Cake\Database\Driver::setLogger()`.
     *
     * @param \Psr\Log\LoggerInterface $logger The logger instance.
     * @return void
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
        $this->enableQueryLogging();

        if ($this->commandSubscriber instanceof CommandSubscriber) {
            $this->commandSubscriber->setLogger(new MongoLogger($logger));
        }
    }

    /**
     * Returns the configured logger.
     *
     * @return \Psr\Log\LoggerInterface|null
     */
    public function getLogger(): ?LoggerInterface
    {
        return $this->logger;
    }

    /**
     * Creates a logger instance from a class name.
     *
     * @param string|null $className Logger's class name.
     * @return \Psr\Log\LoggerInterface
     */
    protected function createLogger(?string $className): LoggerInterface
    {
        $className ??= QueryLogger::class;

        /** @var class-string<\Psr\Log\LoggerInterface>|null $className */
        $className = App::className($className, 'Cake/Log', 'Log');
        if ($className === null) {
            throw new CakeException(
                'For logging you must either set the `log` config to a FQCN which implements Psr\Log\LoggerInterface' .
                ' or require the cakephp/log package in your composer config.',
            );
        }

        return new $className();
    }

    /**
     * Logs a message through the configured logger, when logging is enabled.
     *
     * @param \Stringable|string $message The message to log.
     * @param array<string, mixed> $context Log context.
     * @return bool Whether the message was logged.
     */
    public function log(Stringable|string $message, array $context = []): bool
    {
        if ($this->logger === null || !$this->logQueries) {
            return false;
        }

        $this->logger->debug((string)$message, $context);

        return true;
    }

    /**
     * Enables query logging.
     *
     * Registers a `CommandSubscriber` (forwarding driver commands to the query
     * logger via a `MongoLogger`) so every command emitted on this driver is
     * captured.
     *
     * @return $this
     */
    public function enableQueryLogging(): static
    {
        $this->logQueries = true;

        if (!$this->commandSubscriber instanceof CommandSubscriber) {
            $this->commandSubscriber = new CommandSubscriber(new MongoLogger($this->logger ?? new QueryLogger()));
        }

        $this->commandSubscriber->enable();

        return $this;
    }

    /**
     * Disables query logging.
     *
     * Unregisters the `CommandSubscriber` so subsequent commands are not captured.
     *
     * @return $this
     */
    public function disableQueryLogging(): static
    {
        $this->logQueries = false;

        $this->commandSubscriber?->disable();

        return $this;
    }

    /**
     * Returns whether query logging is enabled.
     *
     * @return bool
     */
    public function isQueryLoggingEnabled(): bool
    {
        return $this->logQueries;
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
            DriverFeature::Window => $this->supportsWindowFunctions(),
        };
    }

    /**
     * Returns whether the connected server supports window functions.
     *
     * `$setWindowFields` requires MongoDB 5.0+; older servers reject the
     * stage at execution time, so capability must be probed up front.
     *
     * @return bool
     */
    protected function supportsWindowFunctions(): bool
    {
        try {
            $server = $this->getManager()->selectServer();
            $buildInfo = $server->executeCommand('admin', new Command(['buildInfo' => 1]))->toArray()[0] ?? null;
            $version = $buildInfo->version ?? '';
            $parts = explode('.', $version);

            return (int)$parts[0] >= 5;
        } catch (Throwable) {
            return false;
        }
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
     * Returns whether a client has been connected.
     *
     * @return bool
     */
    public function isConnected(): bool
    {
        return $this->client instanceof Client;
    }

    /**
     * Disconnects the driver on shutdown.
     */
    public function __destruct()
    {
        $this->disconnect();
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

    /**
     * Returns an array that can be used to describe the internal state of this
     * object.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'connected' => $this->isConnected(),
            'database' => $this->database instanceof Database ? $this->database->getDatabaseName() : null,
            'logQueries' => $this->logQueries,
        ];
    }
}

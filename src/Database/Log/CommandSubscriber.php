<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Log;

use Crustum\Mongo\Database\Log\MongoLogger;
use MongoDB\Driver\Monitoring\CommandFailedEvent;
use MongoDB\Driver\Monitoring\CommandStartedEvent;
use MongoDB\Driver\Monitoring\CommandSubscriber as CommandSubscriberInterface;
use MongoDB\Driver\Monitoring\CommandSucceededEvent;
use Psr\Log\LogLevel;
use function MongoDB\Driver\Monitoring\addSubscriber;
use function MongoDB\Driver\Monitoring\removeSubscriber;

/**
 * MongoDB command monitoring subscriber.
 *
 * Implements `MongoDB\Driver\Monitoring\CommandSubscriber` to capture
 * every driver command (find, insert, update, delete, aggregate, …) with its
 * duration and feed it to a `Database\Log\MongoLogger`. This is the real
 * query-logging path for the Database layer.
 *
 * @see mongodb-odm APM/CommandLogger.php
 */
class CommandSubscriber implements CommandSubscriberInterface
{
    /**
     * The logger commands are forwarded to.
     *
     * @var \Crustum\Mongo\Database\Log\MongoLogger
     */
    protected MongoLogger $logger;

    /**
     * In-flight command metadata keyed by request id.
     *
     * @var array<int|string, array{command: array<string|int, mixed>, database: string}>
     */
    protected array $started = [];

    /**
     * Constructor
     *
     * @param \Crustum\Mongo\Database\Log\MongoLogger $logger The logger to forward commands to.
     */
    public function __construct(MongoLogger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Returns the wrapped logger.
     *
     * @return \Crustum\Mongo\Database\Log\MongoLogger
     */
    public function getLogger(): MongoLogger
    {
        return $this->logger;
    }

    /**
     * Replaces the wrapped logger.
     *
     * @param \Crustum\Mongo\Database\Log\MongoLogger $logger The logger to forward commands to.
     * @return $this
     */
    public function setLogger(MongoLogger $logger): static
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * Registers this subscriber with the driver.
     *
     * @return void
     */
    public function enable(): void
    {
        addSubscriber($this);
    }

    /**
     * Unregisters this subscriber from the driver.
     *
     * @return void
     */
    public function disable(): void
    {
        removeSubscriber($this);
    }

    /**
     * @inheritDoc
     */
    public function commandStarted(CommandStartedEvent $event): void
    {
        $this->started[$event->getRequestId()] = [
            'command' => (array)$event->getCommand(),
            'database' => $event->getDatabaseName(),
        ];
    }

    /**
     * @inheritDoc
     */
    public function commandSucceeded(CommandSucceededEvent $event): void
    {
        $meta = $this->started[$event->getRequestId()] ?? null;
        unset($this->started[$event->getRequestId()]);

        $command = $meta['command'] ?? [];
        $database = $meta['database'] ?? '';

        $this->logger->log(
            LogLevel::DEBUG,
            'mongo command',
            [
                'command' => $command,
                'database' => $database,
                'collection' => $this->extractCollection($command),
                'duration_ms' => round($event->getDurationMicros() / 1000, 4),
                'numReturn' => $this->extractNumReturn($event),
            ],
        );
    }

    /**
     * @inheritDoc
     */
    public function commandFailed(CommandFailedEvent $event): void
    {
        $meta = $this->started[$event->getRequestId()] ?? null;
        unset($this->started[$event->getRequestId()]);

        $command = $meta['command'] ?? [];
        $database = $meta['database'] ?? '';

        $this->logger->log(
            LogLevel::ERROR,
            'mongo command failed',
            [
                'command' => $command,
                'database' => $database,
                'collection' => $this->extractCollection($command),
                'duration_ms' => round($event->getDurationMicros() / 1000, 4),
                'error' => $event->getError()->getMessage(),
            ],
        );
    }

    /**
     * Extracts the collection name from a command document, when present.
     *
     * @param array<string|int, mixed> $command The command document
     * @return string|null The collection name or null
     */
    protected function extractCollection(array $command): ?string
    {
        foreach (['find', 'insert', 'update', 'delete', 'aggregate', 'count', 'distinct'] as $key) {
            if (isset($command[$key]) && is_string($command[$key])) {
                return $command[$key];
            }
        }

        return null;
    }

    /**
     * Extracts the number of returned documents from a successful reply.
     *
     * The driver returns the reply as an object (e.g. `BSONDocument`), so it
     * is normalized to an array before reading.
     *
     * @param \MongoDB\Driver\Monitoring\CommandSucceededEvent $event The succeeded event
     * @return int
     */
    protected function extractNumReturn(CommandSucceededEvent $event): int
    {
        $reply = (array)$event->getReply();
        $cursor = $reply['cursor'] ?? null;

        if (is_array($cursor)) {
            $firstBatch = $cursor['firstBatch'] ?? null;
            if (is_array($firstBatch)) {
                return count($firstBatch);
            }
        }

        foreach (['n', 'nModified', 'ok'] as $key) {
            if (isset($reply[$key]) && is_numeric($reply[$key])) {
                return (int)$reply[$key];
            }
        }

        return 0;
    }
}

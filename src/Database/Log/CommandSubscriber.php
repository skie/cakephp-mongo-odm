<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Log;

use MongoDB\Driver\Monitoring\CommandFailedEvent;
use MongoDB\Driver\Monitoring\CommandStartedEvent;
use MongoDB\Driver\Monitoring\CommandSubscriber as CommandSubscriberInterface;
use MongoDB\Driver\Monitoring\CommandSucceededEvent;
use Psr\Log\LogLevel;
use function MongoDB\Driver\Monitoring\addSubscriber;
use function MongoDB\Driver\Monitoring\removeSubscriber;

/**
 * Registers a MongoLogger with the process-wide command monitor.
 *
 * ext-mongodb `addSubscriber` is global: one shared subscriber receives every
 * command. Each attached MongoLogger is scoped to its driver's configured
 * database so DebugKit / multi-connection setups only record matching traffic
 * (SQL-style per-connection panels).
 */
class CommandSubscriber implements CommandSubscriberInterface
{
    /**
     * Process-wide monitor instance.
     *
     * @var self|null
     */
    protected static ?self $monitor = null;

    /**
     * Active Mongo loggers keyed by object id.
     *
     * @var array<int, \Crustum\Mongo\Database\Log\MongoLogger>
     */
    protected array $loggers = [];

    /**
     * Whether this instance is currently registered with the driver monitor.
     *
     * @var bool
     */
    protected bool $enabled = false;

    /**
     * In-flight command metadata keyed by request id.
     *
     * @var array<int|string, array{command: array<string|int, mixed>, database: string}>
     */
    protected array $started = [];

    /**
     * Attach a MongoLogger to the shared monitor (and enable monitoring).
     *
     * @param \Crustum\Mongo\Database\Log\MongoLogger $logger Logger for one driver.
     * @return self
     */
    public static function attach(MongoLogger $logger): self
    {
        $monitor = self::$monitor ??= new self();
        $monitor->loggers[spl_object_id($logger)] = $logger;
        $monitor->enable();

        return $monitor;
    }

    /**
     * Detach a MongoLogger; disables monitoring when none remain.
     *
     * @param \Crustum\Mongo\Database\Log\MongoLogger $logger Logger to remove.
     * @return void
     */
    public static function detach(MongoLogger $logger): void
    {
        if (!self::$monitor instanceof self) {
            return;
        }

        unset(self::$monitor->loggers[spl_object_id($logger)]);
        if (self::$monitor->loggers === []) {
            self::$monitor->disable();
            self::$monitor = null;
        }
    }

    /**
     * Reset the shared monitor (tests).
     *
     * @return void
     */
    public static function resetMonitor(): void
    {
        if (self::$monitor instanceof self) {
            self::$monitor->disable();
        }

        self::$monitor = null;
    }

    /**
     * Returns the primary/first logger (compatibility for getLogger()).
     *
     * @return \Crustum\Mongo\Database\Log\MongoLogger
     */
    public function getLogger(): MongoLogger
    {
        $logger = $this->loggers === [] ? null : $this->loggers[array_key_first($this->loggers)];
        if (!$logger instanceof MongoLogger) {
            throw new \RuntimeException('CommandSubscriber has no attached MongoLogger.');
        }

        return $logger;
    }

    /**
     * Whether this subscriber is registered with the driver monitor.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Number of attached MongoLoggers (tests / diagnostics).
     *
     * @return int
     */
    public function loggerCount(): int
    {
        return count($this->loggers);
    }

    /**
     * Registers this subscriber with the driver.
     *
     * Idempotent: repeated enable() calls do not add the subscriber twice.
     *
     * @return void
     */
    public function enable(): void
    {
        if ($this->enabled) {
            return;
        }

        addSubscriber($this);
        $this->enabled = true;
    }

    /**
     * Unregisters this subscriber from the driver.
     *
     * Idempotent when already disabled.
     *
     * @return void
     */
    public function disable(): void
    {
        if (!$this->enabled) {
            return;
        }

        removeSubscriber($this);
        $this->enabled = false;
        $this->started = [];
    }

    /**
     * @inheritDoc
     */
    public function commandStarted(CommandStartedEvent $event): void
    {
        if ($this->loggers === []) {
            return;
        }

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

        if ($meta === null || $this->loggers === []) {
            return;
        }

        $command = $meta['command'];
        $database = $meta['database'];
        $context = [
            'command' => $command,
            'database' => $database,
            'collection' => $this->extractCollection($command),
            'duration_ms' => round($event->getDurationMicros() / 1000, 4),
            'numReturn' => $this->extractNumReturn($event),
        ];

        foreach ($this->loggers as $logger) {
            if (!$logger->acceptsDatabase($database)) {
                continue;
            }

            $logger->log(LogLevel::DEBUG, 'mongo command', $context);
        }
    }

    /**
     * @inheritDoc
     */
    public function commandFailed(CommandFailedEvent $event): void
    {
        $meta = $this->started[$event->getRequestId()] ?? null;
        unset($this->started[$event->getRequestId()]);

        if ($meta === null || $this->loggers === []) {
            return;
        }

        $command = $meta['command'];
        $database = $meta['database'];
        $context = [
            'command' => $command,
            'database' => $database,
            'collection' => $this->extractCollection($command),
            'duration_ms' => round($event->getDurationMicros() / 1000, 4),
            'error' => $event->getError()->getMessage(),
        ];

        foreach ($this->loggers as $logger) {
            if (!$logger->acceptsDatabase($database)) {
                continue;
            }

            $logger->log(LogLevel::ERROR, 'mongo command failed', $context);
        }
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

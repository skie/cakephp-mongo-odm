<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Log;

use Cake\Database\Log\LoggedQuery;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Stringable;
use Throwable;

/**
 * Logger for MongoDB driver commands.
 *
 * Command context is normalized, encoded to JSON, wrapped into a
 * `LoggedQuery` when a duration is present, and delegated to the wrapped
 * logger. Exceptions passed in context are rethrown.
 *
 * JSON encoding flags are configurable (`jsonFlags`). Default is compact
 * single-line output; pass `JSON_PRETTY_PRINT` for multi-line.
 */
class MongoLogger extends AbstractLogger
{
    /**
     * The underlying logger.
     *
     * @var \Crustum\Mongo\Database\Log\QueryLogger|\Psr\Log\LoggerInterface
     */
    protected QueryLogger|LoggerInterface $logger;

    /**
     * Flags passed to `json_encode()` for the command payload.
     *
     * @var int
     */
    protected int $jsonFlags;

    /**
     * When set, only commands for this MongoDB database are forwarded.
     *
     * ext-mongodb monitoring is process-global; DebugKit and multi-connection
     * setups attach one logger per Cake connection. Filtering by the driver's
     * configured database keeps each Sql Log panel scoped (like SQL schemas).
     *
     * @var string|null
     */
    protected ?string $database = null;

    /**
     * Whether schema/reflection commands (listCollections, listIndexes, …) are logged.
     *
     * @var bool
     */
    protected bool $includeSchemaCommands = false;

    /**
     * Command keys recognized as the operation name.
     *
     * @var list<string>
     */
    protected const OPERATION_KEYS = [
        'find',
        'insert',
        'update',
        'delete',
        'aggregate',
        'count',
        'distinct',
        'createIndexes',
    ];

    /**
     * Command names treated as schema / catalog reflection (DebugKit-style).
     *
     * @var list<string>
     */
    protected const SCHEMA_COMMANDS = [
        'listCollections',
        'listIndexes',
        'listDatabases',
        'collStats',
        'dbStats',
        'connectionStatus',
        'getParameter',
        'hostInfo',
        'buildInfo',
        'atlasVersion',
        'abortTransaction',
        'commitTransaction',
        'startTransaction',
    ];

    /**
     * Driver/session keys stripped from logged command documents.
     *
     * @var list<string>
     */
    protected const COMMAND_META_KEYS = [
        '$db',
        'lsid',
        '$clusterTime',
        '$readPreference',
        '$session',
    ];

    /**
     * Constructor.
     *
     * ### Options
     *
     * - `jsonFlags` - Bitmask for `json_encode()`. Use `0` (default) for a
     *   single-line payload, or `JSON_PRETTY_PRINT` for multi-line.
     * - `database` - When set, ignore commands for any other database name.
     * - `includeSchemaCommands` - When false (default), skip catalog/reflection
     *   commands so DebugKit / Speculum show application queries only.
     *
     * @param \Crustum\Mongo\Database\Log\QueryLogger|\Psr\Log\LoggerInterface $logger The logger to delegate to.
     * @param array<string, mixed> $config Logger options.
     */
    public function __construct(QueryLogger|LoggerInterface $logger, array $config = [])
    {
        $config += [
            'jsonFlags' => 0,
            'database' => null,
            'includeSchemaCommands' => false,
        ];

        $this->logger = $logger;
        $this->jsonFlags = (int)$config['jsonFlags'];
        $database = $config['database'];
        $this->database = $database === null || $database === ''
            ? null
            : (string)$database;
        $this->includeSchemaCommands = (bool)$config['includeSchemaCommands'];
    }

    /**
     * Returns the wrapped logger.
     *
     * @return \Crustum\Mongo\Database\Log\QueryLogger|\Psr\Log\LoggerInterface
     */
    public function getLogger(): QueryLogger|LoggerInterface
    {
        return $this->logger;
    }

    /**
     * Database this logger is scoped to, if any.
     *
     * @return string|null
     */
    public function getDatabase(): ?string
    {
        return $this->database;
    }

    /**
     * Whether this logger should record a command for the given database.
     *
     * @param string|null $database Command database from APM.
     * @return bool
     */
    public function acceptsDatabase(?string $database): bool
    {
        if ($this->database === null) {
            return true;
        }

        return $database === $this->database;
    }

    /**
     * Whether a command document is schema/catalog reflection.
     *
     * @param array<string|int, mixed> $command Command document.
     * @return bool
     */
    public function isSchemaCommand(array $command): bool
    {
        foreach (self::SCHEMA_COMMANDS as $name) {
            if (array_key_exists($name, $command)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Replaces the wrapped logger.
     *
     * @param \Crustum\Mongo\Database\Log\QueryLogger|\Psr\Log\LoggerInterface $logger The logger to delegate to.
     * @return $this
     */
    public function setLogger(QueryLogger|LoggerInterface $logger): static
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * Returns the JSON encode flags.
     *
     * @return int
     */
    public function getJsonFlags(): int
    {
        return $this->jsonFlags;
    }

    /**
     * Sets the JSON encode flags.
     *
     * @param int $flags Bitmask for `json_encode()`.
     * @return $this
     */
    public function setJsonFlags(int $flags): static
    {
        $this->jsonFlags = $flags;

        return $this;
    }

    /**
     * Logs a MongoDB command record.
     *
     * Signature matches PSR-3 `LoggerInterface::log()`.
     *
     * @param mixed $level The log level.
     * @param \Stringable|string $message The log message.
     * @param array<string, mixed> $context Additional context data.
     * @throws \Throwable When an exception is present in the context.
     * @return void
     */
    public function log(mixed $level, Stringable|string $message, array $context = []): void
    {
        if (!$this->acceptsDatabase(isset($context['database']) ? (string)$context['database'] : null)) {
            return;
        }

        if (
            !$this->includeSchemaCommands
            && isset($context['command'])
            && is_array($context['command'])
            && $this->isSchemaCommand($context['command'])
        ) {
            return;
        }

        $logData = $context;

        if (isset($context['command']) && is_array($context['command'])) {
            $command = $context['command'];
            $logData = $this->formatCommandLog($command, $context);
        }

        $encoded = json_encode($logData, $this->jsonFlags) ?: $message;

        if (isset($context['command'], $context['duration_ms'])) {
            $query = new LoggedQuery();
            $query->setContext([
                'query' => $encoded,
                'took' => $context['duration_ms'],
                'numRows' => $context['numReturn'] ?? 0,
            ]);
            $context['query'] = $query;
        }

        if (isset($context['exception']) && $context['exception'] instanceof Throwable) {
            throw $context['exception'];
        }

        $this->logger->log($level, $encoded, $context);
    }

    /**
     * Builds a log payload that mirrors the driver command document.
     *
     * The previous shape kept only `filter` and `options`, which dropped
     * aggregate pipelines and other operation-specific fields visible in
     * Speculum / APM raw command logs.
     *
     * @param array<string|int, mixed> $command The command document.
     * @param array<string, mixed> $context Subscriber context.
     * @return array<string, mixed>
     */
    protected function formatCommandLog(array $command, array $context): array
    {
        $operation = array_find_key(
            $command,
            fn(mixed $value, string|int $key): bool => is_string($key)
                && in_array($key, static::OPERATION_KEYS, true),
        ) ?? 'command';

        return array_merge(
            [
                'operation' => $operation,
                'database' => $context['database'] ?? '',
                'collection' => $context['collection'] ?? '',
            ],
            $this->sanitizeCommand($command),
        );
    }

    /**
     * Removes driver/session metadata from a command document.
     *
     * @param array<string|int, mixed> $command The command document.
     * @return array<string|int, mixed>
     */
    protected function sanitizeCommand(array $command): array
    {
        $sanitized = [];
        foreach ($command as $key => $value) {
            if (is_string($key) && in_array($key, static::COMMAND_META_KEYS, true)) {
                continue;
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
    }
}

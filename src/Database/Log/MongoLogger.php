<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Log;

use Cake\Database\Log\LoggedQuery;
use Cake\Database\Log\QueryLogger;
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
 */
class MongoLogger extends AbstractLogger
{
    /**
     * The underlying logger.
     *
     * @var \Cake\Database\Log\QueryLogger|\Psr\Log\LoggerInterface
     */
    protected QueryLogger|LoggerInterface $logger;

    /**
     * Command keys recognized as the operation name.
     *
     * @var list<string>
     */
    protected const array OPERATION_KEYS = [
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
     * Constructor.
     *
     * @param \Cake\Database\Log\QueryLogger|\Psr\Log\LoggerInterface $logger The logger to delegate to.
     */
    public function __construct(QueryLogger|LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Returns the wrapped logger.
     *
     * @return \Cake\Database\Log\QueryLogger|\Psr\Log\LoggerInterface
     */
    public function getLogger(): QueryLogger|LoggerInterface
    {
        return $this->logger;
    }

    /**
     * Replaces the wrapped logger.
     *
     * @param \Cake\Database\Log\QueryLogger|\Psr\Log\LoggerInterface $logger The logger to delegate to.
     * @return $this
     */
    public function setLogger(QueryLogger|LoggerInterface $logger): static
    {
        $this->logger = $logger;

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
        $logData = $context;

        if (isset($context['command']) && is_array($context['command'])) {
            $command = $context['command'];
            $operation = array_find_key(
                $command,
                fn(mixed $value, string|int $key): bool => is_string($key)
                    && in_array($key, static::OPERATION_KEYS, true),
            ) ?? 'command';

            $logData = [
                'operation' => $operation,
                'database' => $context['database'] ?? '',
                'collection' => $context['collection'] ?? '',
                'filter' => $command['filter'] ?? [],
                'options' => $context['options'] ?? [],
            ];
        }

        $encoded = json_encode($logData, JSON_PRETTY_PRINT) ?: $message;

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
}

<?php
declare(strict_types=1);

/**
 * Ported and adapted from cakephp/migrations (MIT License).
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc.
 * @license https://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace Crustum\Mongo\Migration\Db\Adapter;

use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Database\Schema\SchemaManager;
use Crustum\Mongo\Migration\Migration\IrreversibleMigrationException;
use Crustum\Mongo\Migration\MigrationInterface;
use Crustum\Mongo\Migration\SeedInterface;
use MongoDB\Collection;

/**
 * Recording proxy adapter.
 *
 * Wraps a migration adapter and records DDL commands so `change()` migrations
 * can be reversed for the `down` direction. Inverse commands are executed in
 * reverse order.
 */
class RecordingAdapter implements AdapterInterface
{
    /**
     * The decorated adapter.
     *
     * @var \Crustum\Mongo\Migration\Db\Adapter\AdapterInterface
     */
    protected AdapterInterface $adapter;

    /**
     * Recorded commands, each `[method, args]`.
     *
     * @var list<array{string, array<int, mixed>}>
     */
    protected array $commands = [];

    /**
     * Constructor.
     *
     * @param \Crustum\Mongo\Migration\Db\Adapter\AdapterInterface $adapter The decorated adapter
     */
    public function __construct(AdapterInterface $adapter)
    {
        $this->adapter = $adapter;
    }

    /**
     * Returns the decorated adapter.
     *
     * @return \Crustum\Mongo\Migration\Db\Adapter\AdapterInterface
     */
    public function getAdapter(): AdapterInterface
    {
        return $this->adapter;
    }

    /**
     * @inheritDoc
     */
    public function getConnection(): Connection
    {
        return $this->adapter->getConnection();
    }

    /**
     * @inheritDoc
     */
    public function getSchemaManager(): SchemaManager
    {
        return $this->adapter->getSchemaManager();
    }

    /**
     * @inheritDoc
     */
    public function getCollection(string $name): Collection
    {
        return $this->adapter->getCollection($name);
    }

    /**
     * @inheritDoc
     */
    public function listCollections(): array
    {
        return $this->adapter->listCollections();
    }

    /**
     * @inheritDoc
     */
    public function hasCollection(string $name): bool
    {
        return $this->adapter->hasCollection($name);
    }

    /**
     * @inheritDoc
     */
    public function createCollection(string $name, array $options = []): void
    {
        $this->commands[] = ['createCollection', [$name, $options]];
    }

    /**
     * @inheritDoc
     */
    public function dropCollection(string $name): void
    {
        $this->commands[] = ['dropCollection', [$name]];
    }

    /**
     * @inheritDoc
     */
    public function renameCollection(string $from, string $to, bool $dropTarget = false): void
    {
        $this->commands[] = ['renameCollection', [$from, $to, $dropTarget]];
    }

    /**
     * @inheritDoc
     */
    public function createIndex(string $name, array|string $key, array $options = []): string
    {
        $indexName = $options['name'] ?? $this->defaultIndexName($key);
        $this->commands[] = ['createIndex', [$name, $indexName, $key]];

        return $indexName;
    }

    /**
     * @inheritDoc
     */
    public function dropIndex(string $name, string $indexName): void
    {
        $this->commands[] = ['dropIndex', [$name, $indexName]];
    }

    /**
     * @inheritDoc
     */
    public function setValidator(
        string $name,
        ?array $validator,
        ?string $validationLevel = null,
        ?string $validationAction = null,
    ): void {
        $this->commands[] = ['setValidator', [$name, $validator, $validationLevel, $validationAction]];
    }

    /**
     * @inheritDoc
     */
    public function getVersions(): array
    {
        return $this->adapter->getVersions();
    }

    /**
     * @inheritDoc
     */
    public function getVersionLog(): array
    {
        return $this->adapter->getVersionLog();
    }

    /**
     * @inheritDoc
     */
    public function migrated(
        MigrationInterface $migration,
        string $direction,
        string $startTime,
        string $endTime,
    ): static {
        $this->adapter->migrated($migration, $direction, $startTime, $endTime);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function unmigrated(MigrationInterface $migration): static
    {
        $this->adapter->unmigrated($migration);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function toggleBreakpoint(MigrationInterface $migration): static
    {
        $this->adapter->toggleBreakpoint($migration);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function setBreakpoint(MigrationInterface $migration): static
    {
        $this->adapter->setBreakpoint($migration);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function unsetBreakpoint(MigrationInterface $migration): static
    {
        $this->adapter->unsetBreakpoint($migration);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function resetAllBreakpoints(): int
    {
        return $this->adapter->resetAllBreakpoints();
    }

    /**
     * @inheritDoc
     */
    public function beginTransaction(): void
    {
        $this->adapter->beginTransaction();
    }

    /**
     * @inheritDoc
     */
    public function commitTransaction(): void
    {
        $this->adapter->commitTransaction();
    }

    /**
     * @inheritDoc
     */
    public function rollbackTransaction(): void
    {
        $this->adapter->rollbackTransaction();
    }

    /**
     * @inheritDoc
     */
    public function hasTransactions(): bool
    {
        return $this->adapter->hasTransactions();
    }

    /**
     * @inheritDoc
     */
    public function getSeedLog(): array
    {
        return $this->adapter->getSeedLog();
    }

    /**
     * @inheritDoc
     */
    public function seedExecuted(SeedInterface $seed, string $executedTime): static
    {
        $this->adapter->seedExecuted($seed, $executedTime);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function removeSeedFromLog(SeedInterface $seed): static
    {
        $this->adapter->removeSeedFromLog($seed);

        return $this;
    }

    /**
     * Executes the recorded commands in reverse.
     *
     * @throws \Crustum\Mongo\Migration\Migration\IrreversibleMigrationException When a recorded command cannot be reversed.
     * @return void
     */
    public function executeInvertedCommands(): void
    {
        foreach (array_reverse($this->commands) as [$method, $args]) {
            $inverse = $this->inverseMethod($method);
            $this->adapter->{$inverse}(...$this->invertArgs($method, $args));
        }
    }

    /**
     * Maps a recorded method to its inverse.
     *
     * @param string $method Recorded method
     * @throws \Crustum\Mongo\Migration\Migration\IrreversibleMigrationException
     * @return string The inverse method
     */
    protected function inverseMethod(string $method): string
    {
        return match ($method) {
            'createCollection' => 'dropCollection',
            'dropCollection' => 'createCollection',
            'renameCollection' => 'renameCollection',
            'createIndex' => 'dropIndex',
            'setValidator' => 'setValidator',
            default => throw new IrreversibleMigrationException(sprintf(
                'Cannot reverse a "%s" command',
                $method,
            )),
        };
    }

    /**
     * Re-orders arguments for the inverse call.
     *
     * @param string $method Recorded method
     * @param array<int, mixed> $args Recorded arguments
     * @return array<int, mixed> Arguments for the inverse call
     */
    protected function invertArgs(string $method, array $args): array
    {
        return match ($method) {
            'renameCollection' => [$args[1], $args[0], $args[2] ?? false],
            'createIndex' => [$args[0], $args[1]],
            'dropCollection' => [$args[0], []],
            'setValidator' => [$args[0], $args[1], $args[2] ?? null, $args[3] ?? null],
            default => $args,
        };
    }

    /**
     * Computes the default index name MongoDB generates for a key map.
     *
     * Mirrors the server-side naming so `createIndex()` can be recorded without
     * touching the database and reversed by name.
     *
     * @param array<string, int|string>|string $key The index key(s)
     * @return string The generated index name
     */
    protected function defaultIndexName(array|string $key): string
    {
        $keys = is_string($key) ? [$key => 1] : $key;

        $parts = [];
        foreach ($keys as $field => $direction) {
            $suffix = match (true) {
                $direction === 1 => '1',
                $direction === -1 => '-1',
                default => (string)$direction,
            };
            $parts[] = $field . '_' . $suffix;
        }

        return implode('_', $parts);
    }
}

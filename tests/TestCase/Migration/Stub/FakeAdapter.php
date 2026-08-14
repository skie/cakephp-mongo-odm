<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Migration\Stub;

use Cake\Datasource\ConnectionManager;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Database\Schema\SchemaManager;
use Crustum\Mongo\Migration\Db\Adapter\AdapterInterface;
use Crustum\Mongo\Migration\MigrationInterface;
use Crustum\Mongo\Migration\SeedInterface;
use MongoDB\Collection;

/**
 * In-memory fake migration adapter.
 *
 * Implements AdapterInterface without touching a database: DDL commands are
 * applied to an in-memory collection map and every method call is recorded in
 * `$calls` so tests can assert the exact adapter surface the engine uses.
 */
class FakeAdapter implements AdapterInterface
{
    /**
     * Recorded method calls, each `[method, args]`.
     *
     * @var list<array{string, array<int, mixed>}>
     */
    public array $calls = [];

    /**
     * In-memory collections: name => ['validator' => ?, 'indexes' => [name => options]].
     *
     * @var array<string, array<string, mixed>>
     */
    public array $collections = [];

    /**
     * Applied migration versions.
     *
     * @var array<int>
     */
    public array $versions = [];

    /**
     * Version log entries keyed by version.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $versionLog = [];

    /**
     * Seed execution log entries.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $seedLog = [];

    /**
     * Breakpoint state per version.
     *
     * @var array<int, int>
     */
    public array $breakpoints = [];

    /**
     * Whether the fake reports transaction support.
     *
     * @var bool
     */
    public bool $transactionSupport = false;

    /**
     * The underlying Mongo connection (used only for getCollection()).
     *
     * @var \Crustum\Mongo\Database\Connection
     */
    protected Connection $connection;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->connection = ConnectionManager::get('test_mongo');
    }

    /**
     * Records a call and returns.
     *
     * @param string $method Method name
     * @param array<int, mixed> $args Arguments
     * @return void
     */
    protected function record(string $method, array $args): void
    {
        $this->calls[] = [$method, $args];
    }

    /**
     * @inheritDoc
     */
    public function getConnection(): Connection
    {
        return $this->connection;
    }

    /**
     * @inheritDoc
     */
    public function getSchemaManager(): SchemaManager
    {
        return new SchemaManager($this->connection);
    }

    /**
     * @inheritDoc
     */
    public function getCollection(string $name): Collection
    {
        return $this->connection->getCollection($name);
    }

    /**
     * @inheritDoc
     */
    public function listCollections(): array
    {
        $this->record('listCollections', func_get_args());

        return array_keys($this->collections);
    }

    /**
     * @inheritDoc
     */
    public function hasCollection(string $name): bool
    {
        $this->record('hasCollection', func_get_args());

        return isset($this->collections[$name]);
    }

    /**
     * @inheritDoc
     */
    public function createCollection(string $name, array $options = []): void
    {
        $this->record('createCollection', func_get_args());
        $this->collections[$name] = [
            'validator' => $options['validator'] ?? null,
            'indexes' => [],
        ];
    }

    /**
     * @inheritDoc
     */
    public function dropCollection(string $name): void
    {
        $this->record('dropCollection', func_get_args());
        unset($this->collections[$name]);
    }

    /**
     * @inheritDoc
     */
    public function renameCollection(string $from, string $to, bool $dropTarget = false): void
    {
        $this->record('renameCollection', func_get_args());
        if (isset($this->collections[$from])) {
            $this->collections[$to] = $this->collections[$from];
            unset($this->collections[$from]);
        }
    }

    /**
     * @inheritDoc
     */
    public function createIndex(string $name, array|string $key, array $options = []): string
    {
        $this->record('createIndex', func_get_args());
        $indexName = $options['name'] ?? (is_string($key) ? $key . '_1' : implode('_', array_map(
            fn(string $field, int|string $dir): string => $field . '_' . $dir,
            array_keys((array)$key),
            (array)$key,
        )));
        $this->collections[$name]['indexes'][$indexName] = ['key' => $key] + $options;

        return $indexName;
    }

    /**
     * @inheritDoc
     */
    public function dropIndex(string $name, string $indexName): void
    {
        $this->record('dropIndex', func_get_args());
        unset($this->collections[$name]['indexes'][$indexName]);
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
        $this->record('setValidator', func_get_args());
        $this->collections[$name]['validator'] = $validator;
    }

    /**
     * @inheritDoc
     */
    public function getVersions(): array
    {
        $this->record('getVersions', func_get_args());
        sort($this->versions);

        return $this->versions;
    }

    /**
     * @inheritDoc
     */
    public function getVersionLog(): array
    {
        $this->record('getVersionLog', func_get_args());

        return $this->versionLog;
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
        $this->record('migrated', func_get_args());
        if ($direction === MigrationInterface::UP) {
            $this->versions[] = $migration->getVersion();
            $this->versionLog[$migration->getVersion()] = [
                'version' => $migration->getVersion(),
                'migration_name' => $migration->getName(),
                'start_time' => $startTime,
                'end_time' => $endTime,
                'breakpoint' => $this->breakpoints[$migration->getVersion()] ?? 0,
            ];
        } else {
            $this->versions = array_values(array_filter(
                $this->versions,
                fn(int $v): bool => $v !== $migration->getVersion(),
            ));
            unset($this->versionLog[$migration->getVersion()]);
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function unmigrated(MigrationInterface $migration): static
    {
        $this->record('unmigrated', func_get_args());
        $this->versions = array_values(array_filter(
            $this->versions,
            fn(int $v): bool => $v !== $migration->getVersion(),
        ));
        unset($this->versionLog[$migration->getVersion()]);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function toggleBreakpoint(MigrationInterface $migration): static
    {
        $this->record('toggleBreakpoint', func_get_args());
        $version = $migration->getVersion();
        $this->breakpoints[$version] = ($this->breakpoints[$version] ?? 0) === 0 ? 1 : 0;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function setBreakpoint(MigrationInterface $migration): static
    {
        $this->record('setBreakpoint', func_get_args());
        $this->breakpoints[$migration->getVersion()] = 1;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function unsetBreakpoint(MigrationInterface $migration): static
    {
        $this->record('unsetBreakpoint', func_get_args());
        $this->breakpoints[$migration->getVersion()] = 0;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function resetAllBreakpoints(): int
    {
        $this->record('resetAllBreakpoints', func_get_args());
        $count = count(array_filter($this->breakpoints, fn(int $state): bool => $state === 1));
        $this->breakpoints = array_map(fn(): int => 0, $this->breakpoints);

        return $count;
    }

    /**
     * @inheritDoc
     */
    public function beginTransaction(): void
    {
        $this->record('beginTransaction', func_get_args());
    }

    /**
     * @inheritDoc
     */
    public function commitTransaction(): void
    {
        $this->record('commitTransaction', func_get_args());
    }

    /**
     * @inheritDoc
     */
    public function rollbackTransaction(): void
    {
        $this->record('rollbackTransaction', func_get_args());
    }

    /**
     * @inheritDoc
     */
    public function hasTransactions(): bool
    {
        $this->record('hasTransactions', func_get_args());

        return $this->transactionSupport;
    }

    /**
     * @inheritDoc
     */
    public function getSeedLog(): array
    {
        $this->record('getSeedLog', func_get_args());

        return $this->seedLog;
    }

    /**
     * @inheritDoc
     */
    public function seedExecuted(SeedInterface $seed, string $executedTime): static
    {
        $this->record('seedExecuted', func_get_args());
        $this->seedLog[] = [
            'seed_name' => $seed->getName(),
            'plugin' => null,
            'executed_at' => $executedTime,
        ];

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function removeSeedFromLog(SeedInterface $seed): static
    {
        $this->record('removeSeedFromLog', func_get_args());
        $this->seedLog = array_values(array_filter(
            $this->seedLog,
            fn(array $entry): bool => $entry['seed_name'] !== $seed->getName(),
        ));

        return $this;
    }
}

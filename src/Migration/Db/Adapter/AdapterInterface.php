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
use Crustum\Mongo\Migration\MigrationInterface;
use Crustum\Mongo\Migration\SeedInterface;
use MongoDB\Collection;

/**
 * Adapter contract for the Mongo migration engine.
 *
 * Replaces the SQL `Migrations\Db\Adapter\AdapterInterface`: instead of SQL
 * statements, DDL is executed through the `SchemaManager` and the migration
 * journal lives in a Mongo `_migrations` collection.
 */
interface AdapterInterface
{
    /**
     * The name of the migration journal collection.
     */
    public const MIGRATION_TABLE = '_migrations';

    /**
     * The name of the seed execution log collection.
     *
     * Analog of the reference `cake_seeds` table: `{ seed_name, plugin,
     * executed_at }` with a unique `(seed_name, plugin)` index.
     */
    public const SEED_TABLE = '_seeds';

    /**
     * Returns the underlying Mongo connection.
     *
     * @return \Crustum\Mongo\Database\Connection
     */
    public function getConnection(): Connection;

    /**
     * Returns the schema manager used for DDL operations.
     *
     * @return \Crustum\Mongo\Database\Schema\SchemaManager
     */
    public function getSchemaManager(): SchemaManager;

    /**
     * Returns a Mongo collection for data operations (used by seeds).
     *
     * @param string $name Collection name
     * @return \MongoDB\Collection
     */
    public function getCollection(string $name): Collection;

    /**
     * Returns the names of all collections in the database.
     *
     * @return list<string>
     */
    public function listCollections(): array;

    /**
     * Checks whether a collection exists.
     *
     * @param string $name Collection name
     * @return bool
     */
    public function hasCollection(string $name): bool;

    /**
     * Creates a collection.
     *
     * @param string $name Collection name
     * @param array<string, mixed> $options Collection options (validator, validationLevel, …)
     * @return void
     */
    public function createCollection(string $name, array $options = []): void;

    /**
     * Drops a collection.
     *
     * @param string $name Collection name
     * @return void
     */
    public function dropCollection(string $name): void;

    /**
     * Renames a collection.
     *
     * @param string $from Current name
     * @param string $to New name
     * @param bool $dropTarget Whether to drop an existing target first
     * @return void
     */
    public function renameCollection(string $from, string $to, bool $dropTarget = false): void;

    /**
     * Creates an index.
     *
     * @param string $name Collection name
     * @param array<string, int|string>|string $key Index key(s)
     * @param array<string, mixed> $options Index options
     * @return string The index name
     */
    public function createIndex(string $name, array|string $key, array $options = []): string;

    /**
     * Drops an index.
     *
     * @param string $name Collection name
     * @param string $indexName Index name
     * @return void
     */
    public function dropIndex(string $name, string $indexName): void;

    /**
     * Sets the validator of a collection.
     *
     * @param string $name Collection name
     * @param array<string, mixed>|null $validator The `$jsonSchema` rules, or null to clear
     * @param string|null $validationLevel Validation level
     * @param string|null $validationAction Validation action
     * @return void
     */
    public function setValidator(
        string $name,
        ?array $validator,
        ?string $validationLevel = null,
        ?string $validationAction = null,
    ): void;

    /**
     * Returns the applied migration versions, ascending.
     *
     * @return array<int>
     */
    public function getVersions(): array;

    /**
     * Returns the full migration log entries, keyed by version.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getVersionLog(): array;

    /**
     * Records a migration as run in the `_migrations` collection.
     *
     * @param \Crustum\Mongo\Migration\MigrationInterface $migration Migration
     * @param string $direction Direction ('up' or 'down')
     * @param string $startTime Start time (Y-m-d H:i:s)
     * @param string $endTime End time (Y-m-d H:i:s)
     * @return $this
     */
    public function migrated(
        MigrationInterface $migration,
        string $direction,
        string $startTime,
        string $endTime,
    ): static;

    /**
     * Removes the record of a migration from the log.
     *
     * @param \Crustum\Mongo\Migration\MigrationInterface $migration Migration
     * @return $this
     */
    public function unmigrated(MigrationInterface $migration): static;

    /**
     * Toggles the breakpoint state of a migration.
     *
     * @param \Crustum\Mongo\Migration\MigrationInterface $migration Migration
     * @return $this
     */
    public function toggleBreakpoint(MigrationInterface $migration): static;

    /**
     * Sets a breakpoint on a migration.
     *
     * @param \Crustum\Mongo\Migration\MigrationInterface $migration Migration
     * @return $this
     */
    public function setBreakpoint(MigrationInterface $migration): static;

    /**
     * Unsets a breakpoint on a migration.
     *
     * @param \Crustum\Mongo\Migration\MigrationInterface $migration Migration
     * @return $this
     */
    public function unsetBreakpoint(MigrationInterface $migration): static;

    /**
     * Resets all breakpoints.
     *
     * @return int The number of breakpoints reset
     */
    public function resetAllBreakpoints(): int;

    /**
     * Begins a transaction (Mongo session).
     *
     * @return void
     */
    public function beginTransaction(): void;

    /**
     * Commits the current transaction.
     *
     * @return void
     */
    public function commitTransaction(): void;

    /**
     * Rolls back the current transaction.
     *
     * @return void
     */
    public function rollbackTransaction(): void;

    /**
     * Whether the adapter supports transactions.
     *
     * @return bool
     */
    public function hasTransactions(): bool;

    /**
     * Returns the seed execution log, ordered by execution time.
     *
     * Each entry: `['seed_name' => string, 'plugin' => ?string,
     * 'executed_at' => string]`.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSeedLog(): array;

    /**
     * Records a seed as executed in the `_seeds` log.
     *
     * @param \Crustum\Mongo\Migration\SeedInterface $seed Seed
     * @param string $executedTime Execution timestamp (Y-m-d H:i:s)
     * @return $this
     */
    public function seedExecuted(SeedInterface $seed, string $executedTime): static;

    /**
     * Removes the execution record of a seed from the `_seeds` log.
     *
     * @param \Crustum\Mongo\Migration\SeedInterface $seed Seed
     * @return $this
     */
    public function removeSeedFromLog(SeedInterface $seed): static;
}

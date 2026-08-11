<?php
declare(strict_types=1);

/**
 * Ported and adapted from cakephp/migrations (MIT License).
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc.
 * @license https://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace Crustum\Mongo\Migration\Adapter;

use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Database\Schema\SchemaManager;
use Crustum\Mongo\Migration\MigrationInterface;
use MongoDB\Collection;

/**
 * Mongo migration adapter.
 *
 * Executes DDL through the `SchemaManager` and keeps the migration journal in
 * a Mongo `_migrations` collection. Replaces the SQL
 * `Migrations\Db\Adapter\AbstractAdapter`.
 */
class CakeMongoAdapter implements AdapterInterface
{
    /**
     * The connection.
     *
     * @var \Crustum\Mongo\Database\Connection
     */
    protected Connection $connection;

    /**
     * The schema manager.
     *
     * @var \Crustum\Mongo\Database\Schema\SchemaManager
     */
    protected SchemaManager $schemaManager;

    /**
     * Whether transactions are available (requires a replica set).
     *
     * @var bool|null
     */
    protected ?bool $transactions = null;

    /**
     * Constructor.
     *
     * @param \Crustum\Mongo\Database\Connection $connection The connection
     */
    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
        $this->schemaManager = new SchemaManager($connection);
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
        return $this->schemaManager;
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
        return $this->schemaManager->listCollections();
    }

    /**
     * @inheritDoc
     */
    public function hasCollection(string $name): bool
    {
        return in_array($name, $this->listCollections(), true);
    }

    /**
     * @inheritDoc
     */
    public function createCollection(string $name, array $options = []): void
    {
        $this->schemaManager->createCollection($name, $options);
    }

    /**
     * @inheritDoc
     */
    public function dropCollection(string $name): void
    {
        $this->schemaManager->dropCollection($name);
    }

    /**
     * @inheritDoc
     */
    public function renameCollection(string $from, string $to, bool $dropTarget = false): void
    {
        $this->schemaManager->renameCollection($from, $to, $dropTarget);
    }

    /**
     * @inheritDoc
     */
    public function createIndex(string $name, array|string $key, array $options = []): string
    {
        return $this->schemaManager->createIndex($name, $key, $options);
    }

    /**
     * @inheritDoc
     */
    public function dropIndex(string $name, string $indexName): void
    {
        $this->schemaManager->dropIndex($name, $indexName);
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
        $this->schemaManager->setValidator($name, $validator, $validationLevel, $validationAction);
    }

    /**
     * @inheritDoc
     */
    public function getVersions(): array
    {
        $versions = [];
        foreach ($this->migrationLog()->find([], ['sort' => ['version' => 1]]) as $entry) {
            $entry = (array)$entry;
            $versions[] = (int)$entry['version'];
        }

        return $versions;
    }

    /**
     * @inheritDoc
     */
    public function getVersionLog(): array
    {
        $log = [];
        foreach ($this->migrationLog()->find([], ['sort' => ['version' => 1]]) as $entry) {
            $entry = (array)$entry;
            $log[(int)$entry['version']] = [
                'version' => (int)$entry['version'],
                'migration_name' => (string)($entry['migration_name'] ?? ''),
                'start_time' => (string)($entry['start_time'] ?? ''),
                'end_time' => (string)($entry['end_time'] ?? ''),
                'breakpoint' => (int)($entry['breakpoint'] ?? 0),
            ];
        }

        return $log;
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
        if ($direction === MigrationInterface::UP) {
            $this->migrationLog()->insertOne([
                'version' => $migration->getVersion(),
                'migration_name' => substr($migration->getName(), 0, 100),
                'start_time' => $startTime,
                'end_time' => $endTime,
                'breakpoint' => 0,
            ]);
        } else {
            $this->migrationLog()->deleteOne(['version' => $migration->getVersion()]);
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function unmigrated(MigrationInterface $migration): static
    {
        $this->migrationLog()->deleteOne(['version' => $migration->getVersion()]);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function toggleBreakpoint(MigrationInterface $migration): static
    {
        $entry = $this->migrationLog()->findOne(['version' => $migration->getVersion()]);
        $entry = $entry !== null ? (array)$entry : [];
        $state = (int)($entry['breakpoint'] ?? 0) === 0 ? 1 : 0;
        $this->migrationLog()->updateOne(
            ['version' => $migration->getVersion()],
            ['$set' => ['breakpoint' => $state]],
        );

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function setBreakpoint(MigrationInterface $migration): static
    {
        $this->migrationLog()->updateOne(
            ['version' => $migration->getVersion()],
            ['$set' => ['breakpoint' => 1]],
        );

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function unsetBreakpoint(MigrationInterface $migration): static
    {
        $this->migrationLog()->updateOne(
            ['version' => $migration->getVersion()],
            ['$set' => ['breakpoint' => 0]],
        );

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function resetAllBreakpoints(): int
    {
        $result = $this->migrationLog()->updateMany(
            ['breakpoint' => 1],
            ['$set' => ['breakpoint' => 0]],
        );

        return $result->getModifiedCount();
    }

    /**
     * @inheritDoc
     */
    public function beginTransaction(): void
    {
        $this->connection->begin();
    }

    /**
     * @inheritDoc
     */
    public function commitTransaction(): void
    {
        $this->connection->commit();
    }

    /**
     * @inheritDoc
     */
    public function rollbackTransaction(): void
    {
        $this->connection->rollback();
    }

    /**
     * @inheritDoc
     */
    public function hasTransactions(): bool
    {
        if ($this->transactions === null) {
            $this->transactions = $this->connection->supportsTransactions();
        }

        return $this->transactions;
    }

    /**
     * Returns the migration journal collection, creating it lazily.
     *
     * @return \MongoDB\Collection
     */
    protected function migrationLog(): Collection
    {
        return $this->connection->getCollection(self::MIGRATION_TABLE);
    }
}

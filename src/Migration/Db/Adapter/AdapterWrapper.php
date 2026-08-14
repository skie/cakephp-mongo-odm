<?php
declare(strict_types=1);

/**
 * Ported and adapted from cakephp/migrations (MIT License).
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc.
 * @license https://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace Crustum\Mongo\Migration\Db\Adapter;

use Cake\Console\ConsoleIo;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Database\Schema\SchemaManager;
use Crustum\Mongo\Migration\MigrationInterface;
use Crustum\Mongo\Migration\SeedInterface;
use MongoDB\Collection;

/**
 * Adapter wrapper.
 *
 * Proxy commands through to another adapter, allowing modification of
 * parameters during calls.
 */
abstract class AdapterWrapper implements WrapperInterface
{
    /**
     * The wrapped adapter.
     *
     * @var \Crustum\Mongo\Migration\Db\Adapter\AdapterInterface
     */
    protected AdapterInterface $adapter;

    /**
     * The console output, when the wrapper reports to it.
     *
     * @var \Cake\Console\ConsoleIo|null
     */
    protected ?ConsoleIo $io = null;

    /**
     * @inheritDoc
     */
    public function __construct(AdapterInterface $adapter)
    {
        $this->setAdapter($adapter);
    }

    /**
     * @inheritDoc
     */
    public function setAdapter(AdapterInterface $adapter): AdapterInterface
    {
        $this->adapter = $adapter;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getAdapter(): AdapterInterface
    {
        return $this->adapter;
    }

    /**
     * Sets the console output.
     *
     * @param \Cake\Console\ConsoleIo $io The console output
     * @return $this
     */
    public function setIo(ConsoleIo $io): static
    {
        $this->io = $io;

        return $this;
    }

    /**
     * Gets the console output.
     *
     * @return \Cake\Console\ConsoleIo|null
     */
    public function getIo(): ?ConsoleIo
    {
        return $this->io;
    }

    /**
     * @inheritDoc
     */
    public function getConnection(): Connection
    {
        return $this->getAdapter()->getConnection();
    }

    /**
     * @inheritDoc
     */
    public function getSchemaManager(): SchemaManager
    {
        return $this->getAdapter()->getSchemaManager();
    }

    /**
     * @inheritDoc
     */
    public function getCollection(string $name): Collection
    {
        return $this->getAdapter()->getCollection($name);
    }

    /**
     * @inheritDoc
     */
    public function listCollections(): array
    {
        return $this->getAdapter()->listCollections();
    }

    /**
     * @inheritDoc
     */
    public function hasCollection(string $name): bool
    {
        return $this->getAdapter()->hasCollection($name);
    }

    /**
     * @inheritDoc
     */
    public function createCollection(string $name, array $options = []): void
    {
        $this->getAdapter()->createCollection($name, $options);
    }

    /**
     * @inheritDoc
     */
    public function dropCollection(string $name): void
    {
        $this->getAdapter()->dropCollection($name);
    }

    /**
     * @inheritDoc
     */
    public function renameCollection(string $from, string $to, bool $dropTarget = false): void
    {
        $this->getAdapter()->renameCollection($from, $to, $dropTarget);
    }

    /**
     * @inheritDoc
     */
    public function createIndex(string $name, array|string $key, array $options = []): string
    {
        return $this->getAdapter()->createIndex($name, $key, $options);
    }

    /**
     * @inheritDoc
     */
    public function dropIndex(string $name, string $indexName): void
    {
        $this->getAdapter()->dropIndex($name, $indexName);
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
        $this->getAdapter()->setValidator($name, $validator, $validationLevel, $validationAction);
    }

    /**
     * @inheritDoc
     */
    public function getVersions(): array
    {
        return $this->getAdapter()->getVersions();
    }

    /**
     * @inheritDoc
     */
    public function getVersionLog(): array
    {
        return $this->getAdapter()->getVersionLog();
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
        $this->getAdapter()->migrated($migration, $direction, $startTime, $endTime);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function unmigrated(MigrationInterface $migration): static
    {
        $this->getAdapter()->unmigrated($migration);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function toggleBreakpoint(MigrationInterface $migration): static
    {
        $this->getAdapter()->toggleBreakpoint($migration);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function setBreakpoint(MigrationInterface $migration): static
    {
        $this->getAdapter()->setBreakpoint($migration);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function unsetBreakpoint(MigrationInterface $migration): static
    {
        $this->getAdapter()->unsetBreakpoint($migration);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function resetAllBreakpoints(): int
    {
        return $this->getAdapter()->resetAllBreakpoints();
    }

    /**
     * @inheritDoc
     */
    public function beginTransaction(): void
    {
        $this->getAdapter()->beginTransaction();
    }

    /**
     * @inheritDoc
     */
    public function commitTransaction(): void
    {
        $this->getAdapter()->commitTransaction();
    }

    /**
     * @inheritDoc
     */
    public function rollbackTransaction(): void
    {
        $this->getAdapter()->rollbackTransaction();
    }

    /**
     * @inheritDoc
     */
    public function hasTransactions(): bool
    {
        return $this->getAdapter()->hasTransactions();
    }

    /**
     * @inheritDoc
     */
    public function getSeedLog(): array
    {
        return $this->getAdapter()->getSeedLog();
    }

    /**
     * @inheritDoc
     */
    public function seedExecuted(SeedInterface $seed, string $executedTime): static
    {
        $this->getAdapter()->seedExecuted($seed, $executedTime);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function removeSeedFromLog(SeedInterface $seed): static
    {
        $this->getAdapter()->removeSeedFromLog($seed);

        return $this;
    }
}

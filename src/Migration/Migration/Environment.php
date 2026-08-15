<?php
declare(strict_types=1);

/**
 * Ported and adapted from cakephp/migrations (MIT License).
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc.
 * @license https://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace Crustum\Mongo\Migration\Migration;

use Cake\Console\ConsoleIo;
use Crustum\Mongo\Migration\Db\Adapter\AdapterInterface;
use Crustum\Mongo\Migration\Db\Adapter\RecordingAdapter;
use Crustum\Mongo\Migration\Config\ConfigInterface;
use RuntimeException;
use Crustum\Mongo\Migration\MigrationInterface;
use Crustum\Mongo\Migration\SeedInterface;

/**
 * Migration environment.
 *
 * Executes migrations and seeds against an adapter, handling transactions and
 * the `change()`/`up()`/`down()` dispatch.
 */
class Environment
{
    /**
     * Environment name.
     *
     * @var string
     */
    protected string $name;

    /**
     * Environment options.
     *
     * @var array<string, mixed>
     */
    protected array $options;

    /**
     * The ConsoleIo instance.
     *
     * @var \Cake\Console\ConsoleIo|null
     */
    protected ?ConsoleIo $io = null;

    /**
     * The current version.
     *
     * @var int
     */
    protected int $currentVersion = 0;

    /**
     * The migration journal collection name.
     *
     * @var string
     */
    protected string $migrationTable = 'cake_migrations';

    /**
     * The adapter.
     *
     * @var \Crustum\Mongo\Migration\Db\Adapter\AdapterInterface|null
     */
    protected ?AdapterInterface $adapter = null;

    /**
     * The config.
     *
     * @var \Crustum\Mongo\Migration\Config\ConfigInterface|null
     */
    protected ?ConfigInterface $config = null;

    /**
     * Constructor.
     *
     * @param string $name Environment name
     * @param array<string, mixed> $options Options
     */
    public function __construct(string $name, array $options)
    {
        $this->name = $name;
        $this->options = $options;
    }

    /**
     * Executes the specified migration on this environment.
     *
     * @param \Crustum\Mongo\Migration\MigrationInterface $migration Migration
     * @param string $direction Direction
     * @param bool $fake Flag that if true, we just record running the migration, but not actually do the migration
     * @return void
     */
    public function executeMigration(MigrationInterface $migration, string $direction = MigrationInterface::UP, bool $fake = false): void
    {
        $direction = $direction === MigrationInterface::UP ? MigrationInterface::UP : MigrationInterface::DOWN;
        $migration->setMigratingUp($direction === MigrationInterface::UP);

        $startTime = time();

        $adapter = $this->getAdapter();
        $migration->setAdapter($adapter);

        $migration->preFlightCheck();

        if (method_exists($migration, MigrationInterface::INIT)) {
            $migration->{MigrationInterface::INIT}();
        }

        $atomic = $migration->useTransactions();
        if ($atomic) {
            $adapter->beginTransaction();
        }

        if (!$fake) {
            if (method_exists($migration, MigrationInterface::CHANGE)) {
                if ($direction === MigrationInterface::DOWN) {
                    $recordAdapter = new RecordingAdapter($adapter);
                    $migration->setAdapter($recordAdapter);

                    $migration->{MigrationInterface::CHANGE}();
                    $recordAdapter->executeInvertedCommands();

                    $migration->setAdapter($adapter);
                } else {
                    $migration->{MigrationInterface::CHANGE}();
                }
            } elseif (method_exists($migration, $direction)) {
                $migration->{$direction}();
            }
        }

        $adapter->migrated($migration, $direction, date('Y-m-d H:i:s', $startTime), date('Y-m-d H:i:s', time()));

        if ($atomic) {
            $adapter->commitTransaction();
        }

        $migration->postFlightCheck();
    }

    /**
     * Executes the specified seeder on this environment.
     *
     * @param \Crustum\Mongo\Migration\SeedInterface $seed Seed
     * @return void
     */
    public function executeSeed(SeedInterface $seed): void
    {
        $adapter = $this->getAdapter();
        $seed->setAdapter($adapter);
        if (method_exists($seed, SeedInterface::INIT)) {
            $seed->{SeedInterface::INIT}();
        }

        $atomic = $adapter->hasTransactions();
        if ($atomic) {
            $adapter->beginTransaction();
        }

        $seed->{SeedInterface::RUN}();

        // Record the seed execution. Idempotent seeds always run and re-record
        // their timestamp; non-idempotent seeds run once (the manager skips
        // already-executed ones) and keep their first record.
        if ($seed->isIdempotent()) {
            $adapter->removeSeedFromLog($seed);
        }
        $adapter->seedExecuted($seed, date('Y-m-d H:i:s'));

        if ($atomic) {
            $adapter->commitTransaction();
        }
    }

    /**
     * Sets the environment name.
     *
     * @param string $name Environment name
     * @return $this
     */
    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Gets the environment name.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Sets the environment options.
     *
     * @param array<string, mixed> $options Environment options
     * @return $this
     */
    public function setOptions(array $options): static
    {
        $this->options = $options;

        return $this;
    }

    /**
     * Gets the environment options.
     *
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Sets the console IO.
     *
     * @param \Cake\Console\ConsoleIo $io Console IO
     * @return $this
     */
    public function setIo(ConsoleIo $io): static
    {
        $this->io = $io;

        return $this;
    }

    /**
     * Gets the console IO.
     *
     * @return \Cake\Console\ConsoleIo|null
     */
    public function getIo(): ?ConsoleIo
    {
        return $this->io;
    }

    /**
     * Gets all migrated version numbers.
     *
     * @return array<int>
     */
    public function getVersions(): array
    {
        return $this->getAdapter()->getVersions();
    }

    /**
     * Gets all migration log entries keyed by version.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getVersionLog(): array
    {
        return $this->getAdapter()->getVersionLog();
    }

    /**
     * Sets the current version of the environment.
     *
     * @param int $version Environment version
     * @return $this
     */
    public function setCurrentVersion(int $version): static
    {
        $this->currentVersion = $version;

        return $this;
    }

    /**
     * Gets the current version of the environment.
     *
     * @return int
     */
    public function getCurrentVersion(): int
    {
        $versions = $this->getVersions();
        $version = 0;
        if ($versions !== []) {
            $version = end($versions);
        }

        $this->setCurrentVersion($version);

        return $this->currentVersion;
    }

    /**
     * Sets the database adapter.
     *
     * @param \Crustum\Mongo\Migration\Db\Adapter\AdapterInterface $adapter Database adapter
     * @return $this
     */
    public function setAdapter(AdapterInterface $adapter): static
    {
        $this->adapter = $adapter;

        return $this;
    }

    /**
     * Gets the database adapter.
     *
     * @throws \RuntimeException
     * @return \Crustum\Mongo\Migration\Db\Adapter\AdapterInterface
     */
    public function getAdapter(): AdapterInterface
    {
        if ($this->adapter instanceof AdapterInterface) {
            return $this->adapter;
        }

        if (!$this->config instanceof ConfigInterface) {
            throw new RuntimeException('No config defined for the environment.');
        }

        $factory = new ManagerFactory([]);
        $adapter = $factory->createAdapter($this->getOptions());
        $this->setAdapter($adapter);

        return $adapter;
    }

    /**
     * Sets the config.
     *
     * @param \Crustum\Mongo\Migration\Config\ConfigInterface $config Config
     * @return $this
     */
    public function setConfig(ConfigInterface $config): static
    {
        $this->config = $config;

        return $this;
    }

    /**
     * Gets the config.
     *
     * @return \Crustum\Mongo\Migration\Config\ConfigInterface|null
     */
    public function getConfig(): ?ConfigInterface
    {
        return $this->config;
    }

    /**
     * Sets the migration journal collection name.
     *
     * @param string $migrationTable Collection name
     * @return $this
     */
    public function setMigrationTable(string $migrationTable): static
    {
        $this->migrationTable = $migrationTable;

        return $this;
    }

    /**
     * Gets the migration journal collection name.
     *
     * @return string
     */
    public function getMigrationTable(): string
    {
        return $this->migrationTable;
    }
}

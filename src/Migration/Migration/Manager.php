<?php
declare(strict_types=1);

/**
 * Ported and adapted from cakephp/migrations (MIT License).
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc.
 * @license https://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace Crustum\Mongo\Migration\Migration;

use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Crustum\Mongo\Migration\Config\ConfigInterface;
use DateTime;
use Exception;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Crustum\Mongo\Migration\MigrationInterface;
use Crustum\Mongo\Migration\SeedInterface;
use Crustum\Mongo\Migration\Util\Util;

/**
 * Migration manager.
 *
 * Ported from cakephp/migrations `Migration\Manager` with the SQL layer
 * replaced by the Mongo adapter. Storage-agnostic: works on `MigrationInterface`
 * and `SeedInterface` objects, tracked in the environment's journal.
 */
class Manager
{
    public const BREAKPOINT_TOGGLE = 1;

    public const BREAKPOINT_SET = 2;

    public const BREAKPOINT_UNSET = 3;

    protected ConfigInterface $config;

    protected ConsoleIo $io;

    protected ?Environment $environment = null;

    /**
     * @var array<int, \Crustum\Mongo\Migration\MigrationInterface>|null
     */
    protected ?array $migrations = null;

    /**
     * @var array<string, \Crustum\Mongo\Migration\SeedInterface>|null
     */
    protected ?array $seeds = null;

    protected ?ContainerInterface $container = null;

    /**
     * Constructor.
     *
     * @param \Crustum\Mongo\Migration\Config\ConfigInterface $config Configuration object
     * @param \Cake\Console\ConsoleIo $io Console input/output
     */
    public function __construct(ConfigInterface $config, ConsoleIo $io)
    {
        $this->setConfig($config);
        $this->setIo($io);
    }

    /**
     * Prints the specified environment's migration status.
     *
     * @param string|null $format Format to print status in (either text, json, or null)
     * @return array<int, array<string, mixed>> Array indicating if there are any missing or down migrations
     */
    public function printStatus(?string $format = null): array
    {
        $migrations = [];
        $defaultMigrations = $this->getMigrations();
        if ($defaultMigrations !== []) {
            $env = $this->getEnvironment();
            $versions = $env->getVersionLog();

            foreach ($defaultMigrations as $migration) {
                if (array_key_exists($migration->getVersion(), $versions)) {
                    $status = 'up';
                    unset($versions[$migration->getVersion()]);
                } else {
                    $status = 'down';
                }

                $migrations[$migration->getVersion()] = [
                    'status' => $status,
                    'id' => $migration->getVersion(),
                    'name' => $migration->getName(),
                ];
            }

            foreach ($versions as $missing) {
                $migrationParams = [
                    'status' => 'up',
                    'id' => (int)$missing['version'],
                    'name' => (string)$missing['migration_name'],
                ];
                if ($format !== 'json') {
                    $migrationParams = ['missing' => true] + $migrationParams;
                }

                $migrations[(int)$missing['version']] = $migrationParams;
            }
        }

        ksort($migrations);

        return array_values($migrations);
    }

    /**
     * Migrate to the version of the database on a given date.
     *
     * @param \DateTime $dateTime Date to migrate to
     * @param bool $fake Flag that if true, we just record running the migration, but not actually do the migration
     * @return void
     */
    public function migrateToDateTime(DateTime $dateTime, bool $fake = false): void
    {
        $versions = array_keys($this->getMigrations());
        $dateString = $dateTime->format('Ymdhis');
        $versionToMigrate = null;
        foreach ($versions as $version) {
            if ($dateString > (string)$version) {
                $versionToMigrate = $version;
            }
        }

        $io = $this->getIo();
        if ($versionToMigrate === null) {
            $io->out('No migrations to run');

            return;
        }

        $io->out('Migrating to version ' . $versionToMigrate);
        $this->migrate($versionToMigrate, $fake);
    }

    /**
     * Rollbacks to the version of the database on a given date.
     *
     * @param \DateTime $dateTime Date to rollback to
     * @param bool $force Force
     * @return void
     */
    public function rollbackToDateTime(DateTime $dateTime, bool $force = false): void
    {
        $env = $this->getEnvironment();
        $versions = $env->getVersions();
        $dateString = $dateTime->format('Ymdhis');
        sort($versions);
        $versions = array_reverse($versions);

        if (!$versions || $dateString > (string)$versions[0]) {
            $this->getIo()->out('No migrations to rollback');

            return;
        }

        if ($dateString < (string)end($versions)) {
            $this->getIo()->out('Rolling back all migrations');
            $this->rollback(0);

            return;
        }

        $index = 0;
        foreach ($versions as $index => $version) {
            if ($dateString > (string)$version) {
                break;
            }
        }

        $versionToRollback = $versions[$index];

        $this->getIo()->out('Rolling back to version ' . $versionToRollback);
        $this->rollback($versionToRollback, $force);
    }

    /**
     * Checks if the migration with version number $version has already been marked migrated.
     *
     * @param int $version Version number of the migration to check
     * @return bool
     */
    public function isMigrated(int $version): bool
    {
        $versions = array_flip($this->getEnvironment()->getAdapter()->getVersions());

        return isset($versions[$version]);
    }

    /**
     * Check if a seed has been executed.
     *
     * Seeds are not persisted to a separate log in the Mongo adapter; the
     * journal tracks migrations only. Seeds run per invocation.
     *
     * @param \Crustum\Mongo\Migration\SeedInterface $seed Seed to check
     * @return bool
     */
    public function isSeedExecuted(SeedInterface $seed): bool
    {
        return false;
    }

    /**
     * Get dependencies of a seed that have not been executed yet.
     *
     * @param \Crustum\Mongo\Migration\SeedInterface $seed Seed to check dependencies for
     * @return array<int, \Crustum\Mongo\Migration\SeedInterface>
     */
    public function getSeedDependenciesNotExecuted(SeedInterface $seed): array
    {
        $dependencies = $seed->getDependencies();
        if ($dependencies === []) {
            return [];
        }

        $seeds = $this->getSeeds();
        $notExecuted = [];
        foreach ($dependencies as $depName) {
            $normalizedName = $this->normalizeSeedName($depName, $seeds);
            if ($normalizedName !== null && isset($seeds[$normalizedName]) && !$this->isSeedExecuted($seeds[$normalizedName])) {
                $notExecuted[] = $seeds[$normalizedName];
            }
        }

        return $notExecuted;
    }

    /**
     * Marks migration with version number $version migrated.
     *
     * @param int $version Version number of the migration to check
     * @param string $path Path where the migration file is located
     * @return bool True if success
     */
    public function markMigrated(int $version, string $path): bool
    {
        $adapter = $this->getEnvironment()->getAdapter();

        $migrationFile = glob($path . DS . $version . '*');
        if (!$migrationFile) {
            throw new RuntimeException(
                sprintf('A migration file matching version number `%s` could not be found', $version),
            );
        }

        $migrationFile = $migrationFile[0];
        $className = $this->getMigrationClassName($migrationFile);

        require_once $migrationFile;

        if (!class_exists($className)) {
            throw new RuntimeException(
                sprintf('Could not find class `%s` in file `%s`.', $className, $migrationFile),
            );
        }

        $migration = new $className($version);

        if (!$migration instanceof MigrationInterface) {
            throw new RuntimeException(
                sprintf('Migration class `%s` must implement %s.', $className, MigrationInterface::class),
            );
        }

        $config = $this->getConfig();
        $migration->setConfig($config);

        $time = date('Y-m-d H:i:s', time());
        $adapter->migrated($migration, 'up', $time, $time);

        return true;
    }

    /**
     * Resolves a migration class name based on $path.
     *
     * @param string $path Path to the migration file of which we want the class name
     * @return string Migration class name
     */
    protected function getMigrationClassName(string $path): string
    {
        $class = (string)preg_replace('/^\d+_/', '', basename($path));
        $class = str_replace('_', ' ', $class);
        $class = ucwords($class);
        $class = str_replace(' ', '', $class);

        $dotPos = strpos($class, '.');
        if ($dotPos !== false) {
            return substr($class, 0, $dotPos);
        }

        return $class;
    }

    /**
     * Decides which versions it should mark as migrated.
     *
     * @param \Cake\Console\Arguments $args Console arguments
     * @return array<int> Array of versions that should be marked as migrated
     */
    public function getVersionsToMark(Arguments $args): array
    {
        $migrations = $this->getMigrations();
        $versions = array_keys($migrations);

        $versionArg = $args->hasArgument('version') ? $args->getArgument('version') : null;
        $targetArg = $args->getOption('target');
        $hasAllVersion = in_array($versionArg, ['all', '*'], true);
        if ((!$versionArg && !$targetArg) || $hasAllVersion) {
            return $versions;
        }

        $version = (int)($targetArg ?: $versionArg);

        if ($args->getOption('only') || $versionArg) {
            if (!in_array($version, $versions, true)) {
                throw new InvalidArgumentException(sprintf('Migration `%d` was not found !', $version));
            }

            return [$version];
        }

        $lengthIncrease = $args->getOption('exclude') ? 0 : 1;
        $index = array_search($version, $versions, true);
        if ($index === false) {
            throw new InvalidArgumentException(sprintf('Migration `%d` was not found !', $version));
        }

        return array_slice($versions, 0, $index + $lengthIncrease);
    }

    /**
     * Mark all migrations in $versions array found in $path as migrated.
     *
     * @param string $path Path where to look for migrations
     * @param array<int> $versions Versions which should be marked
     * @return list<string> Output from the operation
     */
    public function markVersionsAsMigrated(string $path, array $versions): array
    {
        $adapter = $this->getEnvironment()->getAdapter();
        $out = [];

        if ($versions === []) {
            $out[] = '<info>No migrations were found. Nothing to mark as migrated.</info>';

            return $out;
        }

        $adapter->beginTransaction();
        foreach ($versions as $version) {
            if ($this->isMigrated($version)) {
                $out[] = sprintf('<info>Skipping migration `%s` (already migrated).</info>', $version);
                continue;
            }

            try {
                $this->markMigrated($version, $path);
                $out[] = sprintf('<info>Migration `%s` successfully marked migrated !</info>', $version);
            } catch (Exception $e) {
                $adapter->rollbackTransaction();
                $out[] = sprintf(
                    '<error>An error occurred while marking migration `%s` as migrated : %s</error>',
                    $version,
                    $e->getMessage(),
                );
                $out[] = '<error>All marked migrations during this process were unmarked.</error>';

                return $out;
            }
        }

        $adapter->commitTransaction();

        return $out;
    }

    /**
     * Migrate an environment to the specified version or by count of migrations.
     *
     * @param int|null $version Version to migrate to
     * @param bool $fake Flag that if true, we just record running the migration, but not actually do the migration
     * @param int|null $count Number of migrations to run, all migrations will be run if not set and no version is given
     * @return void
     */
    public function migrate(?int $version = null, bool $fake = false, ?int $count = null): void
    {
        $migrations = $this->getMigrations();
        $env = $this->getEnvironment();
        $versions = $env->getVersions();
        $current = $env->getCurrentVersion();

        if (!$versions && !$migrations) {
            return;
        }

        if ($version === null) {
            $candidates = [...$versions, ...array_keys($migrations)];
            $version = $candidates !== [] ? max($candidates) : 0;
        } elseif ($version !== 0 && !isset($migrations[$version])) {
            $this->getIo()->out(sprintf(
                '<comment>warning</comment> %s is not a valid version',
                $version,
            ));

            return;
        }

        $direction = $version > $current ? MigrationInterface::UP : MigrationInterface::DOWN;

        if ($direction === MigrationInterface::DOWN) {
            krsort($migrations);
            foreach ($migrations as $migration) {
                if ($migration->getVersion() <= $version) {
                    break;
                }

                if (in_array($migration->getVersion(), $versions, true)) {
                    $this->executeMigration($migration, MigrationInterface::DOWN, $fake);
                }
            }
        }

        ksort($migrations);
        $done = 0;
        foreach ($migrations as $migration) {
            if ($migration->getVersion() > $version || ($count && $done >= $count)) {
                break;
            }

            if (!in_array($migration->getVersion(), $versions, true)) {
                $this->executeMigration($migration, MigrationInterface::UP, $fake);
                $done++;
            }
        }
    }

    /**
     * Execute a migration against the specified environment.
     *
     * @param \Crustum\Mongo\Migration\MigrationInterface $migration Migration
     * @param string $direction Direction
     * @param bool $fake Flag that if true, we just record running the migration, but not actually do the migration
     * @return void
     */
    public function executeMigration(MigrationInterface $migration, string $direction = MigrationInterface::UP, bool $fake = false): void
    {
        $this->getIo()->out('');

        $migration->setAdapter($this->getEnvironment()->getAdapter());

        if (!$migration->shouldExecute()) {
            $this->printMigrationStatus($migration, 'skipped');

            return;
        }

        $this->printMigrationStatus($migration, ($direction === MigrationInterface::UP ? 'migrating' : 'reverting'));

        $start = microtime(true);
        $this->getEnvironment()->executeMigration($migration, $direction, $fake);
        $end = microtime(true);

        $this->printMigrationStatus(
            $migration,
            ($direction === MigrationInterface::UP ? 'migrated' : 'reverted'),
            sprintf('%.4fs', $end - $start),
        );
    }

    /**
     * Execute a seeder against the specified environment.
     *
     * @param \Crustum\Mongo\Migration\SeedInterface $seed Seed
     * @param bool $force Force re-execution even if seed has already been executed
     * @param bool $fake Record seed as executed without actually running it
     * @return void
     */
    public function executeSeed(SeedInterface $seed, bool $force = false, bool $fake = false): void
    {
        $seed->setAdapter($this->getEnvironment()->getAdapter());

        if (!$seed->shouldExecute()) {
            $this->getIo()->out('');
            $this->printSeedStatus($seed, 'skipped');

            return;
        }

        if (!$force && !$seed->isIdempotent() && $this->isSeedExecuted($seed)) {
            return;
        }

        $this->getIo()->out('');

        if ($fake) {
            $this->printSeedStatus($seed, 'faking');

            $this->printSeedStatus($seed, 'faked');

            return;
        }

        $missingDeps = $this->getSeedDependenciesNotExecuted($seed);
        foreach ($missingDeps as $depSeed) {
            $this->getIo()->verbose(sprintf('  Auto-executing dependency: %s', $depSeed->getName()));
            $this->executeSeed($depSeed, $force, $fake);
        }

        $this->printSeedStatus($seed, 'seeding');

        $start = microtime(true);
        $this->getEnvironment()->executeSeed($seed);
        $end = microtime(true);

        $this->printSeedStatus(
            $seed,
            'seeded',
            sprintf('%.4fs', $end - $start),
        );
    }

    /**
     * Print migration status.
     *
     * @param \Crustum\Mongo\Migration\MigrationInterface $migration Migration
     * @param string $status Status of the migration
     * @param string|null $duration Duration the migration took to be executed
     * @return void
     */
    protected function printMigrationStatus(MigrationInterface $migration, string $status, ?string $duration = null): void
    {
        $this->printStatusOutput(
            $migration->getVersion() . ' ' . $migration->getName(),
            $status,
            $duration,
        );
    }

    /**
     * Print seed status.
     *
     * @param \Crustum\Mongo\Migration\SeedInterface $seed Seed
     * @param string $status Status of the seed
     * @param string|null $duration Duration the seed took to be executed
     * @return void
     */
    protected function printSeedStatus(SeedInterface $seed, string $status, ?string $duration = null): void
    {
        $this->printStatusOutput(
            $this->normalizeSeedDisplayName($seed->getName()) . ' seed',
            $status,
            $duration,
        );
    }

    /**
     * Print status output.
     *
     * @param string $name Name of the migration or seed
     * @param string $status Status of the migration or seed
     * @param string|null $duration Duration the migration or seed took to be executed
     * @return void
     */
    protected function printStatusOutput(string $name, string $status, ?string $duration = null): void
    {
        $this->getIo()->out(
            ' ==' .
            ' <info>' . $name . ':</info>' .
            ' <comment>' . $status . ' ' . $duration . '</comment>',
        );
    }

    /**
     * Rollback an environment by a specific count of migrations.
     *
     * @param int $count Count
     * @param bool $force Force
     * @param bool $fake Flag that if true, we just record running the migration, but not actually do the migration
     * @return void
     */
    public function rollbackByCount(int $count, bool $force = false, bool $fake = false): void
    {
        $executedVersions = $this->getEnvironment()->getVersionLog();

        $total = count($executedVersions);
        $pos = 0;
        while ($pos < $count && $pos < $total) {
            array_pop($executedVersions);
            $pos++;
        }

        if ($executedVersions !== []) {
            $last = end($executedVersions);
            $target = (int)$last['version'];
        } else {
            $target = 0;
        }

        $this->rollback($target, $force, false, $fake);
    }

    /**
     * Rollback an environment to the specified version.
     *
     * @param string|int|null $target Target
     * @param bool $force Force
     * @param bool $targetMustMatchVersion Target must match version
     * @param bool $fake Flag that if true, we just record running the migration, but not actually do the migration
     * @return void
     */
    public function rollback(int|string|null $target = null, bool $force = false, bool $targetMustMatchVersion = true, bool $fake = false): void
    {
        $migrations = $this->getMigrations();
        $executedVersions = $this->getEnvironment()->getVersionLog();
        $sortedMigrations = [];
        $io = $this->getIo();

        foreach (array_keys($executedVersions) as &$versionCreationTime) {
            if (isset($migrations[$versionCreationTime])) {
                array_unshift($sortedMigrations, $migrations[$versionCreationTime]);
            } else {
                unset($executedVersions[$versionCreationTime]);
            }
        }

        if ($target === 'all' || $target === '0') {
            $target = 0;
        } elseif (!is_numeric($target) && $target !== null) {
            $migrationNames = array_map(fn(array $item): mixed => $item['migration_name'], $executedVersions);
            $found = array_search($target, $migrationNames, true);
            if ($found !== false) {
                $target = (string)$found;
            } else {
                $io->out(sprintf('<error>No migration found with name (%s)</error>', $target));

                return;
            }
        }

        $executedVersionCreationTimes = array_keys($executedVersions);
        if (!$executedVersionCreationTimes || $target === end($executedVersionCreationTimes)) {
            $io->out('<error>No migrations to rollback</error>');

            return;
        }

        if ($target === null) {
            $prev = count($executedVersionCreationTimes) - 2;
            $target = $prev >= 0 ? $executedVersionCreationTimes[$prev] : 0;
        }

        if ($targetMustMatchVersion && $target !== 0 && !isset($migrations[$target])) {
            $io->out(sprintf('<error>Target version (%s) not found</error>', $target));

            return;
        }

        $rollbacked = false;

        foreach ($sortedMigrations as $migration) {
            if ($targetMustMatchVersion && $migration->getVersion() == $target) {
                break;
            }

            if (in_array($migration->getVersion(), $executedVersionCreationTimes, true)) {
                $executedArray = $executedVersions[$migration->getVersion()];

                if (
                    !$targetMustMatchVersion
                    && ($this->getConfig()->isVersionOrderCreationTime()
                        ? (int)$executedArray['version'] <= $target
                        : (string)$executedArray['start_time'] <= $target)
                ) {
                    break;
                }

                if ((int)$executedArray['breakpoint'] !== 0 && !$force) {
                    $io->out('<error>Breakpoint reached. Further rollbacks inhibited.</error>');
                    break;
                }

                $this->executeMigration($migration, MigrationInterface::DOWN, $fake);
                $rollbacked = true;
            }
        }

        if (!$rollbacked) {
            $this->getIo()->out('<error>No migrations to rollback</error>');
        }
    }

    /**
     * Run database seeders against an environment.
     *
     * @param string|null $seed Seeder
     * @param bool $force Force re-execution even if seed has already been executed
     * @param bool $fake Record seed as executed without actually running it
     * @throws \InvalidArgumentException
     * @return void
     */
    public function seed(?string $seed = null, bool $force = false, bool $fake = false): void
    {
        $seeds = $this->getSeeds();

        if ($seed === null) {
            foreach ($seeds as $seeder) {
                $this->executeSeed($seeder, $force, $fake);
            }
        } else {
            $normalizedName = $this->normalizeSeedName($seed, $seeds);
            if ($normalizedName !== null) {
                $this->executeSeed($seeds[$normalizedName], $force, $fake);
            } else {
                throw new InvalidArgumentException(sprintf('The seed `%s` does not exist', $seed));
            }
        }
    }

    /**
     * Gets the manager class for the given environment.
     *
     * @return \Crustum\Mongo\Migration\Migration\Environment
     */
    public function getEnvironment(): Environment
    {
        if ($this->environment instanceof Environment) {
            return $this->environment;
        }

        $config = $this->getConfig();
        $envOptions = $config->getEnvironment();
        $environment = new Environment('default', $envOptions ?? []);
        $environment->setIo($this->getIo());
        $environment->setConfig($config);
        $this->environment = $environment;

        return $environment;
    }

    /**
     * Set the io instance.
     *
     * @param \Cake\Console\ConsoleIo $io The io instance to use
     * @return $this
     */
    public function setIo(ConsoleIo $io): static
    {
        $this->io = $io;

        return $this;
    }

    /**
     * Get the io instance.
     *
     * @return \Cake\Console\ConsoleIo
     */
    public function getIo(): ConsoleIo
    {
        return $this->io;
    }

    /**
     * Replace the environment.
     *
     * @param \Crustum\Mongo\Migration\Migration\Environment $environment Environment
     * @return $this
     */
    public function setEnvironment(Environment $environment): static
    {
        $this->environment = $environment;

        return $this;
    }

    /**
     * Sets the user defined PSR-11 container.
     *
     * @param \Psr\Container\ContainerInterface $container Container
     * @return $this
     */
    public function setContainer(ContainerInterface $container): static
    {
        $this->container = $container;

        return $this;
    }

    /**
     * Sets the database migrations.
     *
     * @param array<int, \Crustum\Mongo\Migration\MigrationInterface> $migrations Migrations
     * @return $this
     */
    public function setMigrations(array $migrations): static
    {
        $this->migrations = $migrations;

        return $this;
    }

    /**
     * Gets an array of the database migrations, indexed by migration version and
     * sorted in ascending order.
     *
     * @return array<int, \Crustum\Mongo\Migration\MigrationInterface>
     */
    public function getMigrations(): array
    {
        if ($this->migrations === null) {
            $phpFiles = $this->getMigrationFiles();

            $io = $this->getIo();
            $io->verbose('Migration file');
            $io->verbose(array_map(
                fn(string $phpFile): string => sprintf('    <info>%s</info>', $phpFile),
                $phpFiles,
            ));

            $fileNames = [];
            $versions = [];

            foreach ($phpFiles as $filePath) {
                if (Util::isValidMigrationFileName(basename($filePath))) {
                    $io->verbose(sprintf('Valid migration file <info>%s</info>.', $filePath));

                    $version = Util::getVersionFromFileName(basename($filePath));

                    if (isset($versions[$version])) {
                        throw new InvalidArgumentException(sprintf('Duplicate migration - "%s" has the same version as "%s"', $filePath, $versions[$version]->getVersion()));
                    }

                    $class = Util::mapFileNameToClassName(basename($filePath));

                    if (isset($fileNames[$class])) {
                        throw new InvalidArgumentException(sprintf(
                            'Migration "%s" has the same name as "%s"',
                            basename($filePath),
                            $fileNames[$class],
                        ));
                    }

                    $fileNames[$class] = basename($filePath);

                    $io->verbose(sprintf('Loading class <info>%s</info> from <info>%s</info>.', $class, $filePath));

                    $this->checkMigrationClass($filePath);

                    $origDisplayErrors = ini_get('display_errors');
                    ini_set('display_errors', 'On');

                    // Anonymous-class files return the migration instance from
                    // `require`; named classes are required once and constructed.
                    $migrationInstance = null;
                    if (!class_exists($class)) {
                        $migrationInstance = require $filePath;
                    } else {
                        require_once $filePath;
                    }

                    ini_set('display_errors', $origDisplayErrors);

                    if ($migrationInstance instanceof MigrationInterface) {
                        $io->verbose(sprintf('Using anonymous class from <info>%s</info>.', $filePath));
                        $migration = $migrationInstance;
                        $migration->setVersion($version);
                    } elseif (class_exists($class)) {
                        $io->verbose(sprintf('Constructing <info>%s</info>.', $class));
                        $migration = new $class($version);

                        if (!$migration instanceof MigrationInterface) {
                            throw new InvalidArgumentException(sprintf(
                                'Migration class `%s` must implement %s.',
                                $class,
                                MigrationInterface::class,
                            ));
                        }
                    } else {
                        throw new InvalidArgumentException(sprintf(
                            'Could not find class `%s` in file `%s` and file did not return a migration instance',
                            $class,
                            $filePath,
                        ));
                    }

                    $config = $this->getConfig();
                    $migration->setConfig($config);
                    $migration->setIo($io);

                    $versions[$version] = $migration;
                } else {
                    $io->verbose(sprintf('Invalid migration file <error>%s</error>.', $filePath));
                }
            }

            ksort($versions);
            $this->setMigrations($versions);
        }

        return (array)$this->migrations;
    }

    /**
     * Prevent fatal errors when loading legacy migration files.
     *
     * @param string $filePath Migration file path
     * @return void
     */
    protected function checkMigrationClass(string $filePath): void
    {
        $contents = file_get_contents($filePath);
        if ($contents === false) {
            return;
        }

        $usesLegacyAbstractMigration =
            str_contains($contents, 'use Migrations\AbstractMigration;') ||
            str_contains($contents, 'extends AbstractMigration') ||
            str_contains($contents, 'extends \Migrations\AbstractMigration');

        if ($usesLegacyAbstractMigration) {
            throw new RuntimeException(sprintf(
                'Migration file `%s` uses the legacy `Migrations\\AbstractMigration` class. Update the migration to extend `Crustum\\Mongo\\Migration\\BaseMigration`.',
                $filePath,
            ));
        }
    }

    /**
     * Returns a list of migration files found in the provided migration paths.
     *
     * @return array<int, string>
     */
    protected function getMigrationFiles(): array
    {
        return Util::getFiles($this->getConfig()->getMigrationPath());
    }

    /**
     * Sets the database seeders.
     *
     * @param array<string, \Crustum\Mongo\Migration\SeedInterface> $seeds Seeders
     * @return $this
     */
    public function setSeeds(array $seeds): static
    {
        $this->seeds = $seeds;

        return $this;
    }

    /**
     * Normalize a seed name by trying with and without the 'Seed' suffix.
     *
     * @param string $name Seed name to normalize
     * @param array<string, \Crustum\Mongo\Migration\SeedInterface> $seeds Seeds array to search in
     * @return string|null The normalized seed name, or null if not found
     */
    public function normalizeSeedName(string $name, array $seeds): ?string
    {
        if (array_key_exists($name . 'Seed', $seeds)) {
            return $name . 'Seed';
        }

        if (array_key_exists($name, $seeds)) {
            return $name;
        }

        return null;
    }

    /**
     * Get seed dependencies instances from seed dependency array.
     *
     * @param \Crustum\Mongo\Migration\SeedInterface $seed Seed
     * @return array<string, \Crustum\Mongo\Migration\SeedInterface>
     */
    protected function getSeedDependenciesInstances(SeedInterface $seed): array
    {
        $dependenciesInstances = [];
        $dependencies = $seed->getDependencies();
        if ($dependencies && $this->seeds) {
            foreach ($dependencies as $dependency) {
                $normalizedName = $this->normalizeSeedName($dependency, $this->seeds);
                if ($normalizedName !== null) {
                    $dependenciesInstances[$normalizedName] = $this->seeds[$normalizedName];
                }
            }
        }

        return $dependenciesInstances;
    }

    /**
     * Order seeds by dependencies.
     *
     * @param array<string, \Crustum\Mongo\Migration\SeedInterface> $seeds Seeds
     * @param array<string, true> $visiting Seeds currently being visited (for cycle detection)
     * @param array<string, true> $visited Seeds that have been fully processed
     * @return array<string, \Crustum\Mongo\Migration\SeedInterface>
     * @throws \RuntimeException When a circular dependency is detected
     */
    protected function orderSeedsByDependencies(array $seeds, array $visiting = [], array &$visited = []): array
    {
        $orderedSeeds = [];
        foreach ($seeds as $seed) {
            $name = $seed->getName();

            if (isset($visited[$name])) {
                continue;
            }

            if (isset($visiting[$name])) {
                $cycle = array_keys($visiting);
                $cycle[] = $name;
                throw new RuntimeException(
                    'Circular dependency detected in seeds: ' . implode(' -> ', $cycle),
                );
            }

            $visiting[$name] = true;

            $dependencies = $this->getSeedDependenciesInstances($seed);
            if ($dependencies !== []) {
                $orderedSeeds = array_merge(
                    $this->orderSeedsByDependencies($dependencies, $visiting, $visited),
                    $orderedSeeds,
                );
            }

            $visited[$name] = true;
            unset($visiting[$name]);
            $orderedSeeds[$name] = $seed;
        }

        return $orderedSeeds;
    }

    /**
     * Gets an array of database seeders.
     *
     * @return array<string, \Crustum\Mongo\Migration\SeedInterface>
     */
    public function getSeeds(): array
    {
        if ($this->seeds === null) {
            $phpFiles = $this->getSeedFiles();

            $fileNames = [];
            $seeds = [];

            $config = $this->getConfig();
            $io = $this->getIo();

            foreach ($phpFiles as $filePath) {
                if (Util::isValidSeedFileName(basename($filePath))) {
                    $class = pathinfo($filePath, PATHINFO_FILENAME);
                    $fileNames[$class] = basename($filePath);

                    $seedInstance = null;
                    if (!class_exists($class)) {
                        $seedInstance = require $filePath;
                    } else {
                        require_once $filePath;
                    }

                    if ($seedInstance instanceof SeedInterface) {
                        $io->verbose(sprintf('Using anonymous class from <info>%s</info>.', $filePath));
                        $seed = $seedInstance;
                    } elseif (class_exists($class)) {
                        $io->verbose(sprintf('Instantiating <info>%s</info>.', $class));
                        $seed = $this->container instanceof ContainerInterface ? $this->container->get($class) : new $class();
                    } else {
                        throw new InvalidArgumentException(sprintf(
                            'Could not find class `%s` in file `%s` and file did not return a seed instance',
                            $class,
                            $filePath,
                        ));
                    }

                    if (!$seed instanceof SeedInterface) {
                        throw new InvalidArgumentException(sprintf(
                            'Seed class `%s` must implement %s.',
                            $class,
                            SeedInterface::class,
                        ));
                    }

                    $seed->setIo($io);
                    $seed->setConfig($config);

                    $seeds[$class] = $seed;
                }
            }

            ksort($seeds);
            $this->setSeeds($seeds);
        }

        $this->seeds = $this->orderSeedsByDependencies((array)$this->seeds);
        if (!$this->seeds) {
            return [];
        }

        return $this->seeds;
    }

    /**
     * Returns a list of seed files found in the provided seed paths.
     *
     * @return array<int, string>
     */
    protected function getSeedFiles(): array
    {
        return Util::getFiles($this->getConfig()->getSeedPath());
    }

    /**
     * Sets the config.
     *
     * @param \Crustum\Mongo\Migration\Config\ConfigInterface $config Configuration object
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
     * @return \Crustum\Mongo\Migration\Config\ConfigInterface
     */
    public function getConfig(): ConfigInterface
    {
        return $this->config;
    }

    /**
     * Toggles the breakpoint for a specific version.
     *
     * @param int|null $version Version
     * @return void
     */
    public function toggleBreakpoint(?int $version): void
    {
        $this->markBreakpoint($version, self::BREAKPOINT_TOGGLE);
    }

    /**
     * Updates the breakpoint for a specific version.
     *
     * @param int|null $version The version of the target migration
     * @param int $mark The state of the breakpoint as defined by self::BREAKPOINT_xxxx constants
     * @return void
     */
    protected function markBreakpoint(?int $version, int $mark): void
    {
        $migrations = $this->getMigrations();
        $env = $this->getEnvironment();
        $versions = $env->getVersionLog();

        if (!$versions || !$migrations) {
            return;
        }

        if ($version === null) {
            $lastVersion = end($versions);
            $version = (int)$lastVersion['version'];
        }

        $io = $this->getIo();
        if ($version !== 0 && (!isset($versions[$version]) || !isset($migrations[$version]))) {
            $io->out(sprintf(
                '<comment>warning</comment> %s is not a valid version',
                $version,
            ));

            return;
        }

        switch ($mark) {
            case self::BREAKPOINT_TOGGLE:
                $env->getAdapter()->toggleBreakpoint($migrations[$version]);
                break;
            case self::BREAKPOINT_SET:
                if ((int)$versions[$version]['breakpoint'] === 0) {
                    $env->getAdapter()->setBreakpoint($migrations[$version]);
                }

                break;
            case self::BREAKPOINT_UNSET:
                if ((int)$versions[$version]['breakpoint'] === 1) {
                    $env->getAdapter()->unsetBreakpoint($migrations[$version]);
                }

                break;
        }

        $versions = $env->getVersionLog();

        $io->out(
            ' Breakpoint ' . ((int)$versions[$version]['breakpoint'] !== 0 ? 'set' : 'cleared') .
            ' for <info>' . $version . '</info>' .
            ' <comment>' . $migrations[$version]->getName() . '</comment>',
        );
    }

    /**
     * Remove all breakpoints.
     *
     * @return void
     */
    public function removeBreakpoints(): void
    {
        $this->getIo()->out(sprintf(
            ' %d breakpoints cleared.',
            $this->getEnvironment()->getAdapter()->resetAllBreakpoints(),
        ));
    }

    /**
     * Set the breakpoint for a specific version.
     *
     * @param int|null $version The version of the target migration
     * @return void
     */
    public function setBreakpoint(?int $version): void
    {
        $this->markBreakpoint($version, self::BREAKPOINT_SET);
    }

    /**
     * Unset the breakpoint for a specific version.
     *
     * @param int|null $version The version of the target migration
     * @return void
     */
    public function unsetBreakpoint(?int $version): void
    {
        $this->markBreakpoint($version, self::BREAKPOINT_UNSET);
    }

    /**
     * Reset the migrations stored in the object.
     *
     * @return void
     */
    public function resetMigrations(): void
    {
        $this->migrations = null;
    }

    /**
     * Reset the seeds stored in the object.
     *
     * @return void
     */
    public function resetSeeds(): void
    {
        $this->seeds = null;
    }

    /**
     * Returns the migration journal collection name.
     *
     * @return string
     */
    public function getSchemaTableName(): string
    {
        return $this->getEnvironment()->getAdapter()::MIGRATION_TABLE;
    }

    /**
     * Strips the 'Seed' suffix for display.
     *
     * @param string $name Seed class name
     * @return string Display name
     */
    protected function normalizeSeedDisplayName(string $name): string
    {
        if (str_ends_with($name, 'Seed')) {
            return substr($name, 0, -4);
        }

        return $name;
    }
}

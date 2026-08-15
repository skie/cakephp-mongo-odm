<?php
declare(strict_types=1);

/**
 * Ported and adapted from cakephp/migrations (MIT License).
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc.
 * @license https://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace Crustum\Mongo\Migration\TestSuite;

use Cake\Datasource\ConnectionManager;
use Cake\Log\Log;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Database\Schema\SchemaManager;
use Crustum\Mongo\Migration\Migrations;
use RuntimeException;
use Throwable;

/**
 * Test-suite helper that runs migrations against the test connection and
 * truncates the resulting collections between tests.
 */
class Migrator
{
    /**
     * Runs one set of migrations.
     *
     * @param array<string, mixed> $options Migrate options. Connection defaults to `test`
     * @param bool $truncateCollections Truncate all collections after running migrations. Defaults to true
     * @return void
     */
    public function run(
        array $options = [],
        bool $truncateCollections = true,
    ): void {
        $this->runMany([$options], $truncateCollections);
    }

    /**
     * Runs multiple sets of migrations.
     *
     * @param list<array<string, mixed>> $options Array of option arrays
     * @param bool $truncateCollections Truncate all collections after running migrations. Defaults to true
     * @return void
     */
    public function runMany(
        array $options = [],
        bool $truncateCollections = true,
    ): void {
        if (isset($GLOBALS['__PHPUNIT_BOOTSTRAP'])) {
            return;
        }

        $connectionsToDrop = [];
        $connectionsList = [];

        foreach ($options as $i => $migrationSet) {
            $migrationSet += ['connection' => 'test'];
            $skip = $migrationSet['skip'] ?? [];
            unset($migrationSet['skip']);

            $options[$i] = $migrationSet;
            $connectionName = $migrationSet['connection'];
            if (!isset($connectionsList[$connectionName])) {
                $connectionsList[$connectionName] = ['name' => $connectionName, 'skip' => $skip];
            }

            $migrations = new Migrations();
            if (!isset($connectionsToDrop[$connectionName]) && $this->shouldDropCollections($migrations, $migrationSet)) {
                $connectionsToDrop[$connectionName] = ['name' => $connectionName, 'skip' => $skip];
            }
        }

        foreach ($connectionsToDrop as $item) {
            $this->dropCollections($item['name'], $item['skip']);
        }

        foreach ($options as $migrationSet) {
            $migrations = new Migrations();
            try {
                if (!$migrations->migrate($migrationSet)) {
                    throw new RuntimeException(
                        sprintf('Unable to migrate fixtures for `%s`.', $migrationSet['connection']),
                    );
                }
            } catch (Throwable $e) {
                throw new RuntimeException(
                    'Could not apply migrations for ' . json_encode($migrationSet) . "\n\n" .
                    'Migrations failed to apply with message:' . "\n\n" .
                    $e->getMessage(),
                    0,
                    $e,
                );
            }
        }

        if ($truncateCollections) {
            foreach ($connectionsList as $item) {
                $this->truncate($item['name'], $item['skip']);
            }
        }
    }

    /**
     * Truncates all non-journal collections after running migrations.
     *
     * @param string $connection Connection name
     * @param list<string> $skip A fnmatch compatible list of collection names to skip
     * @return void
     */
    public function truncate(string $connection, array $skip = []): void
    {
        if (isset($GLOBALS['__PHPUNIT_BOOTSTRAP'])) {
            return;
        }

        $collections = $this->getNonJournalCollections($connection, $skip);
        $conn = ConnectionManager::get($connection);
        if (!$conn instanceof Connection) {
            return;
        }

        foreach ($collections as $name) {
            $conn->getCollection($name)->deleteMany([]);
        }
    }

    /**
     * Detect if migrations have changed and the database needs to be wiped.
     *
     * @param \Crustum\Mongo\Migration\Migrations $migrations The migrations service
     * @param array<string, mixed> $options The connection options
     * @return bool
     */
    protected function shouldDropCollections(Migrations $migrations, array $options): bool
    {
        Log::write('debug', sprintf('Reading migrations status for %s...', $options['connection']));

        $messages = ['down' => [], 'missing' => []];
        foreach ($migrations->status($options) as $migration) {
            if ($migration['status'] === 'up' && ($migration['missing'] ?? false)) {
                $messages['missing'][] = 'Applied but missing Migration source=' .
                    $migration['name'] . ' id=' . $migration['id'];
            }

            if ($migration['status'] === 'down') {
                $messages['down'][] = sprintf('Migration to reverse. source=%s id=%s', $migration['name'], $migration['id']);
            }
        }

        $output = [];
        $itemize = fn(string $item): string => '- ' . $item;
        if ($messages['down'] !== []) {
            $output[] = 'Migrations needing to be reversed:';
            $output = array_merge($output, array_map($itemize, $messages['down']));
            $output[] = '';
        }

        if ($messages['missing'] !== []) {
            $output[] = 'Applied but missing migrations:';
            $output = array_merge($output, array_map($itemize, $messages['missing']));
            $output[] = '';
        }

        if ($output !== []) {
            $output = array_merge(
                ['Your migration status has differences with the expected state.', ''],
                $output,
                ['Going to drop all collections in this source, and re-apply migrations.'],
            );
            Log::write('debug', implode("\n", $output));
        }

        return $output !== [];
    }

    /**
     * Drops the regular collections and truncates the journal.
     *
     * @param string $connection Connection name
     * @param list<string> $skip A fnmatch compatible list of collection names to skip
     * @return void
     */
    protected function dropCollections(string $connection, array $skip = []): void
    {
        $conn = ConnectionManager::get($connection);
        if (!$conn instanceof Connection) {
            return;
        }

        $manager = new SchemaManager($conn);
        $drop = $this->getNonJournalCollections($connection, $skip);
        foreach ($drop as $name) {
            $manager->dropCollection($name);
        }

        foreach ($this->getJournalCollections($connection) as $name) {
            $conn->getCollection($name)->deleteMany([]);
        }
    }

    /**
     * Returns the names of the journal collections.
     *
     * @param string $connection Connection name
     * @return list<string>
     */
    protected function getJournalCollections(string $connection): array
    {
        $conn = ConnectionManager::get($connection);
        if (!$conn instanceof Connection) {
            return [];
        }

        $manager = new SchemaManager($conn);

        return array_values(array_filter(
            $manager->listCollections(),
            fn(string $name): bool => $name === 'cake_migrations' || $name === '_seeds',
        ));
    }

    /**
     * Returns the names of the non-journal collections.
     *
     * @param string $connection Connection name
     * @param list<string> $skip Skip patterns
     * @return list<string>
     */
    protected function getNonJournalCollections(string $connection, array $skip): array
    {
        $conn = ConnectionManager::get($connection);
        if (!$conn instanceof Connection) {
            return [];
        }

        $manager = new SchemaManager($conn);
        $skip[] = 'cake_migrations';
        $skip[] = '_seeds';

        return array_values(array_filter(
            $manager->listCollections(),
            function (string $name) use ($skip): bool {
                foreach ($skip as $pattern) {
                    if (fnmatch($pattern, $name)) {
                        return false;
                    }
                }

                return true;
            },
        ));
    }
}

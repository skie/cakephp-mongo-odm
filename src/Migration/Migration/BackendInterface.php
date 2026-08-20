<?php
declare(strict_types=1);

/**
 * Ported and adapted from cakephp/migrations (MIT License).
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc.
 * @license https://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace Crustum\Mongo\Migration\Migration;

/**
 * Migration backend contract.
 *
 * Provides programmatic access to migration/seed operations without the
 * console command layer.
 *
 * @ported-from \Migrations\Migration\BackendInterface
 */
interface BackendInterface
{
    /**
     * Returns the status of each migration based on the options passed.
     *
     * @param array<string, mixed> $options Options to pass to the command
     * @return array<int, array<string, mixed>> The migrations list and their statuses
     */
    public function status(array $options = []): array;

    /**
     * Migrates available migrations.
     *
     * @param array<string, mixed> $options Options to pass to the command
     * @return bool Success
     */
    public function migrate(array $options = []): bool;

    /**
     * Rollbacks migrations.
     *
     * @param array<string, mixed> $options Options to pass to the command
     * @return bool Success
     */
    public function rollback(array $options = []): bool;

    /**
     * Marks a migration as migrated.
     *
     * @param string|int|null $version The version number of the migration to mark as migrated
     * @param array<string, mixed> $options Options to pass to the command
     * @return bool Success
     */
    public function markMigrated(int|string|null $version = null, array $options = []): bool;

    /**
     * Runs seeders.
     *
     * @param array<string, mixed> $options Options to pass to the command
     * @return bool Success
     */
    public function seed(array $options = []): bool;
}

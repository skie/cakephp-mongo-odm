<?php
declare(strict_types=1);

/**
 * Ported and adapted from cakephp/migrations (MIT License).
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc.
 * @license https://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace Crustum\Mongo\Migration;

use Crustum\Mongo\Migration\Migration\BackendInterface;
use Crustum\Mongo\Migration\Migration\BuiltinBackend;

/**
 * The Migrations class is responsible for handling migration operations
 * within a non-shell application.
 */
class Migrations
{
    /**
     * Default options to use.
     *
     * @var array<string, mixed>
     */
    protected array $default = [];

    /**
     * Constructor.
     *
     * @param array<string, mixed> $default Default options to use when calling a method.
     * Available options are:
     * - `connection` The datasource connection to use
     * - `source` The folder where migrations are in
     * - `plugin` The plugin containing the migrations
     */
    public function __construct(array $default = [])
    {
        if ($default !== []) {
            $this->default = $default;
        }
    }

    /**
     * Get the Migrations interface backend.
     *
     * @return \Crustum\Mongo\Migration\Migration\BackendInterface
     */
    protected function getBackend(): BackendInterface
    {
        return new BuiltinBackend($this->default);
    }

    /**
     * Returns the status of each migration based on the options passed.
     *
     * @param array<string, mixed> $options Options to pass to the command
     * @return array<int, array<string, mixed>> The migrations list and their statuses
     */
    public function status(array $options = []): array
    {
        return $this->getBackend()->status($options);
    }

    /**
     * Migrates available migrations.
     *
     * @param array<string, mixed> $options Options to pass to the command
     * @return bool Success
     */
    public function migrate(array $options = []): bool
    {
        return $this->getBackend()->migrate($options);
    }

    /**
     * Rollbacks migrations.
     *
     * @param array<string, mixed> $options Options to pass to the command
     * @return bool Success
     */
    public function rollback(array $options = []): bool
    {
        return $this->getBackend()->rollback($options);
    }

    /**
     * Marks a migration as migrated.
     *
     * @param string|int|null $version The version number of the migration to mark as migrated
     * @param array<string, mixed> $options Options to pass to the command
     * @return bool Success
     */
    public function markMigrated(string|int|null $version = null, array $options = []): bool
    {
        return $this->getBackend()->markMigrated($version, $options);
    }

    /**
     * Seeds the database using a seed file.
     *
     * @param array<string, mixed> $options Options to pass to the command
     * @return bool Success
     */
    public function seed(array $options = []): bool
    {
        return $this->getBackend()->seed($options);
    }
}

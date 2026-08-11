<?php
declare(strict_types=1);

/**
 * Ported and adapted from cakephp/migrations (MIT License).
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc.
 * @license https://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace Crustum\Mongo\Migration\Config;

use ArrayAccess;

/**
 * Configuration interface.
 *
 * @extends \ArrayAccess<string, mixed>
 */
interface ConfigInterface extends ArrayAccess
{
    public const DEFAULT_MIGRATION_FOLDER = 'MongoMigrations';

    public const DEFAULT_SEED_FOLDER = 'Seeds';

    /**
     * Returns the configuration for the current environment.
     *
     * This method returns <code>null</code> if the specified environment
     * doesn't exist.
     *
     * @return array<string, mixed>|null
     */
    public function getEnvironment(): ?array;

    /**
     * Gets the path to search for migration files.
     *
     * @return string
     */
    public function getMigrationPath(): string;

    /**
     * Gets the path to search for seed files.
     *
     * @return string
     */
    public function getSeedPath(): string;

    /**
     * Get the connection name.
     *
     * @return string|false
     */
    public function getConnection(): string|false;

    /**
     * Get the version order.
     *
     * @return string
     */
    public function getVersionOrder(): string;

    /**
     * Is version order creation time?
     *
     * @return bool
     */
    public function isVersionOrderCreationTime(): bool;

    /**
     * Should queries be sent to the database or just print to stdout?
     *
     * @return bool
     */
    public function isDryRun(): bool;
}

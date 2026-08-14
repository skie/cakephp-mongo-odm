<?php
declare(strict_types=1);

/**
 * Ported and adapted from cakephp/migrations (MIT License).
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc.
 * @license https://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace Crustum\Mongo\Migration\Util;

use Cake\Utility\Inflector;
use Crustum\Mongo\Migration\Config\ConfigInterface;
use Crustum\Mongo\Migration\SeedInterface;
use DateTime;
use DateTimeZone;
use RuntimeException;

/**
 * Utility helpers for migration file naming and version parsing.
 */
class Util
{
    /**
     * @var string
     */
    public const DATE_FORMAT = 'YmdHis';

    /**
     * @var string
     */
    protected const MIGRATION_FILE_NAME_PATTERN = '/^\d+_([a-z][a-z\d]*(?:_[a-z\d]+)*)\.php$/i';

    /**
     * @var string
     */
    protected const MIGRATION_FILE_NAME_NO_NAME_PATTERN = '/^\d{14}\.php$/';

    /**
     * @var string
     */
    protected const READABLE_MIGRATION_FILE_NAME_PATTERN = '/^(\d{4})_(\d{2})_(\d{2})_(\d{6})_([A-Z][a-zA-Z\d]*)\.php$/';

    /**
     * @var string
     */
    protected const SEED_FILE_NAME_PATTERN = '/^([a-z][a-z\d]*)\.php$/i';

    /**
     * Gets the current timestamp string, in UTC.
     *
     * @param int|null $offset Seconds to offset
     * @return string
     */
    public static function getCurrentTimestamp(?int $offset = null): string
    {
        $time = 'now';
        if ($offset) {
            $time = '+' . $offset . ' seconds';
        }

        $dt = new DateTime($time, new DateTimeZone('UTC'));

        return $dt->format(static::DATE_FORMAT);
    }

    /**
     * Gets the version from the beginning of a file name.
     *
     * @param string $fileName File name
     * @return int
     */
    public static function getVersionFromFileName(string $fileName): int
    {
        $matches = [];
        $baseName = basename($fileName);

        if (preg_match(static::READABLE_MIGRATION_FILE_NAME_PATTERN, $baseName, $matches)) {
            return (int)($matches[1] . $matches[2] . $matches[3] . $matches[4]);
        }

        preg_match('/^\d+/', $baseName, $matches);
        $value = (int)($matches[0] ?? null);
        if ($value === 0) {
            throw new RuntimeException(sprintf('Cannot get a valid version from filename `%s`', $fileName));
        }

        return $value;
    }

    /**
     * Turn migration names like 'CreateUserTable' into file names like
     * '12345678901234_create_user_table.php'.
     *
     * @param string $className Class name
     * @return string
     */
    public static function mapClassNameToFileName(string $className): string
    {
        $snake = fn($matches): string => '_' . strtolower((string)$matches[0]);
        $fileName = preg_replace_callback('/\d+|[A-Z]/', $snake, $className);

        return static::getCurrentTimestamp() . $fileName . '.php';
    }

    /**
     * Turn file names like '12345678901234_create_user_table.php' into class
     * names like 'CreateUserTable'.
     *
     * @param string $fileName File name
     * @return string
     */
    public static function mapFileNameToClassName(string $fileName): string
    {
        $matches = [];

        if (preg_match(static::READABLE_MIGRATION_FILE_NAME_PATTERN, $fileName, $matches)) {
            return $matches[5];
        }

        if (preg_match(static::MIGRATION_FILE_NAME_PATTERN, $fileName, $matches)) {
            $fileName = $matches[1];
        } elseif (preg_match(static::MIGRATION_FILE_NAME_NO_NAME_PATTERN, $fileName)) {
            return 'V' . substr($fileName, 0, strlen($fileName) - 4);
        }

        return Inflector::camelize($fileName);
    }

    /**
     * Check if a migration file name is valid.
     *
     * @param string $fileName File name
     * @return bool
     */
    public static function isValidMigrationFileName(string $fileName): bool
    {
        return (bool)preg_match(static::MIGRATION_FILE_NAME_PATTERN, $fileName)
            || (bool)preg_match(static::MIGRATION_FILE_NAME_NO_NAME_PATTERN, $fileName)
            || (bool)preg_match(static::READABLE_MIGRATION_FILE_NAME_PATTERN, $fileName);
    }

    /**
     * Check if a seed file name is valid.
     *
     * @param string $fileName File name
     * @return bool
     */
    public static function isValidSeedFileName(string $fileName): bool
    {
        return (bool)preg_match(static::SEED_FILE_NAME_PATTERN, $fileName);
    }

    /**
     * Expands a set of paths with curly braces (if supported by the OS).
     *
     * @param array<int, string> $paths Paths
     * @return array<int, string>
     */
    public static function globAll(array $paths): array
    {
        $result = [];
        foreach ($paths as $path) {
            $result = array_merge($result, static::glob($path));
        }

        return $result;
    }

    /**
     * Expands a path with curly braces (if supported by the OS).
     *
     * @param string $path Path
     * @return array<int, string>
     */
    public static function glob(string $path): array
    {
        $result = glob($path, defined('GLOB_BRACE') ? GLOB_BRACE : 0);
        if ($result) {
            return $result;
        }

        return [];
    }

    /**
     * Given an array of paths, return all unique PHP files that are in them.
     *
     * @param array<int, string>|string $paths Path or array of paths to get .php files
     * @return array<int, string>
     */
    public static function getFiles(string|array $paths): array
    {
        $files = static::globAll(array_map(fn(string $path): string => $path . DIRECTORY_SEPARATOR . '*.php', (array)$paths));

        return array_unique($files);
    }

    /**
     * Resolves the plugin a seed belongs to from its config.
     *
     * Seed classes are not namespaced, so the plugin comes from the config set
     * on the seed by the manager (null for application seeds).
     *
     * @param \Crustum\Mongo\Migration\SeedInterface $seed Seed
     * @return string|null
     */
    public static function getSeedPlugin(SeedInterface $seed): ?string
    {
        $config = $seed->getConfig();
        if (!$config instanceof ConfigInterface || !isset($config['plugin'])) {
            return null;
        }

        return (string)$config['plugin'] ?: null;
    }

    /**
     * Checks whether a seed-log entry's plugin matches the given plugin.
     *
     * A plugin entry matches a null plugin check too (entries logged before
     * plugin attribution existed).
     *
     * @param string|null $entryPlugin Plugin stored in the log
     * @param string|null $plugin The plugin to match
     * @return bool
     */
    public static function matchesSeedPlugin(?string $entryPlugin, ?string $plugin): bool
    {
        if ($entryPlugin === $plugin) {
            return true;
        }

        return $plugin !== null && $entryPlugin === null;
    }

    /**
     * Strips the `Seed` suffix for display.
     *
     * @param string $seedName Seed class name
     * @return string Display name
     */
    public static function getSeedDisplayName(string $seedName): string
    {
        if (str_ends_with($seedName, 'Seed')) {
            return substr($seedName, 0, -4);
        }

        return $seedName;
    }
}

<?php
declare(strict_types=1);

namespace Crustum\Mongo\TestSuite;

use Cake\Datasource\ConnectionManager;
use Crustum\Mongo\Database\Connection;
use MongoDB\Database;

/**
 * Helper for managing test connections and Mongo collections.
 *
 * Reuses Cake's `ConnectionHelper::addTestAliases()` semantics (generic over
 * `ConnectionManager`) and adds Mongo-specific collection management, since
 * Cake's `ConnectionHelper` type-asserts `Cake\Database\Connection`.
 *
 * @inspired-by \Cake\TestSuite\ConnectionHelper
 */
class ConnectionHelper
{
    /**
     * Adds `test_<connection name>` aliases for all non-test connections.
     *
     * @return void
     */
    public static function addTestAliases(): void
    {
        ConnectionManager::alias('test', 'default');
        foreach (ConnectionManager::configured() as $connection) {
            if ($connection === 'test') {
                continue;
            }

            if ($connection === 'default') {
                continue;
            }

            if (str_starts_with($connection, 'test_')) {
                ConnectionManager::alias($connection, substr($connection, 5));
            } else {
                ConnectionManager::alias('test_' . $connection, $connection);
            }
        }
    }

    /**
     * Drops collections in the given connection's database.
     *
     * @param string $connectionName Connection name.
     * @param array<string>|null $collections Collection names, or null for all.
     * @return void
     */
    public static function dropCollections(string $connectionName, ?array $collections = null): void
    {
        $connection = ConnectionManager::get($connectionName);
        assert($connection instanceof Connection);
        $database = $connection->getDatabase();

        $names = $collections ?? self::collectionNames($database);
        foreach ($names as $name) {
            $database->dropCollection($name);
        }
    }

    /**
     * Truncates collections in the given connection's database.
     *
     * @param string $connectionName Connection name.
     * @param array<string>|null $collections Collection names, or null for all.
     * @return void
     */
    public static function truncateCollections(string $connectionName, ?array $collections = null): void
    {
        $connection = ConnectionManager::get($connectionName);
        assert($connection instanceof Connection);
        $database = $connection->getDatabase();

        $names = $collections ?? self::collectionNames($database);
        foreach ($names as $name) {
            $database->selectCollection($name)->deleteMany([]);
        }
    }

    /**
     * Returns the collection names in a database.
     *
     * @param \MongoDB\Database $database The database.
     * @return list<string>
     */
    protected static function collectionNames(Database $database): array
    {
        $names = [];
        foreach ($database->listCollections() as $info) {
            $names[] = $info->getName();
        }

        return $names;
    }
}

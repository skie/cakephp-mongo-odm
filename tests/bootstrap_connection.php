<?php
declare(strict_types=1);

use Cake\Datasource\ConnectionManager;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Database\Driver\MongoDriver;
use Crustum\Mongo\TestSuite\Fixture\SchemaGenerator;

if (!function_exists('mongoTestEnv')) {
    /**
     * Reads a test environment variable, falling back when unset or empty.
     *
     * @param string $key Environment variable name.
     * @param string|null $default Default when the variable is missing or blank.
     * @return string|null
     */
    function mongoTestEnv(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            return $default;
        }

        return $value;
    }
}

if (!function_exists('mongoTestDatabaseName')) {
    /**
     * Resolves an isolated Mongo test database name.
     *
     * Uses the env key (for example `TEST_MONGO_DB`) as the base name, then
     * appends `_<TEST_TOKEN>` when ParaTest assigns a worker token.
     *
     * @param string $default Base database name when the env key is unset.
     * @param string $envKey Environment variable holding the base database name.
     * @return string
     */
    function mongoTestDatabaseName(string $default, string $envKey = 'TEST_MONGO_DB'): string
    {
        $name = mongoTestEnv($envKey, $default) ?? $default;
        $token = mongoTestEnv('TEST_TOKEN');
        if ($token !== null) {
            $name .= '_' . $token;
        }

        return $name;
    }
}

if (!function_exists('mongoTestConnectionConfig')) {
    /**
     * Builds a Mongo connection config array for the test harness.
     *
     * Host and port come from `TEST_MONGO_HOST` / `TEST_MONGO_PORT` (set in
     * `phpunit.xml.dist` or the shell). Override the database name per call.
     *
     * @param string $database Mongo database name.
     * @return array<string, mixed>
     */
    function mongoTestConnectionConfig(string $database): array
    {
        return [
            'className' => Connection::class,
            'driver' => MongoDriver::class,
            'host' => mongoTestEnv('TEST_MONGO_HOST', '127.0.0.1') ?? '127.0.0.1',
            'port' => (int)(mongoTestEnv('TEST_MONGO_PORT', '27017') ?? 27017),
            'database' => $database,
        ];
    }
}

if (!function_exists('mongoTestMigrationSource')) {
    /**
     * Migration folder name under `CONFIG/` (isolated per ParaTest worker).
     *
     * @return string
     */
    function mongoTestMigrationSource(): string
    {
        $token = mongoTestEnv('TEST_TOKEN');
        if ($token === null) {
            return 'MongoMigrations';
        }

        return 'MongoMigrations_' . $token;
    }
}

if (!function_exists('mongoTestMigrationDir')) {
    /**
     * Absolute path to the migration folder for the current test process.
     *
     * @return string
     */
    function mongoTestMigrationDir(): string
    {
        if (!defined('CONFIG')) {
            throw new RuntimeException('CONFIG must be defined before mongoTestMigrationDir().');
        }

        $dir = CONFIG . mongoTestMigrationSource() . DS;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        return $dir;
    }
}

if (!function_exists('mongoTestCleanMigrationDir')) {
    /**
     * Removes baked migration files and schema lock from the test migrations folder.
     *
     * @param string|null $dir Absolute migrations directory; defaults to the worker folder.
     * @return void
     */
    function mongoTestCleanMigrationDir(?string $dir = null): void
    {
        $dir ??= mongoTestMigrationDir();

        foreach (glob($dir . '*.php') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $lock = $dir . 'schema-dump-mongo.lock';
        if (is_file($lock)) {
            unlink($lock);
        }
    }
}

if (!function_exists('mongoTestEnsureSchema')) {
    /**
     * Loads `schema_mongo.php` once per PHP process (bootstrap / ParaTest worker).
     *
     * Per-test data reload is handled by Cake fixtures + `TruncateStrategy`
     * (`truncate()` + `insert()`), not here. Set `MONGO_TEST_SCHEMA_FORCE_RELOAD=1`
     * to force a full drop/recreate (CI clean slate).
     *
     * @param string $schemaPath Absolute path to `schema_mongo.php`.
     * @param string $connection Connection config name (for example `test_mongo`).
     * @return void
     */
    function mongoTestEnsureSchema(string $schemaPath, string $connection = 'test_mongo'): void
    {
        static $loaded = false;
        if ($loaded) {
            return;
        }

        $loaded = true;

        if (mongoTestEnv('MONGO_TEST_SCHEMA_FORCE_RELOAD') !== '1') {
            /** @var \Crustum\Mongo\Database\Connection $mongoConnection */
            $mongoConnection = ConnectionManager::get($connection);

            $schemaHash = md5_file($schemaPath) ?: '';
            $sentinel = $mongoConnection->getDatabase()->selectCollection('_schema_sentinel');
            $record = $sentinel->findOne(['_id' => 'schema_hash']);
            $storedHash = is_object($record) ? (string)($record->hash ?? '') : (string)($record['hash'] ?? '');

            if ($storedHash === $schemaHash) {
                return;
            }
        }

        $generator = new SchemaGenerator($schemaPath, $connection);
        $generator->reload();

        /** @var \Crustum\Mongo\Database\Connection $mongoConnection */
        $mongoConnection = ConnectionManager::get($connection);
        $schemaHash = md5_file($schemaPath) ?: '';
        $mongoConnection->getDatabase()->selectCollection('_schema_sentinel')->replaceOne(
            ['_id' => 'schema_hash'],
            ['_id' => 'schema_hash', 'hash' => $schemaHash],
            ['upsert' => true],
        );
    }
}

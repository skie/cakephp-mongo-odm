<?php
declare(strict_types=1);

namespace Crustum\Mongo\TestSuite;

use Cake\Core\Exception\CakeException;
use Cake\Datasource\ConnectionManager;
use Crustum\Mongo\Database\Connection;
use MongoDB\Database;
use MongoDB\Driver\Exception\RuntimeException as DriverRuntimeException;
use RuntimeException;

/**
 * Schema generator for Mongo test suites.
 *
 * Drops all collections and recreates them from a PHP schema array file:
 * ```
 * return [
 *     ['name' => 'articles', 'options' => [], 'indexes' => [['key' => ['title' => 1]]]],
 * ];
 * ```
 */
class SchemaGenerator
{
    /**
     * The schema definition file path.
     *
     * @var string
     */
    protected string $schemaPath;

    /**
     * The connection name.
     *
     * @var string
     */
    protected string $connection;

    /**
     * Constructor.
     *
     * @param string $schemaPath Path to schema definition file.
     * @param string $connection Connection name to use.
     */
    public function __construct(string $schemaPath, string $connection)
    {
        $this->schemaPath = $schemaPath;
        $this->connection = $connection;
    }

    /**
     * Drops all collections and recreates them from the schema file.
     *
     * @return void
     * @throws \RuntimeException When the schema file is missing or invalid.
     */
    public function reload(): void
    {
        if (!file_exists($this->schemaPath)) {
            throw new RuntimeException(sprintf('Schema file "%s" does not exist.', $this->schemaPath));
        }

        $schema = include $this->schemaPath;
        if (!is_array($schema)) {
            throw new RuntimeException('Schema file must return an array.');
        }

        $connection = ConnectionManager::get($this->connection);
        assert($connection instanceof Connection);

        $this->dropCollections($connection);
        $this->createCollections($connection, $schema);
    }

    /**
     * Drops all collections in the database.
     *
     * @param \Crustum\Mongo\Database\Connection $connection The connection.
     * @return void
     */
    protected function dropCollections(Connection $connection): void
    {
        $database = $connection->getDatabase();
        foreach ($database->listCollections() as $info) {
            $database->dropCollection($info->getName());
        }
    }

    /**
     * Creates collections and their indexes/validators from the schema.
     *
     * @param \Crustum\Mongo\Database\Connection $connection The connection.
     * @param array<int, array<string, mixed>> $schema Schema definition.
     * @return void
     * @throws \Cake\Core\Exception\CakeException When the schema is invalid.
     */
    protected function createCollections(Connection $connection, array $schema): void
    {
        $database = $connection->getDatabase();

        foreach ($schema as $definition) {
            if (empty($definition['name'])) {
                throw new CakeException('Collection name is required in schema.');
            }

            $name = (string)$definition['name'];
            $options = $definition['options'] ?? [];
            $this->createCollection($database, $name, $options);

            if (isset($definition['validator'])) {
                $database->command([
                    'collMod' => $name,
                    'validator' => $definition['validator'],
                    'validationLevel' => $options['validationLevel'] ?? 'strict',
                    'validationAction' => $options['validationAction'] ?? 'error',
                ]);
            }

            foreach ($definition['indexes'] ?? [] as $index) {
                if (empty($index['key'])) {
                    throw new CakeException('Index key is required in schema.');
                }

                $connection->getCollection($name)->createIndex(
                    $index['key'],
                    $index['options'] ?? [],
                );
            }
        }
    }

    /**
     * Creates a collection, tolerating an existing one.
     *
     * @param \MongoDB\Database $database The database.
     * @param string $name Collection name.
     * @param array<string, mixed> $options Creation options.
     * @return void
     */
    protected function createCollection(Database $database, string $name, array $options): void
    {
        try {
            $database->createCollection($name, $options);
        } catch (DriverRuntimeException) {
            $database->selectCollection($name);
        }
    }
}

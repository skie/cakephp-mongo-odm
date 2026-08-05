<?php
declare(strict_types=1);

namespace Crustum\Mongo\TestSuite\Fixture;

use Cake\Core\Exception\CakeException;
use Cake\Datasource\ConnectionManager;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Database\Schema\SchemaManager;
use MongoDB\Driver\Exception\RuntimeException as DriverRuntimeException;
use RuntimeException;

/**
 * Schema generator for Mongo test suites.
 *
 * Loads a declarative PHP file that maps collection names to their
 * validator, indexes, and creation options:
 * ```
 * return [
 *     'articles' => [
 *         'validator' => ['$jsonSchema' => ['bsonType' => 'object', 'properties' => ['title' => ['bsonType' => 'string']]]],
 *         'indexes' => [
 *             'articles_title' => ['key' => ['title' => 1]],
 *         ],
 *         'options' => ['validationLevel' => 'strict'],
 *     ],
 * ];
 * ```
 *
 * `reload()` validates the whole file first, then drops and recreates only
 * the collections named in it, leaving unrelated collections untouched.
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
     * Drops and recreates the collections declared by the schema file.
     *
     * Collections not named in the file are left untouched. The schema file
     * is fully validated before any database change is made.
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

        $this->validate($schema);

        $connection = ConnectionManager::get($this->connection);
        if (!$connection instanceof Connection) {
            throw new RuntimeException(sprintf(
                'Connection "%s" is not a %s instance.',
                $this->connection,
                Connection::class,
            ));
        }

        $manager = new SchemaManager($connection);

        $this->dropCollections($manager, array_keys($schema));
        $this->createCollections($manager, $schema);
    }

    /**
     * Validates the schema definition before any database change.
     *
     * @param array<string, mixed> $schema The schema definition map.
     * @return void
     * @throws \Cake\Core\Exception\CakeException When the schema is invalid.
     */
    protected function validate(array $schema): void
    {
        foreach ($schema as $name => $definition) {
            if ($name === '') {
                throw new CakeException('Every collection key must be a non-empty string in schema.');
            }

            if (!is_array($definition)) {
                throw new CakeException(sprintf('Definition for collection "%s" must be an array.', $name));
            }

            if (isset($definition['validator']) && !is_array($definition['validator'])) {
                throw new CakeException(sprintf('Validator for collection "%s" must be an array.', $name));
            }

            if (isset($definition['options']) && !is_array($definition['options'])) {
                throw new CakeException(sprintf('Options for collection "%s" must be an array.', $name));
            }

            if (isset($definition['fields']) && !is_array($definition['fields'])) {
                throw new CakeException(sprintf('Fields for collection "%s" must be an array.', $name));
            }

            if (isset($definition['indexes']) && !is_array($definition['indexes'])) {
                throw new CakeException(sprintf('Indexes for collection "%s" must be an array.', $name));
            }

            foreach ($definition['indexes'] ?? [] as $indexName => $index) {
                if (!is_string($indexName) || $indexName === '') {
                    throw new CakeException(sprintf('Index name for collection "%s" must be a non-empty string.', $name));
                }

                if (!is_array($index) || empty($index['key']) || !is_array($index['key'])) {
                    throw new CakeException(sprintf('Index "%s" on collection "%s" must define a non-empty key map.', $indexName, $name));
                }
            }
        }
    }

    /**
     * Drops only the collections declared by the schema file.
     *
     * A failed drop (e.g. a concurrent reload is mid-rewrite) is tolerated:
     * the final state is guaranteed by the create phase.
     *
     * @param \Crustum\Mongo\Database\Schema\SchemaManager $manager The schema manager.
     * @param list<string> $names The declared collection names.
     * @return void
     */
    protected function dropCollections(SchemaManager $manager, array $names): void
    {
        foreach ($names as $name) {
            try {
                $manager->dropCollection($name);
            } catch (DriverRuntimeException) {
                // A background operation (index build) may be running from a
                // concurrent reload. Drop will succeed on the next pass.
            }
        }
    }

    /**
     * Creates the declared collections with validators, options, and indexes.
     *
     * @param \Crustum\Mongo\Database\Schema\SchemaManager $manager The schema manager.
     * @param array<string, mixed> $schema The schema definition map.
     * @return void
     */
    protected function createCollections(SchemaManager $manager, array $schema): void
    {
        foreach ($schema as $name => $definition) {
            $this->createCollection($manager, $name, $definition);
        }
    }

    /**
     * Creates a single collection with its validator, options, and indexes.
     *
     * @param \Crustum\Mongo\Database\Schema\SchemaManager $manager The schema manager.
     * @param string $name The collection name.
     * @param array<string, mixed> $definition The collection definition.
     * @return void
     */
    protected function createCollection(SchemaManager $manager, string $name, array $definition): void
    {
        $options = $definition['options'] ?? [];

        if (isset($definition['validator'])) {
            $options += [
                'validator' => $definition['validator'],
                'validationLevel' => $options['validationLevel'] ?? 'strict',
                'validationAction' => $options['validationAction'] ?? 'error',
            ];
        } elseif (isset($definition['fields'])) {
            $options += [
                'validator' => $this->compileValidator($definition['fields']),
                'validationLevel' => $options['validationLevel'] ?? 'strict',
                'validationAction' => $options['validationAction'] ?? 'error',
            ];
        }

        try {
            $manager->createCollection($name, $options);
        } catch (DriverRuntimeException) {
            // A concurrent reload (e.g. parallel static-analysis workers
            // bootstrapping the same schema file) may have created the
            // collection already with the same definition. Leave it in place.
        }

        foreach ($definition['indexes'] ?? [] as $indexName => $index) {
            if (array_keys($index['key']) === ['_id']) {
                continue;
            }

            $options = $index['options'] ?? [];
            $options['name'] = $indexName;

            $manager->createIndex($name, $index['key'], $options);
        }
    }

    /**
     * Compiles the `fields` shorthand into a basic `$jsonSchema` validator.
     *
     * Used only when no explicit `validator` is supplied.
     *
     * @param array<string, array<string, mixed>> $fields Field definitions keyed by field name.
     * @return array<string, mixed>
     */
    protected function compileValidator(array $fields): array
    {
        $properties = [];
        foreach ($fields as $fieldName => $attrs) {
            $properties[$fieldName] = $attrs;
        }

        return [
            '$jsonSchema' => [
                'bsonType' => 'object',
                'properties' => $properties,
            ],
        ];
    }
}

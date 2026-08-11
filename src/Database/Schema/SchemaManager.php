<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Schema;

use Crustum\Mongo\Database\Connection;
use MongoDB\Collection;
use MongoDB\Database;

/**
 * MongoDB schema manager (DDL).
 *
 * Provides collection and index lifecycle operations that the ODM layer needs:
 * create/drop/rename collections, validator management, and index (including
 * search/vector) management. Wraps the driver's `Database`/`Collection` API in
 * a Cake-friendly surface.
 *
 * @see mongodb-odm SchemaManager.php
 */
class SchemaManager
{
    /**
     * The connection.
     *
     * @var \Crustum\Mongo\Database\Connection
     */
    protected Connection $connection;

    /**
     * Constructor
     *
     * @param \Crustum\Mongo\Database\Connection $connection The connection
     */
    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Returns the underlying database.
     *
     * @return \MongoDB\Database
     */
    protected function database(): Database
    {
        return $this->connection->getDatabase();
    }

    /**
     * Returns a collection by name.
     *
     * @param string $name The collection name
     * @return \MongoDB\Collection
     */
    protected function collection(string $name): Collection
    {
        return $this->connection->getCollection($name);
    }

    /**
     * Returns the names of all collections in the database.
     *
     * @param bool $includeSystemCollections Whether to include system.* collections
     * @return list<string>
     */
    public function listCollections(bool $includeSystemCollections = false): array
    {
        $names = [];
        foreach ($this->database()->listCollections() as $collection) {
            $name = $collection->getName();
            if (!$includeSystemCollections && str_starts_with($name, 'system.')) {
                continue;
            }

            $names[] = $name;
        }

        sort($names);

        return $names;
    }

    /**
     * Creates a collection.
     *
     * @param string $name The collection name
     * @param array<string, mixed> $options Collection options (validator, validationLevel, capped, size, …)
     * @return bool true on success
     * @throws \MongoDB\Driver\Exception\BulkWriteException When the collection already exists (unique constraint on name).
     */
    public function createCollection(string $name, array $options = []): bool
    {
        $this->database()->createCollection($name, $options);

        return true;
    }

    /**
     * Drops a collection.
     *
     * The underlying driver returns void; the drop is idempotent in MongoDB.
     *
     * @param string $name The collection name
     * @return bool true on success
     */
    public function dropCollection(string $name): bool
    {
        $this->database()->dropCollection($name);

        return true;
    }

    /**
     * Renames a collection.
     *
     * The `renameCollection` command must run against the `admin` database,
     * so it is dispatched there regardless of the configured database.
     *
     * @param string $from The current collection name
     * @param string $to The new collection name
     * @param bool $dropTarget Whether to drop an existing target collection first
     * @return bool true on success
     */
    public function renameCollection(string $from, string $to, bool $dropTarget = false): bool
    {
        $command = [
            'renameCollection' => sprintf(
                '%s.%s',
                $this->database()->getDatabaseName(),
                $from,
            ),
            'to' => sprintf('%s.%s', $this->database()->getDatabaseName(), $to),
            'dropTarget' => $dropTarget,
        ];

        return $this->connection->getClient()
            ->selectDatabase('admin')
            ->command($command)
            ->toArray() !== [];
    }

    /**
     * Sets (or removes) the JSON schema validator of a collection.
     *
     * Passing `null` as the validator clears it (collMod expects an empty
     * document, never `null`).
     *
     * @param string $name The collection name
     * @param array<string, mixed>|null $validator The `$jsonSchema` rules, or null to clear the validator
     * @param string|null $validationLevel The validation level (off/ strict/ moderate)
     * @param string|null $validationAction The validation action (error/warn)
     * @return bool true on success
     */
    public function setValidator(
        string $name,
        ?array $validator,
        ?string $validationLevel = null,
        ?string $validationAction = null,
    ): bool {
        $options = ['validator' => $validator ?? (object)[]];
        if ($validationLevel !== null) {
            $options['validationLevel'] = $validationLevel;
        }

        if ($validationAction !== null) {
            $options['validationAction'] = $validationAction;
        }

        $this->database()->command([
            'collMod' => $name,
        ] + $options);

        return true;
    }

    /**
     * Sets the validator of a collection from a `Validator` value object.
     *
     * @param string $name The collection name
     * @param \Crustum\Mongo\Database\Schema\Validator $validator The validator value object
     * @param string|null $validationLevel The validation level (off/ strict/ moderate)
     * @param string|null $validationAction The validation action (error/warn)
     * @return bool true on success
     */
    public function setValidatorObject(
        string $name,
        Validator $validator,
        ?string $validationLevel = null,
        ?string $validationAction = null,
    ): bool {
        return $this->setValidator(
            $name,
            $validator->toArray(),
            $validationLevel,
            $validationAction,
        );
    }

    /**
     * Returns the validator (if any) of a collection.
     *
     * @param string $name The collection name
     * @return array<string, mixed>|null The validator rules, or null when the collection has none
     */
    public function getValidator(string $name): ?array
    {
        foreach ($this->database()->listCollections(['filter' => ['name' => $name]]) as $collection) {
            $options = $collection->getOptions();

            return $options['validator'] ?? null;
        }

        return null;
    }

    /**
     * Creates an index on a collection.
     *
     * @param string $name The collection name
     * @param array<string, int|string>|string $key The index key(s), e.g. `['field' => 1]` or `'field'`
     * @param array<string, mixed> $options Index options (unique, sparse, name, expireAfterSeconds, …)
     * @return string The index name
     */
    public function createIndex(string $name, array|string $key, array $options = []): string
    {
        $keys = is_string($key) ? [$key => 1] : $key;

        return $this->collection($name)->createIndex($keys, $options);
    }

    /**
     * Creates an index on a collection from an `Index` value object.
     *
     * @param string $name The collection name
     * @param \Crustum\Mongo\Database\Schema\Index $index The index value object
     * @return string The index name
     */
    public function createIndexObject(string $name, Index $index): string
    {
        return $this->collection($name)->createIndex(
            $index->getKey(),
            $index->createIndexOptions(),
        );
    }

    /**
     * Drops an index from a collection.
     *
     * The underlying driver returns void; the drop is idempotent in MongoDB.
     *
     * @param string $name The collection name
     * @param string $indexName The index name
     * @return bool true on success
     */
    public function dropIndex(string $name, string $indexName): bool
    {
        $this->collection($name)->dropIndex($indexName);

        return true;
    }

    /**
     * Returns the indexes of a collection.
     *
     * @param string $name The collection name
     * @return array<string, array<string, mixed>> Indexes keyed by index name
     */
    public function listIndexes(string $name): array
    {
        $indexes = [];
        foreach ($this->collection($name)->listIndexes() as $index) {
            $indexes[$index->getName()] = [
                'key' => $index->getKey(),
                'unique' => $index->isUnique(),
                'sparse' => $index->isSparse(),
            ];
        }

        ksort($indexes);

        return $indexes;
    }

    /**
     * Creates a search (Atlas / MongoDB 7+) index.
     *
     * @param string $name The collection name
     * @param object|array<string, mixed> $definition The search index definition
     * @param array<string, mixed> $options Options (name, type)
     * @return string The index name
     */
    public function createSearchIndex(string $name, array|object $definition, array $options = []): string
    {
        return $this->collection($name)->createSearchIndex($definition, $options);
    }

    /**
     * Creates multiple search indexes.
     *
     * @param string $name The collection name
     * @param list<array{definition: object|array<string, mixed>, name?: string, type?: string}> $indexes The search index definitions
     * @param array<string, mixed> $options Options
     * @return list<string> The index names
     */
    public function createSearchIndexes(string $name, array $indexes, array $options = []): array
    {
        return array_values($this->collection($name)->createSearchIndexes($indexes, $options));
    }

    /**
     * Returns the search indexes of a collection.
     *
     * @param string $name The collection name
     * @return array<int, array<string, mixed>> The search indexes
     */
    public function listSearchIndexes(string $name): array
    {
        return iterator_to_array($this->collection($name)->listSearchIndexes(), false);
    }

    /**
     * Drops a search index.
     *
     * @param string $name The collection name
     * @param string $indexName The search index name
     * @return void
     */
    public function dropSearchIndex(string $name, string $indexName): void
    {
        $this->collection($name)->dropSearchIndex($indexName);
    }
}

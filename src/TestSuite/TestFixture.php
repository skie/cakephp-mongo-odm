<?php
declare(strict_types=1);

namespace Crustum\Mongo\TestSuite;

use Cake\Core\Exception\CakeException;
use Cake\Datasource\ConnectionInterface;
use Cake\Datasource\FixtureInterface;
use Crustum\Mongo\Database\Connection;
use MongoDB\BSON\ObjectId;
use Throwable;

/**
 * Data-only fixture for MongoDB collections.
 *
 * Compatible with Cake's `FixtureHelper`/`TruncateStrategy`: `$records` hold
 * documents, `create()`/`drop()` manage the collection, `truncate()` empties it.
 *
 * @see cake50/src/TestSuite/Fixture/TestFixture.php
 */
class TestFixture implements FixtureInterface
{
    /**
     * The collection name.
     *
     * @var string
     */
    public string $table = '';

    /**
     * The connection name. Must start with `test`.
     *
     * @var string
     */
    public string $connection = 'test';

    /**
     * Collection creation options (capped, validation, ...).
     *
     * @var array<string, mixed>
     */
    public array $indexSettings = [];

    /**
     * Index definitions.
     *
     * @var array<string, mixed>
     */
    public array $schema = [];

    /**
     * Documents to insert.
     *
     * @var list<array<string, mixed>>
     */
    public array $records = [];

    /**
     * Connection names this fixture has been created on.
     *
     * @var list<string>
     */
    public array $created = [];

    /**
     * Constructor.
     *
     * @throws \Cake\Core\Exception\CakeException When the connection name does not start with `test`.
     */
    public function __construct()
    {
        if (!empty($this->connection) && !str_starts_with($this->connection, 'test')) {
            throw new CakeException(sprintf(
                'Invalid datasource name "%s" for "%s" fixture. Fixture datasource names must begin with "test".',
                $this->connection,
                $this->table,
            ));
        }

        $this->init();
    }

    /**
     * Hook for subclasses to configure the fixture.
     *
     * @return void
     */
    public function init(): void
    {
    }

    /**
     * @inheritDoc
     */
    public function create(ConnectionInterface $db): bool
    {
        if (!$db instanceof Connection) {
            return false;
        }

        if (empty($this->table)) {
            return false;
        }

        try {
            $database = $db->getDatabase();
            $database->dropCollection($this->table);
            $database->createCollection($this->table, $this->indexSettings);

            if ($this->schema !== []) {
                $db->getCollection($this->table)->createIndex($this->schema);
            }

            $this->created[] = $db->configName();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function insert(ConnectionInterface $db): bool
    {
        if (!$db instanceof Connection || $this->records === []) {
            return false;
        }

        $collection = $db->getCollection($this->table);

        $typeMap = [];
        try {
            $typeMap = $db->getSchemaCollection()->describe($this->table)->typeMap();
        } catch (Throwable) {
            // Schema metadata is best-effort; fall back to naming conventions.
        }

        foreach ($this->records as $record) {
            if (isset($record['id']) && !isset($record['_id'])) {
                $record['_id'] = $record['id'];
                unset($record['id']);
            }

            foreach ($record as $field => $value) {
                if (!is_string($value) || !preg_match('/^[0-9a-f]{24}$/', $value)) {
                    continue;
                }

                $isObjectId = $field === '_id'
                    || ($typeMap[$field] ?? null) === 'objectid';
                if ($isObjectId) {
                    $record[$field] = new ObjectId($value);
                }
            }

            $collection->insertOne($record);
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function drop(ConnectionInterface $db): bool
    {
        if (!$db instanceof Connection) {
            return false;
        }

        try {
            $db->getDatabase()->dropCollection($this->table);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function truncate(ConnectionInterface $db): bool
    {
        if (!$db instanceof Connection) {
            return false;
        }

        try {
            $db->getCollection($this->table)->deleteMany([]);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function connection(): string
    {
        return $this->connection;
    }

    /**
     * @inheritDoc
     */
    public function sourceName(): string
    {
        return $this->table;
    }

    /**
     * @inheritDoc
     */
    public function createConstraints(ConnectionInterface $connection): void
    {
    }

    /**
     * @inheritDoc
     */
    public function dropConstraints(ConnectionInterface $connection): void
    {
    }
}

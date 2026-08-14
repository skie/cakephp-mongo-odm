<?php
declare(strict_types=1);

/**
 * Ported and adapted from cakephp/migrations (MIT License).
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc.
 * @license https://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace Crustum\Mongo\Migration\Db;

use Crustum\Mongo\Migration\Adapter\AdapterInterface;
use Crustum\Mongo\Migration\Adapter\RecordingAdapter;
use Crustum\Mongo\Migration\Util\ColumnParser;
use RuntimeException;

/**
 * Fluent collection builder for migrations.
 *
 * Mirrors `Migrations\Db\Table` for MongoDB. Actions accumulate on the object
 * and are applied to the database when `create()` or `update()` runs:
 *
 * ```php
 * $this->collection('users')
 *     ->addField('username', 'string')
 *     ->addField('email', 'string')
 *     ->addIndex(['email'], ['unique' => true])
 *     ->create();
 * ```
 */
class Collection
{
    /**
     * Collection name.
     *
     * @var string
     */
    protected string $name;

    /**
     * Collection creation options.
     *
     * @var array<string, mixed>
     */
    protected array $options = [];

    /**
     * The adapter.
     *
     * @var \Crustum\Mongo\Migration\Adapter\AdapterInterface|null
     */
    protected ?AdapterInterface $adapter = null;

    /**
     * Field definitions keyed by field name.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $fields = [];

    /**
     * Index definitions keyed by index name.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $indexes = [];

    /**
     * The `$jsonSchema` validator, if set.
     *
     * @var array<string, mixed>|null
     */
    protected ?array $validator = null;

    /**
     * Whether the collection is being dropped.
     *
     * @var bool
     */
    protected bool $drop = false;

    /**
     * Whether the collection is being updated (existing collection).
     *
     * @var bool
     */
    protected bool $updating = false;

    /**
     * Constructor.
     *
     * @param string $name Collection name
     * @param array<string, mixed> $options Collection creation options
     * @param \Crustum\Mongo\Migration\Adapter\AdapterInterface|null $adapter Database adapter
     */
    public function __construct(string $name, array $options = [], ?AdapterInterface $adapter = null)
    {
        $this->name = $name;
        $this->options = $options;

        if ($adapter instanceof AdapterInterface) {
            $this->setAdapter($adapter);
        }
    }

    /**
     * Gets the collection name.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Gets the collection options.
     *
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Sets the database adapter.
     *
     * @param \Crustum\Mongo\Migration\Adapter\AdapterInterface $adapter Database adapter
     * @return $this
     */
    public function setAdapter(AdapterInterface $adapter): static
    {
        $this->adapter = $adapter;

        return $this;
    }

    /**
     * Gets the database adapter.
     *
     * @throws \RuntimeException
     * @return \Crustum\Mongo\Migration\Adapter\AdapterInterface
     */
    public function getAdapter(): AdapterInterface
    {
        if (!$this->adapter instanceof AdapterInterface) {
            throw new RuntimeException('There is no database adapter set yet, cannot proceed');
        }

        return $this->adapter;
    }

    /**
     * Does the collection have pending actions?
     *
     * @return bool
     */
    public function hasPendingActions(): bool
    {
        return $this->drop || $this->fields !== [] || $this->indexes !== [] || $this->validator !== null;
    }

    /**
     * Does the collection exist?
     *
     * @return bool
     */
    public function exists(): bool
    {
        return $this->getAdapter()->hasCollection($this->getName());
    }

    /**
     * Marks the collection for dropping.
     *
     * @return $this
     */
    public function drop(): static
    {
        $this->drop = true;

        return $this;
    }

    /**
     * Updates the collection with all pending actions.
     *
     * Applies the pending changes (fields, validator, indexes) to an existing
     * collection, merging new fields into the current validator.
     *
     * @return void
     */
    public function update(): void
    {
        $this->updating = true;
        $this->executeActions();
        $this->reset();
    }

    /**
     * Adds a field definition.
     *
     * Alias of `addColumn()` for SQL-style migrations.
     *
     * Type is a canonical Mongo type name (string, objectid, integer, boolean,
     * date, decimal128, hash, collection, …).
     *
     * @param string $name Field name
     * @param string|null $type Field type
     * @param array<string, mixed> $options Field options (null, default, length, …)
     * @return $this
     */
    public function addField(string $name, ?string $type = null, array $options = []): static
    {
        $parser = new ColumnParser();
        $this->fields[$name] = [
            'type' => $parser->mapType($type),
            'null' => (bool)($options['null'] ?? false),
        ];

        if (array_key_exists('default', $options)) {
            $this->fields[$name]['default'] = $options['default'];
        }

        if (isset($options['length'])) {
            $this->fields[$name]['length'] = $options['length'];
        }

        if (isset($options['precision'])) {
            $this->fields[$name]['precision'] = $options['precision'];
        }

        return $this;
    }

    /**
     * Adds a field definition.
     *
     * SQL-style alias of `addField()` so migrations written like
     * `->addColumn('username', 'string')` work unchanged.
     *
     * @param string $name Field name
     * @param string|null $type Field type
     * @param array<string, mixed> $options Field options (null, default, length, …)
     * @return $this
     */
    public function addColumn(string $name, ?string $type = null, array $options = []): static
    {
        return $this->addField($name, $type, $options);
    }

    /**
     * Removes a field definition.
     *
     * @param string $name Field name
     * @return $this
     */
    public function removeField(string $name): static
    {
        unset($this->fields[$name]);

        return $this;
    }

    /**
     * Checks whether a field is defined.
     *
     * @param string $name Field name
     * @return bool
     */
    public function hasField(string $name): bool
    {
        return isset($this->fields[$name]);
    }

    /**
     * Adds an index.
     *
     * @param array<string, int>|array<string>|string $key Index key(s) or field name(s)
     * @param array<string, mixed> $options Index options (unique, sparse, name, …)
     * @return $this
     */
    public function addIndex(array|string $key, array $options = []): static
    {
        if (is_string($key)) {
            $key = [$key];
        }

        if (array_is_list($key)) {
            $key = array_fill_keys($key, 1);
        }

        $indexName = $options['name'] ?? $this->defaultIndexName($key);
        $this->indexes[$indexName] = [
            'key' => $key,
            'options' => $options,
        ];

        return $this;
    }

    /**
     * Removes an index.
     *
     * @param string $indexName Index name
     * @return $this
     */
    public function removeIndex(string $indexName): static
    {
        unset($this->indexes[$indexName]);

        return $this;
    }

    /**
     * Sets the `$jsonSchema` validator.
     *
     * @param array<string, mixed> $validator The `$jsonSchema` rules
     * @return $this
     */
    public function setValidator(array $validator): static
    {
        $this->validator = $validator;

        return $this;
    }

    /**
     * Creates the collection (or updates it) with all pending actions.
     *
     * @return void
     */
    public function create(): void
    {
        $this->executeActions();
        $this->reset();
    }

    /**
     * Resets all pending changes.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->drop = false;
        $this->updating = false;
        $this->fields = [];
        $this->indexes = [];
        $this->validator = null;
    }

    /**
     * Applies all pending actions to the database.
     *
     * When the adapter is a `RecordingAdapter` (i.e. a `change()` migration is
     * being reversed for the `down` direction), `create()` must record a
     * `createCollection` command even if the collection already exists in the
     * database — otherwise the recorded command would be a `setValidator` whose
     * inverse cannot drop the collection, and the rollback would silently leak
     * the DDL.
     *
     * @return void
     */
    protected function executeActions(): void
    {
        $adapter = $this->getAdapter();

        if ($this->drop) {
            $adapter->dropCollection($this->getName());

            return;
        }

        if (!$this->updating && (!$this->exists() || $adapter instanceof RecordingAdapter)) {
            $options = $this->options;
            $validator = $this->buildValidator();
            if ($validator !== null) {
                $options['validator'] = $validator;
            }

            $adapter->createCollection($this->getName(), $options);
        } elseif ($this->validator !== null || $this->fields !== []) {
            $validator = $this->buildValidator();
            $adapter->setValidator($this->getName(), $validator);
        }

        foreach ($this->indexes as $indexName => $index) {
            $options = $index['options'];
            $options['name'] = $indexName;
            $adapter->createIndex($this->getName(), $index['key'], $options);
        }
    }

    /**
     * Builds the `$jsonSchema` validator from declared fields, merging any
     * explicitly set validator.
     *
     * @return array<string, mixed>|null
     */
    protected function buildValidator(): ?array
    {
        if ($this->validator !== null) {
            return $this->validator;
        }

        if ($this->fields === []) {
            return null;
        }

        $properties = [];
        if ($this->updating) {
            $existing = $this->getExistingValidator();
            if (isset($existing['$jsonSchema']['properties'])) {
                $properties = $existing['$jsonSchema']['properties'];
            }
        }

        foreach ($this->fields as $name => $field) {
            $properties[$name] = ['bsonType' => $this->bsonType($field['type'])];
        }

        return [
            '$jsonSchema' => [
                'bsonType' => 'object',
                'properties' => $properties,
                'additionalProperties' => true,
            ],
        ];
    }

    /**
     * Reads the current validator of the collection from the database.
     *
     * Used when updating an existing collection so new fields are merged into
     * the existing validator instead of replacing it.
     *
     * @return array<string, mixed>
     */
    protected function getExistingValidator(): array
    {
        $validator = $this->getAdapter()->getSchemaManager()->getValidator($this->getName());

        return is_array($validator) ? $validator : [];
    }

    /**
     * Maps a canonical Mongo type to a BSON type spelling for `$jsonSchema`.
     *
     * @param string $type Canonical type name
     * @return string BSON type spelling
     */
    protected function bsonType(string $type): string
    {
        return match ($type) {
            'objectid' => 'objectId',
            'integer', 'int' => 'int',
            'int64' => 'long',
            'float' => 'double',
            'decimal128' => 'decimal',
            'boolean', 'bool' => 'bool',
            'date', 'datetime' => 'date',
            'timestamp' => 'timestamp',
            'binary' => 'binData',
            'hash', 'object' => 'object',
            'array', 'collection' => 'array',
            default => 'string',
        };
    }

    /**
     * Computes the default index name MongoDB generates for a key map.
     *
     * @param array<string, int|string> $key The index key(s)
     * @return string The generated index name
     */
    protected function defaultIndexName(array $key): string
    {
        $parts = [];
        foreach ($key as $field => $direction) {
            $suffix = match (true) {
                $direction === 1 => '1',
                $direction === -1 => '-1',
                default => (string)$direction,
            };
            $parts[] = $field . '_' . $suffix;
        }

        return implode('_', $parts);
    }
}

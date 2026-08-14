<?php
declare(strict_types=1);

/**
 * Ported and adapted from cakephp/migrations (MIT License).
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc.
 * @license https://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace Crustum\Mongo\Migration\Db;

use Crustum\Mongo\Migration\Db\Action\AddField;
use Crustum\Mongo\Migration\Db\Action\AddIndex;
use Crustum\Mongo\Migration\Db\Action\CreateCollection;
use Crustum\Mongo\Migration\Db\Action\DropCollection;
use Crustum\Mongo\Migration\Db\Action\DropIndex;
use Crustum\Mongo\Migration\Db\Action\RemoveField;
use Crustum\Mongo\Migration\Db\Action\SetValidator;
use Crustum\Mongo\Migration\Db\Adapter\AdapterInterface;
use Crustum\Mongo\Migration\Db\Plan\Intent;
use Crustum\Mongo\Migration\Db\Plan\Plan;
use Crustum\Mongo\Migration\Util\ColumnParser;
use RuntimeException;

/**
 * Fluent collection builder for migrations.
 *
 * Mirrors `Migrations\Db\Table` for MongoDB: pending changes accumulate on the
 * object and are turned into an `Intent` + `Plan` when `create()` or `update()`
 * runs:
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
     * @var \Crustum\Mongo\Migration\Db\Adapter\AdapterInterface|null
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
     * Fields marked for removal (update).
     *
     * @var array<string, true>
     */
    protected array $removedFields = [];

    /**
     * Indexes marked for removal (update).
     *
     * @var array<string, true>
     */
    protected array $droppedIndexes = [];

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
     * @param \Crustum\Mongo\Migration\Db\Adapter\AdapterInterface|null $adapter Database adapter
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
     * @param \Crustum\Mongo\Migration\Db\Adapter\AdapterInterface $adapter Database adapter
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
     * @return \Crustum\Mongo\Migration\Db\Adapter\AdapterInterface
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
        return $this->drop
            || $this->fields !== []
            || $this->removedFields !== []
            || $this->indexes !== []
            || $this->droppedIndexes !== []
            || $this->validator !== null;
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
        $this->execute($this->buildUpdateIntent());
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
            'options' => $options,
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
     * When applied via `update()`, the field is dropped from the validator.
     *
     * @param string $name Field name
     * @return $this
     */
    public function removeField(string $name): static
    {
        unset($this->fields[$name]);
        $this->removedFields[$name] = true;

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
     * When applied via `update()`, the index is dropped.
     *
     * @param string $indexName Index name
     * @return $this
     */
    public function removeIndex(string $indexName): static
    {
        unset($this->indexes[$indexName]);
        $this->droppedIndexes[$indexName] = true;

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
     * Creates the collection (or drops it) with all pending actions.
     *
     * @return void
     */
    public function create(): void
    {
        $this->execute($this->buildCreateIntent());
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
        $this->removedFields = [];
        $this->indexes = [];
        $this->droppedIndexes = [];
        $this->validator = null;
    }

    /**
     * Builds the intent for a create operation.
     *
     * @return \Crustum\Mongo\Migration\Db\Plan\Intent
     */
    protected function buildCreateIntent(): Intent
    {
        $intent = new Intent();

        if ($this->drop) {
            $intent->addAction(new DropCollection($this->getName()));

            return $intent;
        }

        $intent->addAction(new CreateCollection($this->getName(), $this->options));

        foreach ($this->fields as $name => $field) {
            $intent->addAction(new AddField($this->getName(), $name, $field['type'], $field['options'] ?? []));
        }

        $this->collectIndexAdds($intent);

        return $intent;
    }

    /**
     * Builds the intent for an update operation.
     *
     * @return \Crustum\Mongo\Migration\Db\Plan\Intent
     */
    protected function buildUpdateIntent(): Intent
    {
        $intent = new Intent();

        if ($this->validator !== null) {
            $intent->addAction(new SetValidator($this->getName(), $this->validator));
        } else {
            foreach ($this->fields as $name => $field) {
                $intent->addAction(new AddField($this->getName(), $name, $field['type'], $field['options'] ?? []));
            }

            foreach (array_keys($this->removedFields) as $name) {
                $intent->addAction(new RemoveField($this->getName(), $name));
            }
        }

        $this->collectIndexAdds($intent);

        foreach (array_keys($this->droppedIndexes) as $indexName) {
            $intent->addAction(new DropIndex($this->getName(), $indexName));
        }

        return $intent;
    }

    /**
     * Collects AddIndex actions for the pending index definitions.
     *
     * @param \Crustum\Mongo\Migration\Db\Plan\Intent $intent The intent
     * @return void
     */
    protected function collectIndexAdds(Intent $intent): void
    {
        foreach ($this->indexes as $indexName => $index) {
            $intent->addAction(new AddIndex($this->getName(), $indexName, $index['key'], $index['options']));
        }
    }

    /**
     * Executes an intent through a resolved plan against the adapter.
     *
     * @param \Crustum\Mongo\Migration\Db\Plan\Intent $intent The intent
     * @return void
     */
    protected function execute(Intent $intent): void
    {
        (new Plan($intent))->execute($this->getAdapter());
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

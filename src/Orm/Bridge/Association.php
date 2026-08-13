<?php
declare(strict_types=1);

namespace Crustum\Mongo\Orm\Bridge;

use Cake\Datasource\EntityInterface;
use Cake\ORM\Table;
use Cake\Utility\Inflector;
use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\ODM\Document;
use Crustum\Mongo\ODM\Query\SelectQuery;
use Crustum\Mongo\Orm\Bridge\Row\DocumentWrapper;
use InvalidArgumentException;
use function Cake\Core\pluginSplit;

/**
 * Base cross-boundary association (Direction 1: ORM Table source → Mongo Collection target).
 *
 * A bridge association describes how a Cake ORM `Table` row relates to Mongo
 * `Document`s. It does **not** use SQL joins — loading is a batched Mongo query
 * (`whereIn` + dictionary match), mirroring the ODM `STRATEGY_SELECT`.
 *
 * This class is load-focused (P2). Write/save + cascade come in later phases
 * (P3/P5).
 *
 * @see docs/reference/29-orm-mongo-association-bridge.md §4–§7
 */
abstract class Association
{
    /**
     * Association alias.
     *
     * @var string
     */
    protected string $name;

    /**
     * The ORM source table (Direction 1 source).
     *
     * @var \Cake\ORM\Table
     */
    protected Table $source;

    /**
     * Target collection options (alias or class) resolved via the locator.
     *
     * @var string
     */
    protected string $className;

    /**
     * Foreign key on the owning side.
     *
     * For BelongsTo: the SQL column holding the Mongo `_id`.
     * For HasOne/HasMany: the Mongo field holding the SQL `id`.
     *
     * @var array<string>|string
     */
    protected array|string $foreignKey;

    /**
     * Binding key on the matched side.
     *
     * Defaults to the target Mongo `_id` (BelongsTo) or source SQL primary key
     * (HasOne/HasMany).
     *
     * @var array<string>|string
     */
    protected array|string $bindingKey;

    /**
     * Entity property the loaded association is attached to.
     *
     * @var string
     */
    protected string $propertyName;

    /**
     * Target conditions applied to the batched load.
     *
     * @var array<string, mixed>
     */
    protected array $conditions = [];

    /**
     * Whether target documents are cascade-deleted with the source row.
     *
     * @var string|bool
     */
    protected bool|string $dependent = false;

    /**
     * Whether loaded Mongo documents are wrapped into ORM `Entity`s.
     *
     * @var bool
     */
    protected bool $autoWrap = false;

    /**
     * Constructor.
     *
     * @param string $name Association alias.
     * @param \Cake\ORM\Table $source The ORM source table.
     * @param array<string, mixed> $options Association options.
     */
    public function __construct(string $name, Table $source, array $options = [])
    {
        $this->name = $name;
        $this->source = $source;
        $this->className = (string)($options['className'] ?? $name);
        $this->propertyName = (string)($options['property'] ?? $this->defaultProperty());
        $this->foreignKey = $options['foreignKey'] ?? $this->defaultForeignKey();
        $this->bindingKey = $options['bindingKey'] ?? $this->defaultBindingKey();
        $this->conditions = (array)($options['conditions'] ?? []);
        $this->dependent = $options['dependent'] ?? false;
        $this->autoWrap = (bool)($options['autoWrap'] ?? false);
    }

    /**
     * Gets the association alias.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Gets the ORM source table.
     *
     * @return \Cake\ORM\Table
     */
    public function getSource(): Table
    {
        return $this->source;
    }

    /**
     * Gets the target Mongo collection, resolving it lazily.
     *
     * @return \Crustum\Mongo\ODM\BaseCollection
     * @throws \InvalidArgumentException When the alias does not resolve to a collection.
     */
    public function getTarget(): BaseCollection
    {
        if ($this->source instanceof MongoCollectionAwareInterface) {
            return $this->source->getMongoCollection($this->className);
        }

        throw new InvalidArgumentException(sprintf(
            'Source table `%s` must implement `%s` to resolve Mongo target `%s`.',
            $this->source::class,
            MongoCollectionAwareInterface::class,
            $this->className,
        ));
    }

    /**
     * Builds a lazy query against the target Mongo collection.
     *
     * Mirrors cake `Association::find()`: the query is returned un-executed so
     * the caller decides when to materialize (`->all()`, `->first()`). The
     * association `conditions` are pre-applied.
     *
     * @param string $type Finder name.
     * @param mixed ...$args Finder arguments.
     * @return \Crustum\Mongo\ODM\Query\SelectQuery
     */
    public function find(string $type = 'all', mixed ...$args): SelectQuery
    {
        $query = $this->getTarget()->find($type, ...$args);
        if ($this->getConditions() !== []) {
            $query->where($this->getConditions());
        }

        return $query;
    }

    /**
     * Persists a dirty cross-association value for a source row.
     *
     * Direction-1 save hook used by `saveWithBridge()`: writes the associated
     * data to the target Mongo collection. The base implementation marshals
     * the value into a document carrying the foreign key and saves it via the
     * target collection; subclasses override when the write shape differs
     * (HasMany writes a list of documents).
     *
     * @param \Cake\Datasource\EntityInterface $entity The source entity.
     * @param mixed $value The dirty association value.
     * @return bool Whether the write succeeded.
     */
    public function save(EntityInterface $entity, mixed $value): bool
    {
        $data = is_array($value) ? $value : ['data' => $value];
        $data[$this->foreignKey()] = $entity->get($this->bindingKey());

        $collection = $this->getTarget();
        $document = $collection->newDocument($data);

        return $collection->save($document) !== false;
    }

    /**
     * Cascades a source-row delete to the target Mongo documents.
     *
     * Direction-1 delete hook used by `deleteWithBridge()`. The base
     * implementation is a no-op; subclasses apply the `dependent` matrix
     * (doc 29 §10): HasOne deletes the target, HasMany deletes or nullifies,
     * BelongsToMany clears links, DBRef does nothing.
     *
     * @param \Cake\Datasource\EntityInterface $entity The source entity being deleted.
     * @param array<string, mixed> $options Delete options.
     * @return bool Whether the cascade succeeded.
     */
    public function cascadeDelete(EntityInterface $entity, array $options = []): bool
    {
        return true;
    }

    /**
     * Single foreign key (string) used for writes.
     *
     * @return string
     */
    protected function foreignKey(): string
    {
        $key = $this->getForeignKey();

        return is_array($key) ? ($key[0] ?? '') : $key;
    }

    /**
     * Single binding key (string) read from the source row.
     *
     * @return string
     */
    protected function bindingKey(): string
    {
        $key = $this->getBindingKey();

        return is_array($key) ? ($key[0] ?? '_id') : $key;
    }

    /**
     * Gets the foreign key.
     *
     * @return array<string>|string
     */
    public function getForeignKey(): array|string
    {
        return $this->foreignKey;
    }

    /**
     * Sets the foreign key.
     *
     * @param array<string>|string $key Foreign key fields.
     * @return $this
     */
    public function setForeignKey(array|string $key): static
    {
        $this->foreignKey = $key;

        return $this;
    }

    /**
     * Gets the binding key.
     *
     * @return array<string>|string
     */
    public function getBindingKey(): array|string
    {
        return $this->bindingKey;
    }

    /**
     * Sets the binding key.
     *
     * @param array<string>|string $key Binding key fields.
     * @return $this
     */
    public function setBindingKey(array|string $key): static
    {
        $this->bindingKey = $key;

        return $this;
    }

    /**
     * Gets the entity property name.
     *
     * @return string
     */
    public function getProperty(): string
    {
        return $this->propertyName;
    }

    /**
     * Sets the entity property name.
     *
     * @param string $property Property name.
     * @return $this
     */
    public function setProperty(string $property): static
    {
        $this->propertyName = $property;

        return $this;
    }

    /**
     * Gets the target conditions.
     *
     * @return array<string, mixed>
     */
    public function getConditions(): array
    {
        return $this->conditions;
    }

    /**
     * Sets the target conditions.
     *
     * @param array<string, mixed> $conditions Conditions.
     * @return $this
     */
    public function setConditions(array $conditions): static
    {
        $this->conditions = $conditions;

        return $this;
    }

    /**
     * Whether target documents are cascade-deleted with the source row.
     *
     * @return string|bool
     */
    public function getDependent(): bool|string
    {
        return $this->dependent;
    }

    /**
     * Sets whether target documents are cascade-deleted.
     *
     * @param string|bool $dependent `true` (delete), `'nullify'`, or `false`.
     * @return $this
     */
    public function setDependent(bool|string $dependent): static
    {
        $this->dependent = $dependent;

        return $this;
    }

    /**
     * Whether loaded documents are wrapped into ORM `Entity`s.
     *
     * @return bool
     */
    public function shouldAutoWrap(): bool
    {
        return $this->autoWrap;
    }

    /**
     * Loads the association for a set of source rows and attaches results.
     *
     * Runs one batched Mongo query, matches by key, and sets the association
     * property on each source entity.
     *
     * @param iterable<\Cake\Datasource\EntityInterface|array<string, mixed>> $entities Source rows.
     * @return void
     */
    public function load(iterable $entities): void
    {
        $rows = [];
        $keys = [];
        foreach ($entities as $row) {
            $rows[] = $row;
            $value = $this->extractSourceKey($row);
            if ($value !== null && $value !== '') {
                $keys[(string)$value] = $value;
            }
        }

        $map = $keys === [] ? [] : $this->loadByKeys(array_values($keys));

        foreach ($rows as $row) {
            $this->injectRow($row, $map);
        }
    }

    /**
     * Loads the target documents matching the given source keys and builds a
     * keyed map (one batched Mongo query).
     *
     * @param list<int|string> $keys Source key values.
     * @return array<string, mixed> Map of target-key string to document(s).
     */
    abstract public function loadByKeys(array $keys): array;

    /**
     * Injects the loaded association value into a single source row.
     *
     * Reads the source key from the row, looks it up in the map, and attaches
     * the result under the association property (or the given nest key).
     *
     * @param \Cake\Datasource\EntityInterface|array<string, mixed> $row The source row.
     * @param array<string, mixed> $map Keyed map built by {@see loadByKeys()}.
     * @param string|null $nestKey Override for the property name (eager-loader alias path).
     * @return \Cake\Datasource\EntityInterface|array<string, mixed>
     */
    public function injectRow(EntityInterface|array $row, array $map, ?string $nestKey = null): EntityInterface|array
    {
        $key = $this->extractSourceKey($row);
        $value = $key !== null ? ($map[(string)$key] ?? $this->emptyValue()) : $this->emptyValue();
        $this->attachToRow($row, $this->wrapValue($value), $nestKey);

        return $row;
    }

    /**
     * Wraps loaded documents when `autoWrap` is enabled.
     *
     * A single `Document` becomes a `DocumentWrapper`; a list becomes a list
     * of wrappers.
     *
     * @param mixed $value The loaded association value.
     * @return mixed
     */
    protected function wrapValue(mixed $value): mixed
    {
        if (!$this->autoWrap) {
            return $value;
        }

        if (is_array($value)) {
            $wrapped = [];
            foreach ($value as $key => $item) {
                $wrapped[$key] = $item instanceof Document ? new DocumentWrapper($item) : $item;
            }

            return $wrapped;
        }

        return $value instanceof Document ? new DocumentWrapper($value) : $value;
    }

    /**
     * Extracts the source-side key field used to build the batched filter.
     *
     * BelongsTo reads the SQL foreign key column; HasOne/HasMany read the SQL
     * primary key (binding key).
     *
     * @param \Cake\Datasource\EntityInterface|array<string, mixed> $row The source row.
     * @return mixed
     */
    protected function extractSourceKey(EntityInterface|array $row): mixed
    {
        return $this->extractField($row, $this->sourceKeyField());
    }

    /**
     * Source-side field whose value is matched against the target.
     *
     * @return string
     */
    abstract protected function sourceKeyField(): string;

    /**
     * Default value attached when nothing matches (null for singular, [] for many).
     *
     * @return mixed
     */
    abstract protected function emptyValue(): mixed;

    /**
     * Public accessor for the default empty value.
     *
     * @return mixed
     */
    public function getEmptyValue(): mixed
    {
        return $this->emptyValue();
    }

    /**
     * Public accessor for the source-side key field.
     *
     * @return string
     */
    public function getSourceKeyField(): string
    {
        return $this->sourceKeyField();
    }

    /**
     * Extracts a single field value from an entity or array row.
     *
     * @param \Cake\Datasource\EntityInterface|array<string, mixed> $row The row.
     * @param string $field The field name.
     * @return mixed
     */
    protected function extractField(EntityInterface|array $row, string $field): mixed
    {
        if ($row instanceof EntityInterface) {
            return $row->get($field);
        }

        return $row[$field] ?? null;
    }

    /**
     * Attaches a loaded value to a source entity/array row under the property.
     *
     * @param \Cake\Datasource\EntityInterface|array<string, mixed> $row The row.
     * @param mixed $value The loaded value.
     * @param string|null $nestKey Override for the property name.
     * @return void
     */
    protected function attachToRow(EntityInterface|array &$row, mixed $value, ?string $nestKey = null): void
    {
        $property = $nestKey ?? $this->propertyName;
        if ($row instanceof EntityInterface) {
            $row->set($property, $value);

            return;
        }

        $row[$property] = $value;
    }

    /**
     * Default foreign key derived from the association name.
     *
     * @return string
     */
    abstract protected function defaultForeignKey(): string;

    /**
     * Default binding key for the target.
     *
     * @return string
     */
    abstract protected function defaultBindingKey(): string;

    /**
     * Default entity property name.
     *
     * Mirrors cake60 `Association::propertyName()`.
     *
     * @return string
     */
    protected function defaultProperty(): string
    {
        [, $name] = pluginSplit($this->name);

        return Inflector::underscore($name);
    }

    /**
     * Builds the singular underscored foreign key for an association name.
     *
     * Mirrors cake60 `ConventionsTrait::modelKey()`.
     *
     * @param string $name Model class or alias name.
     * @return string
     */
    protected function modelKey(string $name): string
    {
        [, $name] = pluginSplit($name);

        return Inflector::underscore(Inflector::singularize($name)) . '_id';
    }
}

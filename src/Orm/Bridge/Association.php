<?php
declare(strict_types=1);

namespace Crustum\Mongo\Orm\Bridge;

use Cake\Datasource\EntityInterface;
use Cake\ORM\Table;
use Cake\Utility\Inflector;
use Crustum\Mongo\ODM\BaseCollection;
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
        $this->dependent = (bool)($options['dependent'] ?? false);
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
     * @param iterable<\Cake\Datasource\EntityInterface> $entities Source entities.
     * @return void
     */
    abstract public function load(iterable $entities): void;

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
     * @return void
     */
    protected function attachToRow(EntityInterface|array &$row, mixed $value): void
    {
        if ($row instanceof EntityInterface) {
            $row->set($this->propertyName, $value);

            return;
        }

        $row[$this->propertyName] = $value;
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

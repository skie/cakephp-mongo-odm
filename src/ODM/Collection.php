<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM;

use BadMethodCallException;
use Cake\Datasource\EntityInterface;
use Cake\Datasource\QueryInterface;
use Cake\Datasource\RepositoryInterface;
use Cake\Datasource\SchemaInterface;
use Cake\Event\Event;
use Cake\Event\EventInterface;
use Cake\Event\EventManager;
use Cake\Event\EventManagerInterface;
use Cake\Validation\Validator;
use Closure;
use Crustum\Mongo\Database\Connection;
use Psr\SimpleCache\CacheInterface;

/**
 * ODM collection (repository) base class.
 *
 * Maps Cake's `Cake\ORM\Table` onto a MongoDB collection. This is the basic
 * skeleton: the full repository behavior (persistence, finders, events,
 * validation) is implemented in Phase 2. Methods required by the datasource
 * contract return safe defaults or fail loudly, so the ODM layer can be typed
 * against this class instead of duck-typed objects.
 *
 * @see cake60/src/ORM/Table.php
 * @see 15-odm-phase-2-collection.md
 */
class Collection implements RepositoryInterface
{
    use CollectionEventsTrait;

    /**
     * Registered collection alias.
     *
     * @var string|null
     */
    protected ?string $alias = null;

    /**
     * Fully-qualified registry alias.
     *
     * @var string|null
     */
    protected ?string $registryAlias = null;

    /**
     * Connection used by this collection.
     *
     * @var \Crustum\Mongo\Database\Connection|null
     */
    protected ?Connection $connection = null;

    /**
     * Registered associations.
     *
     * @var \Crustum\Mongo\ODM\AssociationCollection
     */
    protected AssociationCollection $associations;

    /**
     * Event manager for model events.
     *
     * @var \Cake\Event\EventManagerInterface
     */
    protected EventManagerInterface $eventManager;

    /**
     * Collection schema.
     *
     * @var \Cake\Datasource\SchemaInterface|null
     */
    protected ?SchemaInterface $schema = null;

    /**
     * Default validator.
     *
     * @var \Cake\Validation\Validator|null
     */
    protected ?Validator $validator = null;

    /**
     * Constructor.
     *
     * @param array<string, mixed> $options Collection options.
     */
    public function __construct(array $options = [])
    {
        $this->alias = isset($options['alias']) ? (string)$options['alias'] : null;
        $this->registryAlias = isset($options['registryAlias'])
            ? (string)$options['registryAlias']
            : $this->alias;
        $this->connection = isset($options['connection']) && $options['connection'] instanceof Connection
            ? $options['connection']
            : null;
        $this->associations = isset($options['associations']) && $options['associations'] instanceof AssociationCollection
            ? $options['associations']
            : new AssociationCollection();
        $this->schema = isset($options['schema']) && $options['schema'] instanceof SchemaInterface
            ? $options['schema']
            : null;
        $this->validator = isset($options['validator']) && $options['validator'] instanceof Validator
            ? $options['validator']
            : null;
        $this->eventManager = new EventManager();
    }

    /**
     * Sets the collection alias.
     *
     * @param string $alias The alias.
     * @return $this
     */
    public function setAlias(string $alias): static
    {
        $this->alias = $alias;

        return $this;
    }

    /**
     * Gets the collection alias.
     *
     * @return string
     * @throws \BadMethodCallException When the alias is not configured.
     */
    public function getAlias(): string
    {
        if ($this->alias === null) {
            throw new BadMethodCallException('Collection alias is not set.');
        }

        return $this->alias;
    }

    /**
     * Prefixes a field name with the collection alias.
     *
     * @param string $field The field name.
     * @return string
     */
    public function aliasField(string $field): string
    {
        return $this->getAlias() . '.' . $field;
    }

    /**
     * Sets the fully-qualified registry alias.
     *
     * @param string $registryAlias The registry alias.
     * @return $this
     */
    public function setRegistryAlias(string $registryAlias): static
    {
        $this->registryAlias = $registryAlias;

        return $this;
    }

    /**
     * Gets the fully-qualified registry alias.
     *
     * @return string
     */
    public function getRegistryAlias(): string
    {
        return $this->registryAlias ?? $this->getAlias();
    }

    /**
     * Whether the schema defines the given field.
     *
     * @param string $field The field name.
     * @return bool
     */
    public function hasField(string $field): bool
    {
        return false;
    }

    /**
     * Creates a query for this collection.
     *
     * @param string $type Finder name.
     * @param mixed ...$args Finder arguments.
     * @return \Cake\Datasource\QueryInterface
     * @throws \BadMethodCallException When finders are not implemented.
     */
    public function find(string $type = 'all', mixed ...$args): QueryInterface
    {
        throw new BadMethodCallException('Collection::find() is implemented in Phase 2.');
    }

    /**
     * Fetches a single document by primary key.
     *
     * @param mixed $primaryKey The primary key value.
     * @param array<string, mixed>|string $finder Finder or options.
     * @param \Psr\SimpleCache\CacheInterface|string|null $cache Cache engine or name.
     * @param \Closure|string|null $cacheKey Cache key.
     * @param mixed ...$args Finder arguments.
     * @return \Cake\Datasource\EntityInterface
     * @throws \BadMethodCallException When reads are not implemented.
     */
    public function get(
        mixed $primaryKey,
        array|string $finder = 'all',
        CacheInterface|string|null $cache = null,
        Closure|string|null $cacheKey = null,
        mixed ...$args,
    ): EntityInterface {
        throw new BadMethodCallException('Collection::get() is implemented in Phase 2.');
    }

    /**
     * Creates a new query instance for this collection.
     *
     * @return \Cake\Datasource\QueryInterface
     * @throws \BadMethodCallException When queries are not implemented.
     */
    public function query(): QueryInterface
    {
        throw new BadMethodCallException('Collection::query() is implemented in Phase 2.');
    }

    /**
     * Updates matching documents.
     *
     * @param \Closure|array<string, mixed>|string $fields Update specification.
     * @param \Closure|array<string, mixed>|string|null $conditions Filter conditions.
     * @return int
     * @throws \BadMethodCallException When writes are not implemented.
     */
    public function updateAll(Closure|array|string $fields, Closure|array|string|null $conditions): int
    {
        throw new BadMethodCallException('Collection::updateAll() is implemented in Phase 2.');
    }

    /**
     * Deletes matching documents.
     *
     * @param \Closure|array<string, mixed>|string|null $conditions Filter conditions.
     * @return int
     * @throws \BadMethodCallException When writes are not implemented.
     */
    public function deleteAll(Closure|array|string|null $conditions): int
    {
        throw new BadMethodCallException('Collection::deleteAll() is implemented in Phase 2.');
    }

    /**
     * Whether any document matches the conditions.
     *
     * @param \Closure|array<string, mixed>|string|null $conditions Filter conditions.
     * @return bool
     * @throws \BadMethodCallException When reads are not implemented.
     */
    public function exists(Closure|array|string|null $conditions): bool
    {
        throw new BadMethodCallException('Collection::exists() is implemented in Phase 2.');
    }

    /**
     * Persists a document.
     *
     * @param \Cake\Datasource\EntityInterface $entity The document.
     * @param array<string, mixed> $options Save options.
     * @return \Cake\Datasource\EntityInterface|false
     * @throws \BadMethodCallException When writes are not implemented.
     */
    public function save(EntityInterface $entity, array $options = []): EntityInterface|false
    {
        throw new BadMethodCallException('Collection::save() is implemented in Phase 2.');
    }

    /**
     * Deletes a document.
     *
     * @param \Cake\Datasource\EntityInterface $entity The document.
     * @param array<string, mixed> $options Delete options.
     * @return bool
     * @throws \BadMethodCallException When writes are not implemented.
     */
    public function delete(EntityInterface $entity, array $options = []): bool
    {
        throw new BadMethodCallException('Collection::delete() is implemented in Phase 2.');
    }

    /**
     * Creates an empty document.
     *
     * @return \Cake\Datasource\EntityInterface
     * @throws \BadMethodCallException When marshalling is not implemented.
     */
    public function newEmptyEntity(): EntityInterface
    {
        throw new BadMethodCallException('Collection::newEmptyEntity() is implemented in Phase 2.');
    }

    /**
     * Marshals one document from input data.
     *
     * @param array<string, mixed> $data Input data.
     * @param array<string, mixed> $options Marshalling options.
     * @return \Cake\Datasource\EntityInterface
     * @throws \BadMethodCallException When marshalling is not implemented.
     */
    public function newEntity(array $data, array $options = []): EntityInterface
    {
        throw new BadMethodCallException('Collection::newEntity() is implemented in Phase 2.');
    }

    /**
     * Marshals multiple documents from input data.
     *
     * @param array<int, mixed> $data Input rows.
     * @param array<string, mixed> $options Marshalling options.
     * @return array<int, \Cake\Datasource\EntityInterface>
     * @throws \BadMethodCallException When marshalling is not implemented.
     */
    public function newEntities(array $data, array $options = []): array
    {
        throw new BadMethodCallException('Collection::newEntities() is implemented in Phase 2.');
    }

    /**
     * Merges input data into an existing document.
     *
     * @param \Cake\Datasource\EntityInterface $entity The document.
     * @param array<string, mixed> $data Input data.
     * @param array<string, mixed> $options Marshalling options.
     * @return \Cake\Datasource\EntityInterface
     * @throws \BadMethodCallException When marshalling is not implemented.
     */
    public function patchEntity(EntityInterface $entity, array $data, array $options = []): EntityInterface
    {
        throw new BadMethodCallException('Collection::patchEntity() is implemented in Phase 2.');
    }

    /**
     * Merges input rows into matching documents.
     *
     * @param iterable<mixed> $entities Existing documents.
     * @param array<int, mixed> $data Input rows.
     * @param array<string, mixed> $options Marshalling options.
     * @return array<int, \Cake\Datasource\EntityInterface>
     * @throws \BadMethodCallException When marshalling is not implemented.
     */
    public function patchEntities(iterable $entities, array $data, array $options = []): array
    {
        throw new BadMethodCallException('Collection::patchEntities() is implemented in Phase 2.');
    }

    /**
     * Gets the connection used by this collection.
     *
     * @return \Crustum\Mongo\Database\Connection|null
     */
    public function getConnection(): ?Connection
    {
        return $this->connection;
    }

    /**
     * Sets the connection used by this collection.
     *
     * @param \Crustum\Mongo\Database\Connection $connection The connection.
     * @return $this
     */
    public function setConnection(Connection $connection): static
    {
        $this->connection = $connection;

        return $this;
    }

    /**
     * Gets the collection schema.
     *
     * @return \Cake\Datasource\SchemaInterface|null
     */
    public function getSchema(): ?SchemaInterface
    {
        return $this->schema;
    }

    /**
     * Gets an association by alias.
     *
     * @param string $name The association alias.
     * @return \Crustum\Mongo\ODM\Association|null The association or null when not registered.
     */
    public function getAssociation(string $name): ?Association
    {
        return $this->associations->get($name);
    }

    /**
     * Gets the registered associations.
     *
     * @return \Crustum\Mongo\ODM\AssociationCollection
     */
    public function associations(): AssociationCollection
    {
        return $this->associations;
    }

    /**
     * Gets the entity class used for hydrated documents.
     *
     * @return class-string<\Crustum\Mongo\ODM\Document>
     */
    public function getEntityClass(): string
    {
        return Document::class;
    }

    /**
     * Gets a marshaller bound to this collection.
     *
     * @return \Crustum\Mongo\ODM\Marshaller
     */
    public function marshaller(): Marshaller
    {
        return new Marshaller($this);
    }

    /**
     * Gets the event manager.
     *
     * @return \Cake\Event\EventManagerInterface
     */
    public function getEventManager(): EventManagerInterface
    {
        return $this->eventManager;
    }

    /**
     * Dispatches a model event.
     *
     * @param string $name The event name.
     * @param array<string, mixed> $data Event payload.
     * @return \Cake\Event\EventInterface<object>
     */
    public function dispatchEvent(string $name, array $data = []): EventInterface
    {
        $event = new Event($name, $this, $data);

        return $this->eventManager->dispatch($event);
    }

    /**
     * Gets a validator by name.
     *
     * @param string $name The validator name.
     * @return \Cake\Validation\Validator|null The validator or null when not configured.
     */
    public function getValidator(string $name = 'default'): ?Validator
    {
        return $this->validator;
    }

    /**
     * Dispatches a named finder.
     *
     * @param string $type The finder name.
     * @param mixed $query The query to modify.
     * @param mixed ...$args Finder arguments.
     * @return mixed
     * @throws \BadMethodCallException When finders are not implemented.
     */
    public function callFinder(string $type, mixed $query, mixed ...$args): mixed
    {
        throw new BadMethodCallException('Collection::callFinder() is implemented in Phase 2.');
    }

    /**
     * Gets the primary key field.
     *
     * @return array<string>|string
     */
    public function getPrimaryKey(): string|array
    {
        return '_id';
    }
}

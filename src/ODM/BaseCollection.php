<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM;

use ArrayObject;
use BadMethodCallException;
use Cake\Collection\CollectionInterface;
use Cake\Core\App;
use Cake\Core\Exception\CakeException;
use Cake\Datasource\EntityInterface;
use Cake\Datasource\Exception\InvalidPrimaryKeyException;
use Cake\Datasource\RepositoryInterface;
use Cake\Datasource\RulesChecker;
use Cake\Datasource\SchemaInterface;
use Cake\Event\EventDispatcherInterface;
use Cake\Event\EventDispatcherTrait;
use Cake\Event\EventListenerInterface;
use Cake\Event\EventManager;
use Cake\Event\EventManagerInterface;
use Cake\ORM\Exception\PersistenceFailedException;
use Cake\Utility\Inflector;
use Cake\Validation\ValidatorAwareInterface;
use Cake\Validation\ValidatorAwareTrait;
use Closure;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Exception\MissingDocumentException;
use Crustum\Mongo\ODM\Association\BelongsTo;
use Crustum\Mongo\ODM\Association\BelongsToMany;
use Crustum\Mongo\ODM\Association\DBRef;
use Crustum\Mongo\ODM\Association\Embedded;
use Crustum\Mongo\ODM\Association\EmbedMany;
use Crustum\Mongo\ODM\Association\EmbedOne;
use Crustum\Mongo\ODM\Association\HasMany;
use Crustum\Mongo\ODM\Association\HasOne;
use Crustum\Mongo\ODM\Mapping\DocumentSchemaReader;
use Crustum\Mongo\ODM\Mapping\DtoSchemaReader;
use Crustum\Mongo\ODM\Query\QueryFactory;
use Crustum\Mongo\ODM\Query\SelectQuery;
use InvalidArgumentException;
use LogicException;
use Psr\SimpleCache\CacheInterface;
use function Cake\Core\namespaceSplit;

/**
 * ODM base collection (repository) class.
 *
 * Maps Cake's `Cake\ORM\Table` onto a MongoDB collection. The collection owns
 * its Mongo collection name, entity class, associations, behaviors, and
 * validation sets. Persistence (find/save/delete) is implemented in Phase 2;
 * the lifecycle and wiring that subclasses rely on (initialize, associations,
 * behaviors, validators) is live.
 *
 * @see cake60/src/ORM/Table.php
 * @see 15-odm-phase-2-collection.md
 */
class BaseCollection implements RepositoryInterface, EventListenerInterface, EventDispatcherInterface, ValidatorAwareInterface
{
    use CollectionEventsTrait;
    use EventDispatcherTrait;
    use RulesAwareTrait;
    use ValidatorAwareTrait;

    /**
     * The rules class used for this collection.
     *
     * @var class-string<\Cake\Datasource\RulesChecker>
     */
    public const string RULES_CLASS = RulesChecker::class;

    /**
     * The alias this object is assigned to validators as.
     *
     * @var string
     */
    public const string VALIDATOR_PROVIDER_NAME = 'collection';

    /**
     * The name of the event dispatched when a validator has been built.
     *
     * @var string
     */
    public const string BUILD_VALIDATOR_EVENT = 'Collection.buildValidator';

    /**
     * Name of the default validation set.
     *
     * @var string
     */
    public const string DEFAULT_VALIDATOR = 'default';

    /**
     * Name of the Mongo collection this instance maps to.
     *
     * @var string|null
     */
    protected ?string $collection = null;

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
     * Behavior registry for this collection.
     *
     * @var \Crustum\Mongo\ODM\BehaviorRegistry
     */
    protected BehaviorRegistry $behaviors;

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
     * Primary key field name.
     *
     * @var array<string>|string
     */
    protected array|string $primaryKey = '_id';

    /**
     * The name of the class that represents a single document for this collection.
     *
     * @var class-string<\Crustum\Mongo\ODM\Document>|null
     */
    protected ?string $documentClass = null;

    /**
     * The field used as the human-readable display field.
     *
     * @var array<string>|string|null
     */
    protected array|string|null $displayField = null;

    /**
     * Query factory used to build ODM queries.
     *
     * @var \Crustum\Mongo\ODM\Query\QueryFactory
     */
    protected QueryFactory $queryFactory;

    /**
     * Constructor.
     *
     * @param array<string, mixed> $options Collection options.
     */
    public function __construct(array $options = [])
    {
        if (isset($options['registryAlias'])) {
            $this->setRegistryAlias((string)$options['registryAlias']);
        }

        if (isset($options['collection'])) {
            $this->setCollection((string)$options['collection']);
        }

        if (isset($options['alias'])) {
            $this->setAlias((string)$options['alias']);
        }

        if (isset($options['connection']) && $options['connection'] instanceof Connection) {
            $this->setConnection($options['connection']);
        }

        if (isset($options['schema']) && $options['schema'] instanceof SchemaInterface) {
            $this->setSchema($options['schema']);
        }

        if (isset($options['documentClass'])) {
            $this->setDocumentClass((string)$options['documentClass']);
        }

        if (isset($options['primaryKey'])) {
            $this->setPrimaryKey($options['primaryKey']);
        }

        if (isset($options['validator'])) {
            if (is_array($options['validator'])) {
                foreach ($options['validator'] as $name => $validator) {
                    $this->setValidator((string)$name, $validator);
                }
            } else {
                $this->setValidator(static::DEFAULT_VALIDATOR, $options['validator']);
            }
        }

        $this->eventManager = isset($options['eventManager']) && $options['eventManager'] instanceof EventManagerInterface
            ? $options['eventManager']
            : new EventManager();
        $this->behaviors = isset($options['behaviors']) && $options['behaviors'] instanceof BehaviorRegistry
            ? $options['behaviors']
            : new BehaviorRegistry();
        $this->behaviors->setCollection($this);

        $this->associations = isset($options['associations']) && $options['associations'] instanceof AssociationCollection
            ? $options['associations']
            : new AssociationCollection();

        $this->queryFactory = isset($options['queryFactory']) && $options['queryFactory'] instanceof QueryFactory
            ? $options['queryFactory']
            : new QueryFactory();

        $this->initialize($options);

        $this->getEventManager()->on($this);
        $this->dispatchEvent('Collection.initialize');
    }

    /**
     * Initialize a collection instance. Called after the constructor.
     *
     * Use this method to define associations, attach behaviors, define
     * validation, and do any other initialization logic you need.
     *
     * @param array<string, mixed> $config Configuration options passed to the constructor.
     * @return void
     */
    public function initialize(array $config): void
    {
    }

    /**
     * Sets the Mongo collection name.
     *
     * @param string $collection Collection name.
     * @return $this
     */
    public function setCollection(string $collection): static
    {
        $this->collection = $collection;

        return $this;
    }

    /**
     * Returns the Mongo collection name.
     *
     * @return string
     */
    public function getCollection(): string
    {
        if ($this->collection === null) {
            $collection = namespaceSplit(static::class);
            $collection = substr(end($collection), 0, -10) ?: $this->alias;
            if (!$collection) {
                throw new CakeException(
                    'You must specify either the `alias` or the `collection` option for the constructor.',
                );
            }

            $this->collection = Inflector::underscore($collection);
        }

        return $this->collection;
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
            $alias = namespaceSplit(static::class);
            $alias = substr(end($alias), 0, -10) ?: $this->collection;
            if (!$alias) {
                throw new CakeException(
                    'You must specify either the `alias` or the `collection` option for the constructor.',
                );
            }

            $this->alias = $alias;
        }

        return $this->alias;
    }

    /**
     * Prefixes a field name with the collection alias.
     *
     * If field is already aliased, it will result in no-op.
     *
     * @param string $field The field name.
     * @return string
     */
    public function aliasField(string $field): string
    {
        if (str_contains($field, '.')) {
            return $field;
        }

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
        return $this->registryAlias ??= $this->getAlias();
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
     * @return \Crustum\Mongo\ODM\Query\SelectQuery
     * @throws \BadMethodCallException When the finder is unknown.
     */
    public function find(string $type = 'all', mixed ...$args): SelectQuery
    {
        $query = $this->queryFactory->select($this);

        return $this->callFinder($type, $query, ...$args);
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
     * @throws \Cake\Datasource\Exception\InvalidPrimaryKeyException When the key is null or mismatched.
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When no record is found.
     */
    public function get(
        mixed $primaryKey,
        array|string $finder = 'all',
        CacheInterface|string|null $cache = null,
        Closure|string|null $cacheKey = null,
        mixed ...$args,
    ): EntityInterface {
        if ($primaryKey === null) {
            throw new InvalidPrimaryKeyException(sprintf(
                'Record not found in collection `%s` with primary key `[NULL]`.',
                $this->getCollection(),
            ));
        }

        $key = (array)$this->getPrimaryKey();
        if (!is_array($primaryKey)) {
            $primaryKey = [$primaryKey];
        }

        if (count($key) !== count($primaryKey)) {
            $primaryKey = $primaryKey ?: [null];
            $primaryKey = array_map(static fn(mixed $value): string => var_export($value, true), $primaryKey);

            throw new InvalidPrimaryKeyException(sprintf(
                'Record not found in collection `%s` with primary key `[%s]`.',
                $this->getCollection(),
                implode(', ', $primaryKey),
            ));
        }

        $conditions = array_combine($key, $primaryKey);

        if (is_array($finder)) {
            $type = (string)array_shift($finder);
            $args = $finder + $args;
        } else {
            $type = $finder;
        }

        $query = $this->find($type, ...$args)->where($conditions);

        if ($cache !== null) {
            if ($cacheKey === null) {
                $cacheKey = sprintf(
                    'get-%s-%s',
                    $this->getCollection(),
                    json_encode($primaryKey, JSON_THROW_ON_ERROR),
                );
            }

            $query->cache($cacheKey, $cache);
        }

        /** @var \Cake\Datasource\EntityInterface $entity */
        $entity = $query->firstOrFail();

        return $entity;
    }

    /**
     * Creates a new query instance for this collection.
     *
     * @return \Crustum\Mongo\ODM\Query\SelectQuery
     */
    public function query(): SelectQuery
    {
        return $this->queryFactory->select($this);
    }

    /**
     * Updates matching documents.
     *
     * This method does not fire beforeSave/afterSave events.
     *
     * @param \Closure|array<string, mixed>|string $fields Update specification.
     * @param \Closure|array<string, mixed>|string|null $conditions Filter conditions.
     * @return int The number of modified documents.
     */
    public function updateAll(Closure|array|string $fields, Closure|array|string|null $conditions): int
    {
        $query = $this->queryFactory->update($this);
        if ($fields instanceof Closure) {
            $fields = $fields();
        }

        $query->set(is_array($fields) ? $fields : [$fields]);
        if ($conditions !== null) {
            $query->where($conditions);
        }

        return (int)$query->execute();
    }

    /**
     * Deletes matching documents.
     *
     * This method does not fire beforeDelete/afterDelete events.
     *
     * @param \Closure|array<string, mixed>|string|null $conditions Filter conditions.
     * @return int The number of deleted documents.
     */
    public function deleteAll(Closure|array|string|null $conditions): int
    {
        $query = $this->queryFactory->delete($this);
        if ($conditions !== null) {
            $query->where($conditions);
        }

        return (int)$query->execute();
    }

    /**
     * Whether any document matches the conditions.
     *
     * @param \Closure|array<string, mixed>|string|null $conditions Filter conditions.
     * @return bool
     */
    public function exists(Closure|array|string|null $conditions): bool
    {
        $query = $this->queryFactory->unhydratedSelect($this);
        if ($conditions !== null) {
            $query->where($conditions);
        }

        return $query->limit(1)->first() !== null;
    }

    /**
     * Persists a document.
     *
     * @param \Cake\Datasource\EntityInterface $entity The document.
     * @param array<string, mixed> $options Save options.
     * @return \Cake\Datasource\EntityInterface|false
     */
    public function save(EntityInterface $entity, array $options = []): EntityInterface|false
    {
        $options = new ArrayObject($options + [
            'atomic' => true,
            'associated' => true,
            'checkRules' => true,
            'checkExisting' => true,
            '_primary' => true,
            '_cleanOnSuccess' => true,
        ]);

        if ($entity->hasErrors((bool)$options['associated'])) {
            return false;
        }

        if ($entity->isNew() === false && !$entity->isDirty()) {
            return $entity;
        }

        $success = $this->executeTransaction(
            fn(): EntityInterface|false => $this->processSave($entity, $options),
            (bool)$options['atomic'],
        );

        if ($success) {
            if ($this->transactionCommitted((bool)$options['atomic'], (bool)$options['_primary'])) {
                $this->dispatchEvent('Collection.afterSaveCommit', ['entity' => $entity, 'options' => $options]);
            }

            if ($options['atomic'] || $options['_primary']) {
                if ($options['_cleanOnSuccess']) {
                    $entity->clean();
                    $entity->setNew(false);
                }

                $entity->setSource($this->getRegistryAlias());
            }
        }

        return $success;
    }

    /**
     * Saves a document or throws when the save fails.
     *
     * @param \Cake\Datasource\EntityInterface $entity The document.
     * @param array<string, mixed> $options Save options.
     * @return \Cake\Datasource\EntityInterface
     * @throws \Cake\ORM\Exception\PersistenceFailedException When the document could not be saved.
     */
    public function saveOrFail(EntityInterface $entity, array $options = []): EntityInterface
    {
        $saved = $this->save($entity, $options);
        if ($saved === false) {
            throw new PersistenceFailedException($entity, ['save']);
        }

        return $saved;
    }

    /**
     * Deletes a document.
     *
     * @param \Cake\Datasource\EntityInterface $entity The document.
     * @param array<string, mixed> $options Delete options.
     * @return bool
     */
    public function delete(EntityInterface $entity, array $options = []): bool
    {
        $options = new ArrayObject($options + [
            'atomic' => true,
            'checkRules' => true,
            '_primary' => true,
        ]);

        $success = $this->executeTransaction(
            fn(): bool => $this->processDelete($entity, $options),
            (bool)$options['atomic'],
        );

        if ($success && $this->transactionCommitted((bool)$options['atomic'], (bool)$options['_primary'])) {
            $this->dispatchEvent('Collection.afterDeleteCommit', [
                'entity' => $entity,
                'options' => $options,
            ]);
        }

        return $success;
    }

    /**
     * Creates an empty document.
     *
     * @return \Cake\Datasource\EntityInterface
     */
    public function newEmptyEntity(): EntityInterface
    {
        $class = $this->getDocumentClass();

        return new $class([], ['source' => $this->getRegistryAlias()]);
    }

    /**
     * Marshals one document from input data.
     *
     * @param array<string, mixed> $data Input data.
     * @param array<string, mixed> $options Marshalling options.
     * @return \Cake\Datasource\EntityInterface
     */
    public function newEntity(array $data, array $options = []): EntityInterface
    {
        $options['associated'] ??= $this->associations->keys();

        return $this->marshaller()->one($data, $options);
    }

    /**
     * Marshals multiple documents from input data.
     *
     * @param array<int, mixed> $data Input rows.
     * @param array<string, mixed> $options Marshalling options.
     * @return array<int, \Cake\Datasource\EntityInterface>
     */
    public function newEntities(array $data, array $options = []): array
    {
        $options['associated'] ??= $this->associations->keys();

        return $this->marshaller()->many($data, $options);
    }

    /**
     * Merges input data into an existing document.
     *
     * @param \Cake\Datasource\EntityInterface $entity The document.
     * @param array<string, mixed> $data Input data.
     * @param array<string, mixed> $options Marshalling options.
     * @return \Cake\Datasource\EntityInterface
     */
    public function patchEntity(EntityInterface $entity, array $data, array $options = []): EntityInterface
    {
        if (!$entity instanceof Document) {
            throw new InvalidArgumentException('patchEntity() requires a Document.');
        }

        $options['associated'] ??= $this->associations->keys();

        return $this->marshaller()->merge($entity, $data, $options);
    }

    /**
     * Merges input rows into matching documents.
     *
     * @param iterable<mixed> $entities Existing documents.
     * @param array<int, mixed> $data Input rows.
     * @param array<string, mixed> $options Marshalling options.
     * @return array<int, \Cake\Datasource\EntityInterface>
     */
    public function patchEntities(iterable $entities, array $data, array $options = []): array
    {
        $options['associated'] ??= $this->associations->keys();

        return $this->marshaller()->mergeMany($entities, $data, $options);
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
     * Sets the collection schema.
     *
     * @param \Cake\Datasource\SchemaInterface $schema The schema.
     * @return $this
     */
    public function setSchema(SchemaInterface $schema): static
    {
        $this->schema = $schema;

        return $this;
    }

    /**
     * Derive this collection's schema from a DTO class.
     *
     * Every promoted constructor parameter becomes a field; the type is
     * inferred from the PHP type hint unless a `#[Field]` attribute overrides
     * it. This is the primary application-side schema definition mechanism.
     *
     * @param class-string $dtoClass The DTO class to read
     * @return $this
     */
    public function setSchemaFromDto(string $dtoClass): static
    {
        return $this->setSchema(DtoSchemaReader::read($dtoClass, $this->getCollection()));
    }

    /**
     * Derive this collection's schema from a Document class.
     *
     * Optional sugar: reads repeatable `#[Field]` attributes declared at class
     * level on the concrete document. The document stays an `EntityInterface`
     * data bag; this only supplies application field metadata.
     *
     * @param class-string $documentClass The Document class to read
     * @return $this
     */
    public function setSchemaFromDocument(string $documentClass): static
    {
        return $this->setSchema(DocumentSchemaReader::read($documentClass, $this->getCollection()));
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
     * Creates a new BelongsTo association between this collection and a target.
     *
     * @param string $associated The alias for the target collection.
     * @param array<string, mixed> $options Association options.
     * @return \Crustum\Mongo\ODM\Association\BelongsTo
     */
    public function belongsTo(string $associated, array $options = []): BelongsTo
    {
        return $this->associations->load(BelongsTo::class, $associated, $this, $options);
    }

    /**
     * Creates a new HasOne association between this collection and a target.
     *
     * @param string $associated The alias for the target collection.
     * @param array<string, mixed> $options Association options.
     * @return \Crustum\Mongo\ODM\Association\HasOne
     */
    public function hasOne(string $associated, array $options = []): HasOne
    {
        return $this->associations->load(HasOne::class, $associated, $this, $options);
    }

    /**
     * Creates a new HasMany association between this collection and a target.
     *
     * @param string $associated The alias for the target collection.
     * @param array<string, mixed> $options Association options.
     * @return \Crustum\Mongo\ODM\Association\HasMany
     */
    public function hasMany(string $associated, array $options = []): HasMany
    {
        return $this->associations->load(HasMany::class, $associated, $this, $options);
    }

    /**
     * Creates a new BelongsToMany association between this collection and a target.
     *
     * @param string $associated The alias for the target collection.
     * @param array<string, mixed> $options Association options.
     * @return \Crustum\Mongo\ODM\Association\BelongsToMany
     */
    public function belongsToMany(string $associated, array $options = []): BelongsToMany
    {
        return $this->associations->load(BelongsToMany::class, $associated, $this, $options);
    }

    /**
     * Creates a new EmbedOne association stored inside the source document.
     *
     * @param string $associated The alias for the embedded document.
     * @param array<string, mixed> $options Association options.
     * @return \Crustum\Mongo\ODM\Association\EmbedOne
     */
    public function embedOne(string $associated, array $options = []): EmbedOne
    {
        return $this->associations->load(EmbedOne::class, $associated, $this, $options);
    }

    /**
     * Creates a new EmbedMany association stored inside the source document.
     *
     * @param string $associated The alias for the embedded documents.
     * @param array<string, mixed> $options Association options.
     * @return \Crustum\Mongo\ODM\Association\EmbedMany
     */
    public function embedMany(string $associated, array $options = []): EmbedMany
    {
        return $this->associations->load(EmbedMany::class, $associated, $this, $options);
    }

    /**
     * Creates a new DBRef association between this collection and a target.
     *
     * @param string $associated The alias for the target collection.
     * @param array<string, mixed> $options Association options.
     * @return \Crustum\Mongo\ODM\Association\DBRef
     */
    public function dbref(string $associated, array $options = []): DBRef
    {
        return $this->associations->load(DBRef::class, $associated, $this, $options);
    }

    /**
     * Adds a behavior to this collection's behavior registry.
     *
     * @param string $name The name of the behavior. Can be a short class reference.
     * @param array<string, mixed> $options The options for the behavior to use.
     * @return $this
     */
    public function addBehavior(string $name, array $options = []): static
    {
        $this->behaviors->load($name, $options);

        return $this;
    }

    /**
     * Adds an array of behaviors to the collection's behavior registry.
     *
     * @param array<int|string, mixed> $behaviors All the behaviors to load.
     * @return $this
     */
    public function addBehaviors(array $behaviors): static
    {
        foreach ($behaviors as $name => $options) {
            if (is_int($name)) {
                $name = $options;
                $options = [];
            }

            $this->addBehavior((string)$name, $options);
        }

        return $this;
    }

    /**
     * Removes a behavior from this collection's behavior registry.
     *
     * @param string $name The alias that the behavior was added with.
     * @return $this
     */
    public function removeBehavior(string $name): static
    {
        $this->behaviors->unload($name);

        return $this;
    }

    /**
     * Returns the behavior registry for this collection.
     *
     * @return \Crustum\Mongo\ODM\BehaviorRegistry
     */
    public function behaviors(): BehaviorRegistry
    {
        return $this->behaviors;
    }

    /**
     * Gets a behavior from the registry.
     *
     * @param string $name The behavior alias.
     * @return \Crustum\Mongo\ODM\Behavior
     * @throws \InvalidArgumentException If the behavior does not exist.
     */
    public function getBehavior(string $name): Behavior
    {
        if (!$this->behaviors->has($name)) {
            throw new InvalidArgumentException(sprintf(
                'The `%s` behavior is not defined on `%s`.',
                $name,
                static::class,
            ));
        }

        /** @var \Crustum\Mongo\ODM\Behavior */
        return $this->behaviors->get($name);
    }

    /**
     * Checks whether a behavior with the given alias has been loaded.
     *
     * @param string $name The behavior alias.
     * @return bool
     */
    public function hasBehavior(string $name): bool
    {
        return $this->behaviors->has($name);
    }

    /**
     * Sets the entity class used for hydrated documents.
     *
     * @param string $name The name of the class to use.
     * @return $this
     * @throws \Crustum\Mongo\Exception\MissingDocumentException When the class cannot be found.
     */
    public function setDocumentClass(string $name): static
    {
        /** @var class-string<\Crustum\Mongo\ODM\Document>|null $class */
        $class = App::className($name, 'Model/Document');
        if ($class === null) {
            throw new MissingDocumentException([$name]);
        }

        $this->documentClass = $class;

        return $this;
    }

    /**
     * Gets the document class used for hydrated documents.
     *
     * @return class-string<\Crustum\Mongo\ODM\Document>
     */
    public function getDocumentClass(): string
    {
        if ($this->documentClass === null) {
            $self = static::class;
            $parts = explode('\\', $self);

            if ($self === self::class || count($parts) < 3) {
                return $this->documentClass = Document::class;
            }

            $alias = Inflector::classify(Inflector::underscore(substr(array_pop($parts), 0, -10)));
            $name = implode('\\', array_slice($parts, 0, -1)) . '\\Document\\' . $alias;
            if (!class_exists($name)) {
                return $this->documentClass = Document::class;
            }

            /** @var class-string<\Crustum\Mongo\ODM\Document>|null $class */
            $class = App::className($name, 'Model/Document');
            if ($class === null) {
                return $this->documentClass = Document::class;
            }

            $this->documentClass = $class;
        }

        return $this->documentClass;
    }

    /**
     * Sets the primary key field.
     *
     * @param array<string>|string $key The primary key field.
     * @return $this
     */
    public function setPrimaryKey(array|string $key): static
    {
        $this->primaryKey = $key;

        return $this;
    }

    /**
     * Gets the primary key field.
     *
     * @return array<string>|string
     */
    public function getPrimaryKey(): string|array
    {
        return $this->primaryKey;
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
     * Gets the Model callbacks this collection is interested in.
     *
     * @return array<string, mixed>
     */
    public function implementedEvents(): array
    {
        $eventMap = [
            'Collection.beforeMarshal' => 'beforeMarshal',
            'Collection.afterMarshal' => 'afterMarshal',
            'Collection.buildValidator' => 'buildValidator',
            'Collection.beforeFind' => 'beforeFind',
            'Collection.beforeSave' => 'beforeSave',
            'Collection.afterSave' => 'afterSave',
            'Collection.afterSaveCommit' => 'afterSaveCommit',
            'Collection.beforeDelete' => 'beforeDelete',
            'Collection.afterDelete' => 'afterDelete',
            'Collection.afterDeleteCommit' => 'afterDeleteCommit',
            'Collection.beforeRules' => 'beforeRules',
            'Collection.afterRules' => 'afterRules',
        ];
        $events = [];

        foreach ($eventMap as $event => $method) {
            if (!method_exists($this, $method)) {
                continue;
            }

            $events[$event] = $method;
        }

        return $events;
    }

    /**
     * Dispatches a named finder.
     *
     * @param string $type The finder name.
     * @param \Crustum\Mongo\ODM\Query\SelectQuery $query The query to modify.
     * @param mixed ...$args Finder arguments.
     * @return \Crustum\Mongo\ODM\Query\SelectQuery
     * @throws \BadMethodCallException When the finder is unknown.
     */
    public function callFinder(string $type, SelectQuery $query, mixed ...$args): SelectQuery
    {
        $finder = 'find' . $type;
        if (method_exists($this, $finder)) {
            return $this->invokeFinder($this->{$finder}(...), $query, $args);
        }

        if ($this->behaviors->hasFinder($type)) {
            return $this->invokeFinder($this->behaviors->getFinder($type), $query, $args);
        }

        throw new BadMethodCallException(sprintf(
            'Unknown finder method `%s` on `%s`.',
            $type,
            static::class,
        ));
    }

    /**
     * Returns whether a finder exists on this collection or its behaviors.
     *
     * @param string $type Finder name.
     * @return bool
     */
    public function hasFinder(string $type): bool
    {
        return method_exists($this, 'find' . $type) || $this->behaviors->hasFinder($type);
    }

    /**
     * Invokes a finder callable with the query and arguments.
     *
     * @param \Closure $callable Finder callable.
     * @param \Crustum\Mongo\ODM\Query\SelectQuery $query The query.
     * @param array<int|string, mixed> $args Arguments for the callable.
     * @return \Crustum\Mongo\ODM\Query\SelectQuery
     */
    protected function invokeFinder(Closure $callable, SelectQuery $query, array $args): SelectQuery
    {
        $result = $callable($query, ...$args);
        if (!$result instanceof SelectQuery) {
            throw new LogicException('Finder must return the query it was given.');
        }

        return $result;
    }

    /**
     * Default finder; returns the query unchanged.
     *
     * @param \Crustum\Mongo\ODM\Query\SelectQuery $query The query.
     * @return \Crustum\Mongo\ODM\Query\SelectQuery
     */
    public function findAll(SelectQuery $query): SelectQuery
    {
        return $query;
    }

    /**
     * Configures the query so results appear as an indexed array.
     *
     * @param \Crustum\Mongo\ODM\Query\SelectQuery $query The query.
     * @param \Closure|array<string, mixed>|string|null $keyField The key field.
     * @param \Closure|array<string, mixed>|string|null $valueField The value field.
     * @param \Closure|array<string, mixed>|string|null $groupField The group field.
     * @param string $valueSeparator Separator for composite values.
     * @return \Crustum\Mongo\ODM\Query\SelectQuery
     */
    public function findList(
        SelectQuery $query,
        Closure|array|string|null $keyField = null,
        Closure|array|string|null $valueField = null,
        Closure|array|string|null $groupField = null,
        string $valueSeparator = ' ',
    ): SelectQuery {
        $keyField ??= $this->getPrimaryKey();
        $valueField ??= $this->getDisplayField();

        $options = $this->setFieldMatchers(
            ['keyField' => $keyField, 'valueField' => $valueField, 'groupField' => $groupField, 'valueSeparator' => $valueSeparator],
            ['keyField', 'valueField', 'groupField'],
        );

        return $query->formatResults(
            fn(CollectionInterface $results): CollectionInterface => $results->combine(
                $options['keyField'],
                $options['valueField'],
                $options['groupField'],
            ),
        );
    }

    /**
     * Configures the query so results appear as a threaded/nested array.
     *
     * @param \Crustum\Mongo\ODM\Query\SelectQuery $query The query.
     * @param \Closure|array<string, mixed>|string|null $keyField The key field.
     * @param \Closure|array<string, mixed>|string $parentField The parent field.
     * @param string $nestingKey The key to nest children under.
     * @return \Crustum\Mongo\ODM\Query\SelectQuery
     */
    public function findThreaded(
        SelectQuery $query,
        Closure|array|string|null $keyField = null,
        Closure|array|string $parentField = 'parent_id',
        string $nestingKey = 'children',
    ): SelectQuery {
        $keyField ??= $this->getPrimaryKey();

        $options = $this->setFieldMatchers(['keyField' => $keyField, 'parentField' => $parentField], ['keyField', 'parentField']);

        return $query->formatResults(
            fn(CollectionInterface $results): CollectionInterface => $results->nest(
                $options['keyField'],
                $options['parentField'],
                $nestingKey,
            ),
        );
    }

    /**
     * Converts composite key options into field matchers.
     *
     * @param array<string, mixed> $options The original options.
     * @param array<string> $keys The keys to build matchers from.
     * @return array<string, mixed>
     */
    protected function setFieldMatchers(array $options, array $keys): array
    {
        foreach ($keys as $field) {
            if (!is_array($options[$field])) {
                continue;
            }

            if (count($options[$field]) === 1) {
                $options[$field] = current($options[$field]);
                continue;
            }

            $fields = $options[$field];
            $glue = in_array($field, ['keyField', 'parentField'], true) ? ';' : $options['valueSeparator'];
            $options[$field] = static function (array $row) use ($fields, $glue): string {
                $matches = [];
                foreach ($fields as $field) {
                    $matches[] = (string)($row[$field] ?? '');
                }

                return implode($glue, $matches);
            };
        }

        return $options;
    }

    /**
     * Runs a worker inside a transaction when atomic is enabled.
     *
     * @param \Closure $worker The worker callable.
     * @param bool $atomic Whether to use a transaction.
     * @return mixed
     */
    protected function executeTransaction(Closure $worker, bool $atomic = true): mixed
    {
        $connection = $this->getConnection();
        if ($atomic && $connection instanceof Connection) {
            return $connection->transactional($worker(...));
        }

        return $worker();
    }

    /**
     * Whether the caller would have committed a transaction.
     *
     * @param bool $atomic True if an atomic transaction was used.
     * @param bool $primary True if a primary was used.
     * @return bool
     */
    protected function transactionCommitted(bool $atomic, bool $primary): bool
    {
        $connection = $this->getConnection();

        return (!$connection instanceof Connection || !$connection->inTransaction()) && ($atomic || $primary);
    }

    /**
     * Performs the actual saving of a document.
     *
     * @param \Cake\Datasource\EntityInterface $entity The document.
     * @param \ArrayObject<string, mixed> $options Save options.
     * @return \Cake\Datasource\EntityInterface|false
     */
    protected function processSave(EntityInterface $entity, ArrayObject $options): EntityInterface|false
    {
        $primaryKey = (array)$this->getPrimaryKey();

        if ($options['checkExisting'] && $primaryKey !== [] && $entity->isNew() && $entity->has($primaryKey)) {
            $conditions = [];
            foreach ($entity->extract($primaryKey) as $key => $value) {
                $conditions[$key] = $value;
            }

            $entity->setNew(!$this->exists($conditions));
        }

        $mode = $entity->isNew() ? RulesChecker::CREATE : RulesChecker::UPDATE;
        if ($options['checkRules'] && !$this->checkRules($entity, $mode, $options)) {
            return false;
        }

        $event = $this->dispatchEvent('Collection.beforeSave', ['entity' => $entity, 'options' => $options]);
        if ($event->isStopped()) {
            $result = $event->getResult();
            if ($result === null) {
                return false;
            }

            if (!$result instanceof EntityInterface) {
                return false;
            }

            return $result;
        }

        $saved = $this->saveParents($entity, $options);
        if (!$saved) {
            return false;
        }

        $isNew = $entity->isNew();
        $success = $isNew ? $this->insert($entity) : $this->update($entity);

        if ($success) {
            $success = $this->saveChildren($entity, $options);
        }

        if (!$success && $isNew) {
            $entity->unset($this->getPrimaryKey());
            $entity->setNew(true);
        }

        return $success ? $entity : false;
    }

    /**
     * Inserts a new document into the collection.
     *
     * @param \Cake\Datasource\EntityInterface $entity The document.
     * @return \Cake\Datasource\EntityInterface|false
     */
    protected function insert(EntityInterface $entity): EntityInterface|false
    {
        $data = $entity->toArray();

        $query = $this->queryFactory->insert($this);
        $query->values($data);

        $ids = $query->execute();
        $ids = is_array($ids) ? $ids : [];
        if ($ids === []) {
            return false;
        }

        if (isset($data['_id'])) {
            $entity->set('_id', $data['_id']);
        }

        return $entity;
    }

    /**
     * Updates a dirty document using $set/$unset diffs.
     *
     * @param \Cake\Datasource\EntityInterface $entity The document.
     * @return \Cake\Datasource\EntityInterface|false
     */
    protected function update(EntityInterface $entity): EntityInterface|false
    {
        $primaryKey = (array)$this->getPrimaryKey();
        if (!$entity->has($primaryKey)) {
            throw new InvalidArgumentException('All primary key value(s) are needed for updating.');
        }

        $set = [];
        $unset = [];
        foreach ($entity->getDirty() as $field) {
            if (in_array($field, $primaryKey, true)) {
                continue;
            }

            if ($entity->has($field)) {
                $set[$field] = $entity->get($field);
            } else {
                $unset[] = $field;
            }
        }

        $query = $this->queryFactory->update($this);
        if ($set !== []) {
            $query->set($set);
        }

        if ($unset !== []) {
            $query->unset($unset);
        }

        if ($set === [] && $unset === []) {
            return $entity;
        }

        $query->where($entity->extract($primaryKey));
        $count = $query->execute();

        return is_int($count) ? $entity : false;
    }

    /**
     * Performs the delete operation.
     *
     * @param \Cake\Datasource\EntityInterface $entity The document.
     * @param \ArrayObject<string, mixed> $options Delete options.
     * @return bool
     */
    protected function processDelete(EntityInterface $entity, ArrayObject $options): bool
    {
        if ($entity->isNew()) {
            return false;
        }

        $primaryKey = (array)$this->getPrimaryKey();
        if (!$entity->has($primaryKey)) {
            throw new InvalidArgumentException('Deleting requires all primary key values.');
        }

        if ($options['checkRules'] && !$this->checkRules($entity, RulesChecker::DELETE, $options)) {
            return false;
        }

        $event = $this->dispatchEvent('Collection.beforeDelete', ['entity' => $entity, 'options' => $options]);
        if ($event->isStopped()) {
            return (bool)$event->getResult();
        }

        if (!$this->cascadeDelete($entity, $options->getArrayCopy())) {
            return false;
        }

        $query = $this->queryFactory->delete($this)->where($entity->extract($primaryKey));
        $count = $query->execute();
        if ($count < 1) {
            return false;
        }

        $this->dispatchEvent('Collection.afterDelete', ['entity' => $entity, 'options' => $options]);

        return true;
    }

    /**
     * Saves parent associations before the owning document is persisted.
     *
     * @param \Cake\Datasource\EntityInterface $entity The source document.
     * @param \ArrayObject<string, mixed> $options Save options.
     * @return bool
     */
    protected function saveParents(EntityInterface $entity, ArrayObject $options): bool
    {
        $associated = $this->normalizeAssociated((array)$options['associated']);
        foreach ($this->associations->type(BelongsTo::class) as $association) {
            if (!$entity->isDirty($association->getProperty())) {
                continue;
            }

            if (!$this->isAssociated($associated, $association)) {
                continue;
            }

            if ($association->saveAssociated($entity, $options->getArrayCopy()) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Saves child associations after the owning document is persisted.
     *
     * @param \Cake\Datasource\EntityInterface $entity The source document.
     * @param \ArrayObject<string, mixed> $options Save options.
     * @return bool
     */
    protected function saveChildren(EntityInterface $entity, ArrayObject $options): bool
    {
        $associated = $this->normalizeAssociated((array)$options['associated']);
        foreach ($this->associations as $association) {
            if ($association instanceof BelongsTo) {
                continue;
            }
            if ($association instanceof Embedded) {
                continue;
            }

            if (!$entity->isDirty($association->getProperty())) {
                continue;
            }

            if (!$this->isAssociated($associated, $association)) {
                continue;
            }

            if ($association->saveAssociated($entity, $options->getArrayCopy()) === false) {
                return false;
            }
        }

        $this->dispatchEvent('Collection.afterSave', ['entity' => $entity, 'options' => $options]);

        return true;
    }

    /**
     * Normalizes an associated option into a list of association aliases.
     *
     * @param array<int|string, mixed> $associated The associated option.
     * @return array<string>
     */
    protected function normalizeAssociated(array $associated): array
    {
        if ($associated === []) {
            return [];
        }

        $result = [];
        foreach ($associated as $key => $value) {
            $result[] = is_int($key) ? (string)$value : $key;
        }

        return $result;
    }

    /**
     * Whether an association is included in the associated option.
     *
     * @param array<string> $associated Normalized associated list.
     * @param \Crustum\Mongo\ODM\Association $association The association.
     * @return bool
     */
    protected function isAssociated(array $associated, Association $association): bool
    {
        return in_array($association->getName(), $associated, true)
            || in_array($association->getProperty(), $associated, true);
    }

    /**
     * Cascades deletes to dependent associations.
     *
     * @param \Cake\Datasource\EntityInterface $entity The source document.
     * @param array<string, mixed> $options Delete options.
     * @return bool
     */
    protected function cascadeDelete(EntityInterface $entity, array $options = []): bool
    {
        foreach ($this->associations as $association) {
            if (!$association->getDependent()) {
                continue;
            }

            if (!$association->cascadeDelete($entity, $options)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Sets the display field.
     *
     * @param array<string>|string $field The display field.
     * @return $this
     */
    public function setDisplayField(array|string $field): static
    {
        $this->displayField = $field;

        return $this;
    }

    /**
     * Returns the display field, deriving a sensible default when unset.
     *
     * @return array<string>|string
     */
    public function getDisplayField(): array|string
    {
        if ($this->displayField !== null) {
            return $this->displayField;
        }

        $schema = $this->getSchema();
        if ($schema instanceof SchemaInterface) {
            foreach (['title', 'name', 'label'] as $field) {
                if ($schema->hasColumn($field)) {
                    return $this->displayField = $field;
                }
            }
        }

        return $this->displayField = $this->getPrimaryKey();
    }
}

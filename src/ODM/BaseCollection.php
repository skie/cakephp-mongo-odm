<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM;

use ArrayObject;
use BadMethodCallException;
use Cake\Collection\CollectionInterface;
use Cake\Core\App;
use Cake\Core\Exception\CakeException;
use Cake\Database\Exception\DatabaseException;
use Cake\Database\ExpressionInterface;
use Cake\Datasource\ConnectionManager;
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
use Cake\Utility\Inflector;
use Cake\Validation\ValidatorAwareInterface;
use Crustum\Mongo\ODM\Exception\PersistenceFailedException;
use Crustum\Mongo\ODM\Exception\RolledbackTransactionException;
use Cake\Validation\ValidatorAwareTrait;
use Crustum\Mongo\ODM\Rule\IsUnique as CrustumIsUnique;
use Crustum\Mongo\ODM\RulesChecker as CrustumRulesChecker;
use Closure;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Database\Schema\CollectionSchema;
use Crustum\Mongo\Database\Type\TypeFactory;
use Crustum\Mongo\ODM\Association\BelongsTo;
use Crustum\Mongo\ODM\Association\BelongsToMany;
use Crustum\Mongo\ODM\Association\DBRef;
use Crustum\Mongo\ODM\Association\Embedded;
use Crustum\Mongo\ODM\Association\EmbedMany;
use Crustum\Mongo\ODM\Association\EmbedOne;
use Crustum\Mongo\ODM\Association\HasMany;
use Crustum\Mongo\ODM\Association\HasOne;
use Crustum\Mongo\ODM\Exception\MissingDocumentException;
use Crustum\Mongo\ODM\Mapping\DocumentSchemaReader;
use Crustum\Mongo\ODM\Mapping\DtoSchemaReader;
use Crustum\Mongo\ODM\Query\DeleteQuery;
use Crustum\Mongo\ODM\Query\InsertQuery;
use Crustum\Mongo\ODM\Query\QueryFactory;
use Crustum\Mongo\ODM\Query\SelectQuery;
use Crustum\Mongo\ODM\Query\UnhydratedSelectQuery;
use Crustum\Mongo\ODM\Query\UpdateQuery;
use Exception;
use InvalidArgumentException;
use LogicException;
use Throwable;
use Psr\SimpleCache\CacheInterface;
use ReflectionFunction;
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
     * @var class-string<\Crustum\Mongo\ODM\RulesChecker>
     */
    public const string RULES_CLASS = CrustumRulesChecker::class;

    /**
     * The rule class used by validateUnique().
     *
     * @var class-string<\Crustum\Mongo\ODM\Rule\IsUnique>
     */
    public const string IS_UNIQUE_CLASS = CrustumIsUnique::class;

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
     * Whether documents passed to save/delete/patch/loadInto must match the
     * configured document class.
     *
     * @var bool
     */
    protected bool $assertDocumentClass = true;

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

        if (isset($options['schema'])) {
            if ($options['schema'] instanceof SchemaInterface) {
                $this->setSchema($options['schema']);
            } elseif (is_array($options['schema'])) {
                $this->setSchemaFromArray($options['schema']);
            }
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
            if (!$collection || $collection === 'Base') {
                $collection = $this->alias;
            }

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
     * Creates a new select query
     *
     * @return \Crustum\Mongo\ODM\Query\SelectQuery
     */
    public function selectQuery(): SelectQuery
    {
        $query = $this->queryFactory->select($this);

        return $query;
    }

    /**
     * Creates a new non-hydrating select query.
     *
     * @return \Crustum\Mongo\ODM\Query\UnhydratedSelectQuery
     * @since 5.4.0
     */
    public function unhydratedSelectQuery(): UnhydratedSelectQuery
    {
        return $this->queryFactory->unhydratedSelect($this);
    }

    /**
     * Creates a new unhydrated select query and applies a finder to it.
     *
     * @param string $type Finder name.
     * @param mixed ...$args Finder arguments.
     * @return \Crustum\Mongo\ODM\Query\UnhydratedSelectQuery
     * @throws \Cake\Core\Exception\CakeException When a finder returns a query that is not unhydrated.
     */
    public function unhydratedFind(string $type = 'all', mixed ...$args): UnhydratedSelectQuery
    {
        $query = $this->unhydratedSelectQuery();
        $result = $this->callFinder($type, $query, ...$args);

        if (!$result instanceof UnhydratedSelectQuery) {
            throw new CakeException(sprintf(
                'The `%s` finder must return the query it was given when called via unhydratedFind(); '
                . 'got `%s` instead. Finders that build a fresh query cannot preserve the '
                . 'non-hydrating contract - use find() for those.',
                $type,
                get_debug_type($result),
            ));
        }

        return $result;
    }

    /**
     * Creates a new insert query
     *
     * @return \Crustum\Mongo\ODM\Query\InsertQuery
     */
    public function insertQuery(): InsertQuery
    {
        return $this->queryFactory->insert($this);
    }

    /**
     * Creates a new update query
     *
     * @return \Crustum\Mongo\ODM\Query\UpdateQuery
     */
    public function updateQuery(): UpdateQuery
    {
        return $this->queryFactory->update($this);
    }

    /**
     * Creates a new delete query
     *
     * @return \Crustum\Mongo\ODM\Query\DeleteQuery
     */
    public function deleteQuery(): DeleteQuery
    {
        return $this->queryFactory->delete($this);
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
    public function updateAll(
        ExpressionInterface|Closure|array|string $fields,
        ExpressionInterface|Closure|array|string|null $conditions,
    ): int {
        $query = $this->updateQuery();
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
     * @param \Cake\Database\ExpressionInterface|\Closure|array<string, mixed>|string|null $conditions Filter conditions.
     * @return int The number of deleted documents.
     */
    public function deleteAll(ExpressionInterface|Closure|array|string|null $conditions): int
    {
        $query = $this->deleteQuery();
        if ($conditions !== null) {
            $query->where($conditions);
        }

        return (int)$query->execute();
    }

    /**
     * Whether any document matches the conditions.
     *
     * @param \Cake\Database\ExpressionInterface|\Closure|array<string, mixed>|string|null $conditions Filter conditions.
     * @return bool
     */
    public function exists(ExpressionInterface|Closure|array|string|null $conditions): bool
    {
        $query = $this->queryFactory->unhydratedSelect($this);
        if ($conditions !== null) {
            $query->where($conditions);
        }

        return $query->limit(1)->first() !== null;
    }

    /**
     * Returns a single document after finding one by primary key value or
     * creating a new one if it doesn't exist.
     *
     * If a document matching $search can be found, it will be returned. If not,
     * a new entity will be created with $search used as the default data. The
     * new entity will be saved and returned.
     *
     * If your find conditions require custom order, associations or conditions, then the $search
     * parameter can be a callable that takes the Query as the argument, or a SelectQuery object passed
     * as the $search parameter. Allowing you to customize the find results.
     *
     * ### Options
     *
     * The options array is passed to the save method with exception to the following keys:
     *
     * - atomic: Whether to execute the methods for find, save and callbacks inside a database
     *   transaction (default: true)
     * - defaults: Whether to use the search criteria as default values for the new entity (default: true)
     *
     * @param \Crustum\Mongo\ODM\Query\SelectQuery<TDocument|array<string, mixed>>|callable|array<string, mixed> $search The criteria to find existing
     *   documents by. Note that when you pass a query object you'll have to use
     *   the 2nd arg of the method to modify the entity data before saving.
     * @param callable|array<string, mixed>|null $callback An array of data key/value pairs or a callback that will
     *   be invoked for newly created entities. This callback will be called *before* the entity
     *   is persisted.
     * @param array<string, mixed> $options The options to use when saving.
     * @return \Cake\Datasource\EntityInterface A document.
     * @throws \Crustum\Mongo\ODM\Exception\PersistenceFailedException When the entity couldn't be saved
     */
    public function findOrCreate(
        SelectQuery|callable|array $search,
        callable|array|null $callback = null,
        array $options = [],
    ): EntityInterface {
        $options = new ArrayObject($options + [
            'atomic' => true,
            'defaults' => true,
        ]);

        $entity = $this->executeTransaction(
            fn() => $this->processFindOrCreate($search, $callback, $options->getArrayCopy()),
            (bool)$options['atomic'],
        );

        if ($entity && $this->transactionCommitted((bool)$options['atomic'], true)) {
            $this->dispatchEvent('Collection.afterSaveCommit', compact('entity', 'options'));
        }

        return $entity;
    }

    /**
     * Performs the actual find and/or create of an entity based on the passed options.
     *
     * @param \Crustum\Mongo\ODM\Query\SelectQuery<TDocument|array<string, mixed>>|callable|array<string, mixed> $search The criteria to find an existing document by, or a callable that will
     *   customize the find query.
     * @param callable|array<string, mixed>|null $callback Data or a callback that will be invoked for newly
     *   created entities. This callback will be called *before* the entity
     *   is persisted.
     * @param array<string, mixed> $options The options to use when saving.
     * @return \Cake\Datasource\EntityInterface|array<string, mixed> A document.
     * @throws \Crustum\Mongo\ODM\Exception\PersistenceFailedException When the entity couldn't be saved
     * @throws \InvalidArgumentException
     */
    protected function processFindOrCreate(
        SelectQuery|callable|array $search,
        callable|array|null $callback = null,
        array $options = [],
    ): EntityInterface|array {
        $query = $this->getFindOrCreateQuery($search);

        $row = $query->first();
        if ($row !== null) {
            return $row;
        }

        $data = $search;
        if (is_array($callback) && !is_callable($callback)) {
            $data = $callback + $search;
            $callback = null;
        }

        $document = $this->newEmptyDocument();
        if ($options['defaults'] && is_array($data)) {
            $patchableFields = array_combine(array_keys($data), array_fill(0, count($data), true));
            $document = $this->patchDocument($document, $data, ['patchableFields' => $patchableFields]);
        }
        if ($callback !== null) {
            /** @var \Cake\Datasource\EntityInterface $document */
            $document = $callback($document) ?: $document;
        }
        unset($options['defaults']);

        $result = $this->save($document, $options);

        if ($result === false) {
            throw new PersistenceFailedException($document, ['findOrCreate']);
        }

        return $document;
    }

    /**
     * Gets the query object for findOrCreate().
     *
     * @param \Crustum\Mongo\ODM\Query\SelectQuery<TDocument|array<string, mixed>>|callable|array<string, mixed> $search The criteria to find existing documents by.
     * @return \Crustum\Mongo\ODM\Query\SelectQuery<TDocument|array<string, mixed>>
     */
    protected function getFindOrCreateQuery(SelectQuery|callable|array $search): SelectQuery
    {
        if (is_callable($search)) {
            $query = $this->find();
            $search($query);
        } elseif (is_array($search)) {
            $query = $this->find()->where($search);
        } else {
            $query = $search;
        }

        return $query;
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
     * @throws \Crustum\Mongo\ODM\Exception\PersistenceFailedException When the document could not be saved.
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
     * Persists multiple documents of a collection.
     *
     * The records will be saved in a transaction which will be rolled back if
     * any one of the records fails to save due to failed validation or database
     * error.
     *
     * @template TSavedDocument of \Cake\Datasource\EntityInterface
     * @param iterable<TSavedDocument> $entities Documents to save.
     * @param array<string, mixed> $options Options used when calling save() for each document.
     * @return iterable<TSavedDocument>|false False on failure, documents list on success.
     * @throws \Exception
     */
    public function saveMany(iterable $entities, array $options = []): iterable|false
    {
        try {
            return $this->doSaveMany($entities, $options);
        } catch (PersistenceFailedException) {
            return false;
        }
    }

    /**
     * Persists multiple documents of a collection or throws a PersistenceFailedException
     * if any one of the records fails to save.
     *
     * The records will be saved in a transaction which will be rolled back if
     * any one of the records fails to save due to failed validation or database
     * error.
     *
     * @template TSavedDocument of \Cake\Datasource\EntityInterface
     * @param iterable<TSavedDocument> $entities Documents to save.
     * @param array<string, mixed> $options Options used when calling save() for each document.
     * @return iterable<TSavedDocument> Documents list.
     * @throws \Exception
     * @throws \Crustum\Mongo\ODM\Exception\PersistenceFailedException If a document couldn't be saved.
     */
    public function saveManyOrFail(iterable $entities, array $options = []): iterable
    {
        return $this->doSaveMany($entities, $options);
    }

    /**
     * @template TSavedDocument of \Cake\Datasource\EntityInterface
     * @param iterable<TSavedDocument> $entities Documents to save.
     * @param array<string, mixed> $options Options used when calling save() for each document.
     * @throws \Crustum\Mongo\ODM\Exception\PersistenceFailedException If a document couldn't be saved.
     * @throws \Exception If a document couldn't be saved.
     * @return iterable<TSavedDocument> Documents list.
     */
    protected function doSaveMany(iterable $entities, array $options = []): iterable
    {
        $options = new ArrayObject(
            $options + [
                'atomic' => true,
                'checkRules' => true,
                '_primary' => true,
            ],
        );
        $options['_cleanOnSuccess'] = false;

        /** @var array<bool> $isNew */
        $isNew = [];
        $cleanupOnFailure = function ($entities) use (&$isNew): void {
            /** @var iterable<\Cake\Datasource\EntityInterface> $entities */
            foreach ($entities as $key => $entity) {
                if (isset($isNew[$key]) && $isNew[$key]) {
                    $entity->unset($this->getPrimaryKey());
                    $entity->setNew(true);
                }
            }
        };

        /** @var \Cake\Datasource\EntityInterface|null $failed */
        $failed = null;
        try {
            $this->executeTransaction(function () use ($entities, $options, &$isNew, &$failed): bool {
                $options = (array)$options;
                foreach ($entities as $key => $entity) {
                    $isNew[$key] = $entity->isNew();
                    if ($this->save($entity, $options) === false) {
                        $failed = $entity;

                        return false;
                    }
                }

                return true;
            }, (bool)$options['atomic']);
        } catch (Exception $e) {
            $cleanupOnFailure($entities);

            throw $e;
        }

        if ($failed !== null) {
            $cleanupOnFailure($entities);

            throw new PersistenceFailedException($failed, ['saveMany']);
        }

        $cleanupOnSuccess = function (EntityInterface $entity) use (&$cleanupOnSuccess): void {
            $entity->clean();
            $entity->setNew(false);

            foreach (array_keys($entity->toArray()) as $field) {
                $value = $entity->get($field);

                if ($value instanceof EntityInterface) {
                    $cleanupOnSuccess($value);
                } elseif (is_array($value) && current($value) instanceof EntityInterface) {
                    foreach ($value as $associated) {
                        $cleanupOnSuccess($associated);
                    }
                }
            }
        };

        if ($this->transactionCommitted((bool)$options['atomic'], (bool)$options['_primary'])) {
            foreach ($entities as $entity) {
                $this->dispatchEvent('Collection.afterSaveCommit', compact('entity', 'options'));
                if ($options['atomic'] || $options['_primary']) {
                    $cleanupOnSuccess($entity);
                }
            }
        }

        return $entities;
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
     * Deletes multiple documents of a collection.
     *
     * The records will be deleted in a transaction which will be rolled back if
     * any one of the records fails to delete due to failed validation or database
     * error.
     *
     * @template TDeletedDocument of \Cake\Datasource\EntityInterface
     * @param iterable<TDeletedDocument> $entities Documents to delete.
     * @param array<string, mixed> $options Options used when calling delete() for each document.
     * @return iterable<TDeletedDocument>|false Documents list on success, false on failure.
     * @see \Crustum\Mongo\ODM\BaseCollection::delete() for options and events related to this method.
     */
    public function deleteMany(iterable $entities, array $options = []): iterable|false
    {
        $failed = $this->doDeleteMany($entities, $options);

        if ($failed !== null) {
            return false;
        }

        return $entities;
    }

    /**
     * Deletes multiple documents of a collection or throws a PersistenceFailedException
     * if any one of the records fails to delete.
     *
     * The records will be deleted in a transaction which will be rolled back if
     * any one of the records fails to delete due to failed validation or database
     * error.
     *
     * @param iterable<\Cake\Datasource\EntityInterface> $entities Documents to delete.
     * @param array<string, mixed> $options Options used when calling delete() for each document.
     * @return void
     * @throws \Crustum\Mongo\ODM\Exception\PersistenceFailedException
     * @see \Crustum\Mongo\ODM\BaseCollection::delete() for options and events related to this method.
     */
    public function deleteManyOrFail(iterable $entities, array $options = []): void
    {
        $failed = $this->doDeleteMany($entities, $options);

        if ($failed !== null) {
            throw new PersistenceFailedException($failed, ['deleteMany']);
        }
    }

    /**
     * @param iterable<\Cake\Datasource\EntityInterface> $entities Documents to delete.
     * @param array<string, mixed> $options Options used.
     * @return \Cake\Datasource\EntityInterface|null
     */
    protected function doDeleteMany(iterable $entities, array $options = []): ?EntityInterface
    {
        $options = new ArrayObject($options + [
                'atomic' => true,
                'checkRules' => true,
                '_primary' => true,
            ]);

        $failed = $this->executeTransaction(function () use ($entities, $options) {
            foreach ($entities as $entity) {
                if (!$this->processDelete($entity, $options)) {
                    return $entity;
                }
            }

            return null;
        }, (bool)$options['atomic']);

        if ($failed === null && $this->transactionCommitted((bool)$options['atomic'], (bool)$options['_primary'])) {
            foreach ($entities as $entity) {
                $this->dispatchEvent('Collection.afterDeleteCommit', [
                    'entity' => $entity,
                    'options' => $options,
                ]);
            }
        }

        return $failed;
    }

    /**
     * Try to delete a document or throw a PersistenceFailedException if the document is new,
     * has no primary key value, application rules checks failed or the delete was aborted by a callback.
     *
     * @param \Cake\Datasource\EntityInterface $entity The document to remove.
     * @param array<string, mixed> $options The options for the delete.
     * @return void
     * @throws \Crustum\Mongo\ODM\Exception\PersistenceFailedException
     * @see \Crustum\Mongo\ODM\BaseCollection::delete()
     */
    public function deleteOrFail(EntityInterface $entity, array $options = []): void
    {
        $deleted = $this->delete($entity, $options);
        if ($deleted === false) {
            throw new PersistenceFailedException($entity, ['delete']);
        }
    }

    /**
     * Creates an empty document.
     *
     * @return \Cake\Datasource\EntityInterface
     */
    public function newEmptyDocument(): EntityInterface
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
    public function newDocument(array $data, array $options = []): EntityInterface
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
    public function newDocuments(array $data, array $options = []): array
    {
        $options['associated'] ??= $this->associations->keys();

        return $this->marshaller()->many($data, $options);
    }

    /**
     * Merges input data into an existing document.
     *
     * @param \Cake\Datasource\EntityInterface $document The document.
     * @param array<string, mixed> $data Input data.
     * @param array<string, mixed> $options Marshalling options.
     * @return \Cake\Datasource\EntityInterface
     */
    public function patchDocument(EntityInterface $document, array $data, array $options = []): EntityInterface
    {
        $this->assertDocumentClass($document);

        if (!$document instanceof Document) {
            throw new InvalidArgumentException('patchDocument() requires a Document.');
        }

        $options['associated'] ??= $this->associations->keys();

        return $this->marshaller()->merge($document, $data, $options);
    }

    /**
     * Merges input rows into matching documents.
     *
     * @param iterable<mixed> $documents Existing documents.
     * @param array<int, mixed> $data Input rows.
     * @param array<string, mixed> $options Marshalling options.
     * @return array<int, \Cake\Datasource\EntityInterface>
     */
    public function patchDocuments(iterable $documents, array $data, array $options = []): array
    {
        foreach ($documents as $document) {
            $this->assertDocumentClass($document);
        }

        $options['associated'] ??= $this->associations->keys();

        return $this->marshaller()->mergeMany($documents, $data, $options);
    }

    /**
     * Interface-compat wrapper for {@see newEmptyDocument()}.
     *
     * @return \Cake\Datasource\EntityInterface
     */
    public function newEmptyEntity(): EntityInterface
    {
        return $this->newEmptyDocument();
    }

    /**
     * Interface-compat wrapper for {@see newDocument()}.
     *
     * @param array<string, mixed> $data Input data.
     * @param array<string, mixed> $options Marshalling options.
     * @return \Cake\Datasource\EntityInterface
     */
    public function newEntity(array $data, array $options = []): EntityInterface
    {
        return $this->newDocument($data, $options);
    }

    /**
     * Interface-compat wrapper for {@see newDocuments()}.
     *
     * @param array<int, mixed> $data Input rows.
     * @param array<string, mixed> $options Marshalling options.
     * @return array<int, \Cake\Datasource\EntityInterface>
     */
    public function newEntities(array $data, array $options = []): array
    {
        return $this->newDocuments($data, $options);
    }

    /**
     * Interface-compat wrapper for {@see patchDocument()}.
     *
     * @param \Cake\Datasource\EntityInterface $entity The document.
     * @param array<string, mixed> $data Input data.
     * @param array<string, mixed> $options Marshalling options.
     * @return \Cake\Datasource\EntityInterface
     */
    public function patchEntity(EntityInterface $entity, array $data, array $options = []): EntityInterface
    {
        return $this->patchDocument($entity, $data, $options);
    }

    /**
     * Interface-compat wrapper for {@see patchDocuments()}.
     *
     * @param iterable<mixed> $entities Existing documents.
     * @param array<int, mixed> $data Input rows.
     * @param array<string, mixed> $options Marshalling options.
     * @return array<int, \Cake\Datasource\EntityInterface>
     */
    public function patchEntities(iterable $entities, array $data, array $options = []): array
    {
        return $this->patchDocuments($entities, $data, $options);
    }

    /**
     * Get the default connection name.
     *
     * This method is used to get the fallback connection name if an
     * instance is created through the CollectionLocator without a connection.
     *
     * @return string
     * @see \Crustum\Mongo\ODM\Locator\CollectionLocator::get()
     */
    public static function defaultConnectionName(): string
    {
        return 'default';
    }

    /**
     * Gets the connection used by this collection.
     *
     * Resolves the configured default connection lazily when no connection
     * was injected, matching `Cake\ORM\Table::getConnection()`.
     *
     * @return \Crustum\Mongo\Database\Connection|null
     */
    public function getConnection(): ?Connection
    {
        if (!$this->connection instanceof Connection) {
            $connection = ConnectionManager::get(static::defaultConnectionName());
            if ($connection instanceof Connection) {
                $this->connection = $connection;
            }
        }

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
     * Lazy-creates an empty `CollectionSchema` when none was set explicitly, so
     * callers never receive `null`. Mongo is schemaless: fields come from the
     * application schema readers (`setSchemaFromDto`, `setSchemaFromDocument`,
     * `setSchemaFromArray`, `addColumn`) rather than database introspection.
     *
     * @return \Cake\Datasource\SchemaInterface
     */
    public function getSchema(): SchemaInterface
    {
        return $this->schema ??= new CollectionSchema($this->getCollection());
    }

    /**
     * Returns an introspected schema for this collection.
     *
     * Unlike `getSchema()`, this describes the collection from the database
     * (validators, indexes) so rules such as `existsIn` can inspect nullable
     * fields. When no connection is available or introspection fails, the
     * schema is returned as-is.
     *
     * @return \Cake\Datasource\SchemaInterface
     */
    public function describeSchema(): SchemaInterface
    {
        $connection = $this->getConnection();
        if (!$connection instanceof Connection) {
            return $this->getSchema();
        }

        try {
            return $connection->getSchemaCollection()->describe($this->getCollection());
        } catch (Throwable) {
            return $this->getSchema();
        }
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
     * Build this collection's schema from a plain field definition array.
     *
     * Each key is a field name and each value is a `CollectionSchema::addField()`
     * attribute array (or a type string). Sugar for the ported cake60 tables
     * that called `Table::setSchema([...])` with SQL-style column definitions.
     *
     * @param array<string, array<string, mixed>|string> $fields Field definitions.
     * @return $this
     */
    public function setSchemaFromArray(array $fields): static
    {
        $schema = new CollectionSchema($this->getCollection());
        foreach ($fields as $name => $attrs) {
            $schema->addField($name, $attrs);
        }

        return $this->setSchema($schema);
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
     * Checks whether an association with the given alias is registered.
     *
     * @param string $name The association alias.
     * @return bool True when the association exists.
     */
    public function hasAssociation(string $name): bool
    {
        return $this->associations->has($name);
    }

    /**
     * Magic property accessor for associations.
     *
     * `$collection->Users` returns the `Users` association (which forwards
     * method calls to its target via `Association::__call()`), matching
     * cake60 `Table::__get()`.
     *
     * @param string $property The association alias.
     * @return \Crustum\Mongo\ODM\Association
     * @throws \Cake\Database\Exception\DatabaseException When no such association is defined.
     */
    public function __get(string $property): Association
    {
        $association = $this->associations->get($property);
        if (!$association instanceof Association) {
            throw new DatabaseException(sprintf(
                'Undefined property `%s`. You have not defined the `%s` association on `%s`.',
                $property,
                $property,
                static::class,
            ));
        }

        return $association;
    }

    /**
     * Checks whether a magic association property exists.
     *
     * @param string $property The association alias.
     * @return bool
     */
    public function __isset(string $property): bool
    {
        return $this->associations->has($property);
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
     * Binds multiple associations in a single call, indexed by association type.
     *
     * ```
     * $this->addAssociations([
     *     'belongsTo' => ['Authors', 'Categories'],
     *     'hasMany' => ['Comments' => ['dependent' => true]],
     * ]);
     * ```
     *
     * Numeric keys are treated as association aliases with empty options.
     *
     * @param array<string, array<int|string, mixed>> $params Set of associations to bind (indexed by association type).
     * @return $this
     * @see \Crustum\Mongo\ODM\BaseCollection::belongsTo()
     * @see \Crustum\Mongo\ODM\BaseCollection::hasOne()
     * @see \Crustum\Mongo\ODM\BaseCollection::hasMany()
     * @see \Crustum\Mongo\ODM\BaseCollection::belongsToMany()
     */
    public function addAssociations(array $params): static
    {
        foreach ($params as $assocType => $tables) {
            foreach ($tables as $associated => $options) {
                if (is_int($associated)) {
                    $associated = $options;
                    $options = [];
                }
                $this->{$assocType}($associated, $options);
            }
        }

        return $this;
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
     * @throws \Crustum\Mongo\ODM\Exception\MissingDocumentException When the class cannot be found.
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
     * Enables the assertion that documents passed to save/delete/patch/loadInto
     * match the collection's configured document class.
     *
     * @param bool $enable Whether to enable. Defaults to true.
     * @return $this
     * @see \Crustum\Mongo\ODM\BaseCollection::assertDocumentClass()
     */
    public function enableDocumentClassAssertion(bool $enable = true): static
    {
        $this->assertDocumentClass = $enable;

        return $this;
    }

    /**
     * Disables the document-class assertion for this collection. Use when foreign
     * documents are passed intentionally (e.g. polymorphic patterns).
     *
     * @return $this
     */
    public function disableDocumentClassAssertion(): static
    {
        $this->assertDocumentClass = false;

        return $this;
    }

    /**
     * Returns whether the document-class assertion is enabled for this collection.
     *
     * @return bool
     */
    public function isDocumentClassAssertionEnabled(): bool
    {
        return $this->assertDocumentClass;
    }

    /**
     * Asserts that the given document belongs to this collection instance.
     *
     * The document must either be an instance of the collection's configured
     * document class, or an instance of the generic ``\Crustum\Mongo\ODM\Document``
     * class. The generic class is allowed as an escape hatch for ad-hoc usage
     * such as ``$collection->delete(new Document(['_id' => $id]))``.
     *
     * Catches mistakes like ``$this->Invoices->delete($orderDocument)`` where
     * a document from a different collection is passed.
     *
     * @param \Cake\Datasource\EntityInterface $entity The document to validate.
     * @return void
     * @throws \InvalidArgumentException When the document does not match the
     *   configured document class.
     */
    protected function assertDocumentClass(EntityInterface $entity): void
    {
        if (!$this->assertDocumentClass) {
            return;
        }

        if ($entity->getSource() === $this->getRegistryAlias()) {
            return;
        }

        $documentClass = $this->getDocumentClass();
        if ($entity instanceof $documentClass || $entity::class === Document::class) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'Entity of class `%s` does not match the document class `%s` configured for collection `%s`.',
            $entity::class,
            $documentClass,
            $this->getRegistryAlias(),
        ));
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
    public function invokeFinder(Closure $callable, SelectQuery $query, array $args): SelectQuery
    {
        $reflected = new ReflectionFunction($callable);
        $params = $reflected->getParameters();

        if ($args !== []) {
            $unNamedArgs = [];
            $namedArgs = [];
            foreach ($args as $key => $value) {
                if (is_int($key)) {
                    $unNamedArgs[$key] = $value;
                } else {
                    $namedArgs[$key] = $value;
                }
            }

            $query->applyOptions($namedArgs);
            // Fetch custom args without the query options.
            $args = $unNamedArgs + array_intersect_key($args, $query->getOptions());

            unset($params[0]);
            $lastParam = end($params);
            reset($params);

            if ($lastParam === false || !$lastParam->isVariadic()) {
                $paramNames = [];
                foreach ($params as $param) {
                    $paramNames[] = $param->getName();
                }

                foreach (array_keys($args) as $key) {
                    if (is_string($key) && !in_array($key, $paramNames, true)) {
                        unset($args[$key]);
                    }
                }
            }
        }

        $result = $callable($query, ...$args);
        if (!$result instanceof SelectQuery) {
            throw new LogicException('Finder must return the query it was given.');
        }

        return $result;
    }

    /**
     * Provides the dynamic findBy and findAllBy methods.
     *
     * @param string $method The method name that was fired.
     * @param array<int, mixed> $args List of arguments passed to the function.
     * @return \Crustum\Mongo\ODM\Query\SelectQuery<TDocument|array>
     * @throws \BadMethodCallException when there are missing arguments, or when
     *  and & or are combined.
     */
    protected function dynamicFinder(string $method, array $args): SelectQuery
    {
        $method = Inflector::underscore($method);
        preg_match('/^find_([\w]+)_by_/', $method, $matches);
        if (!$matches) {
            // find_by_ is 8 characters.
            $fields = substr($method, 8);
            $findType = 'all';
        } else {
            $fields = substr($method, strlen($matches[0]));
            $findType = Inflector::variable($matches[1]);
        }
        $hasOr = str_contains($fields, '_or_');
        $hasAnd = str_contains($fields, '_and_');

        $makeConditions = function ($fields, $args): array {
            $conditions = [];
            if (count($args) < count($fields)) {
                throw new BadMethodCallException(sprintf(
                    'Not enough arguments for magic finder. Got %s required %s',
                    count($args),
                    count($fields),
                ));
            }
            foreach ($fields as $field) {
                $conditions[$this->aliasField($field)] = array_shift($args);
            }

            return $conditions;
        };

        if ($hasOr && $hasAnd) {
            throw new BadMethodCallException(
                'Cannot mix "and" & "or" in a magic finder. Use find() instead.',
            );
        }

        if ($hasOr === false && $hasAnd === false) {
            $conditions = $makeConditions([$fields], $args);
        } elseif ($hasOr) {
            $fields = explode('_or_', $fields);
            $conditions = [
                'OR' => $makeConditions($fields, $args),
            ];
        } else {
            $fields = explode('_and_', $fields);
            $conditions = $makeConditions($fields, $args);
        }

        return $this->find($findType, conditions: $conditions);
    }

    /**
     * Handles dynamic finders.
     *
     * @param string $method name of the method to be invoked
     * @param array<int, mixed> $args List of arguments passed to the function
     * @return mixed
     * @throws \BadMethodCallException
     */
    public function __call(string $method, array $args): mixed
    {
        if (preg_match('/^find(?:\w+)?By/', $method) > 0) {
            return $this->dynamicFinder($method, $args);
        }

        throw new BadMethodCallException(
            sprintf('Unknown method `%s` called on `%s`', $method, static::class),
        );
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
        $this->assertDocumentClass($entity);

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
            $success = $this->onSaveSuccess($entity, $options);
        }

        if (!$success && $isNew) {
            $entity->unset($this->getPrimaryKey());
            $entity->setNew(true);
        }

        return $success ? $entity : false;
    }

    /**
     * Handles the saving of children associations and executing the afterSave logic
     * once the document for this collection has been saved successfully.
     *
     * @param \Cake\Datasource\EntityInterface $entity the document to be saved
     * @param \ArrayObject<string, mixed> $options the options to use for the save operation
     * @return bool True on success
     * @throws \Crustum\Mongo\ODM\Exception\RolledbackTransactionException If the transaction
     *   is aborted in the afterSave event.
     */
    protected function onSaveSuccess(EntityInterface $entity, ArrayObject $options): bool
    {
        $success = $this->saveChildren($entity, $options);

        if (!$success && $options['atomic']) {
            return false;
        }

        $connection = $this->getConnection();
        if ($options['atomic'] && $connection instanceof Connection && !$connection->inTransaction()) {
            throw new RolledbackTransactionException(['collection' => static::class]);
        }

        if (!$options['atomic'] && !$options['_primary']) {
            $entity->clean();
            $entity->setNew(false);
            $entity->setSource($this->getRegistryAlias());
        }

        return true;
    }

    /**
     * Inserts a new document into the collection.
     *
     * @param \Cake\Datasource\EntityInterface $entity The document.
     * @return \Cake\Datasource\EntityInterface|false
     */
    protected function insert(EntityInterface $entity): EntityInterface|false
    {
        $primaryKey = (array)$this->getPrimaryKey();
        $data = $entity->toArray();

        // Generate the primary key up front (like cake _newId) so a new
        // document always has an `_id` on the entity after the insert.
        if (!$entity->has($primaryKey)) {
            $newId = $this->newId(array_values($primaryKey));
            if ($newId !== null) {
                $entity->set('_id', $newId);
                $data['_id'] = $newId;
            }
        }

        $query = $this->queryFactory->insert($this);
        $query->values($data);

        $ids = $query->execute();
        $ids = is_array($ids) ? $ids : [];
        if ($ids === []) {
            return false;
        }

        if (isset($data['_id'])) {
            $entity->set('_id', $data['_id']);
        } elseif (isset($ids[0]) && $ids[0] !== '') {
            $entity->set('_id', $ids[0]);
        }

        return $entity;
    }

    /**
     * Generates a primary key value for a new document.
     *
     * Only single-column primary keys generate; composite keys return null
     * (Mongo assigns them). Delegates to the configured id generator / type.
     *
     * @param list<string> $primary The primary key columns.
     * @return string|null The generated id, or null when not applicable.
     */
    protected function newId(array $primary): ?string
    {
        if (count($primary) !== 1) {
            return null;
        }

        $schema = $this->getSchema();
        $typeName = $schema->getColumnType($primary[0]);
        if ($typeName === null) {
            return null;
        }

        $type = TypeFactory::build($typeName);

        return (string)$type->newId();
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
        $this->assertDocumentClass($entity);

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
        if (in_array(true, $associated, true)) {
            return $this->associations->keys();
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
     * Loads the specified associations in the passed document or list of documents
     * by executing extra queries in the collection and merging the results in the
     * appropriate properties.
     *
     * ### Example:
     *
     * ```
     * $user = $usersCollection->get(1);
     * $user = $usersCollection->loadInto($user, ['Articles.Tags', 'Articles.Comments']);
     * echo $user->articles[0]->title;
     * ```
     *
     * You can also load associations for multiple documents at once
     *
     * ### Example:
     *
     * ```
     * $users = $usersCollection->find()->where([...])->toArray();
     * $users = $usersCollection->loadInto($users, ['Articles.Tags', 'Articles.Comments']);
     * echo $user[1]->articles[0]->title;
     * ```
     *
     * The properties for the associations to be loaded will be overwritten on each document.
     *
     * @param \Cake\Datasource\EntityInterface|array<\Cake\Datasource\EntityInterface> $entities a single document or list of documents
     * @param array<int|string, mixed> $contain A `contain()` compatible array.
     * @see \Crustum\Mongo\ODM\Query\SelectQuery::contain()
     * @return \Cake\Datasource\EntityInterface|array<\Cake\Datasource\EntityInterface>
     */
    public function loadInto(EntityInterface|array $entities, array $contain): EntityInterface|array
    {
        if ($entities instanceof EntityInterface) {
            $this->assertDocumentClass($entities);
        } else {
            foreach ($entities as $entity) {
                $this->assertDocumentClass($entity);
            }
        }

        /** @var \Cake\Datasource\EntityInterface|array<\Cake\Datasource\EntityInterface> $result */
        $result = new LazyEagerLoader()->loadInto($entities, $contain, $this);

        return $result;
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
        foreach (['title', 'name', 'label'] as $field) {
            if ($schema->hasColumn($field)) {
                return $this->displayField = $field;
            }
        }

        foreach ($schema->columns() as $column) {
            $columnSchema = $schema->getColumn($column);
            if (
                $columnSchema &&
                ($columnSchema['null'] ?? false) !== true &&
                ($columnSchema['type'] ?? null) === 'string' &&
                !preg_match('/pass|token|secret/i', $column)
            ) {
                return $this->displayField = $column;
            }
        }

        return $this->displayField = $this->getPrimaryKey();
    }

    /**
     * Resolves an association by name, following dotted aliases.
     *
     * @param string $name The alias used for the association.
     * @return \Crustum\Mongo\ODM\Association|null Either the association or null.
     */
    protected function findAssociation(string $name): ?Association
    {
        if (!str_contains($name, '.')) {
            return $this->associations->get($name);
        }

        $result = null;
        [$name, $next] = array_pad(explode('.', $name, 2), 2, null);
        if ($name !== null) {
            $result = $this->associations->get($name);
        }

        if ($result !== null && $next !== null) {
            return $result->getTarget()->getAssociation($next);
        }

        return $result;
    }

    /**
     * Validator method used to check the uniqueness of a value for a column.
     * This is meant to be used with the validation API and not to be called
     * directly.
     *
     * ### Example:
     *
     * ```
     * $validator->add('email', [
     *  'unique' => ['rule' => 'validateUnique', 'provider' => 'table']
     * ])
     * ```
     *
     * Unique validation can be scoped to the value of another column:
     *
     * ```
     * $validator->add('email', [
     *  'unique' => [
     *      'rule' => ['validateUnique', ['scope' => 'site_id']],
     *      'provider' => 'table'
     *  ]
     * ]);
     * ```
     *
     * In the above example, the email uniqueness will be scoped to only documents having
     * the same site_id. Scoping will only be used if the scoping field is present in
     * the data to be validated.
     *
     * @param mixed $value The value of column to be checked for uniqueness.
     * @param array<string, mixed> $options The options array, optionally containing the 'scope' key.
     *   May also be the validation context, if there are no options.
     * @param array<string, mixed>|null $context Either the validation context or null.
     * @return bool True if the value is unique, or false if a non-scalar, non-unique value was given.
     */
    public function validateUnique(mixed $value, array $options = [], ?array $context = null): bool
    {
        if ($context === null) {
            $context = $options;
        }
        $entity = new ($this->getDocumentClass())(
            $context['data'],
            [
                'useSetters' => false,
                'markNew' => $context['newRecord'],
                'source' => $this->getRegistryAlias(),
            ],
        );
        $fields = array_merge(
            [$context['field']],
            isset($options['scope']) ? (array)$options['scope'] : [],
        );
        $values = $entity->extract($fields);
        foreach ($values as $field) {
            if ($field !== null && !is_scalar($field)) {
                return false;
            }
        }
        $class = static::IS_UNIQUE_CLASS;
        $rule = new $class($fields, $options);

        return $rule($entity, ['repository' => $this]);
    }

    /**
     * Returns an array that can be used to describe the internal state of this
     * object.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        $connection = $this->getConnection();

        return [
            'registryAlias' => $this->getRegistryAlias(),
            'collection' => $this->getCollection(),
            'alias' => $this->getAlias(),
            'documentClass' => $this->getDocumentClass(),
            'associations' => $this->associations->keys(),
            'behaviors' => $this->behaviors->loaded(),
            'defaultConnection' => static::defaultConnectionName(),
            'connectionName' => $connection instanceof Connection ? $connection->configName() : null,
        ];
    }
}

<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM;

use Cake\Core\App;
use Cake\Core\ConventionsTrait;
use Cake\Database\Exception\DatabaseException;
use Cake\Datasource\EntityInterface;
use Cake\Datasource\QueryInterface;
use Cake\Utility\Inflector;
use Closure;
use Crustum\Mongo\Database\Aggregation\AggregationBuilder;
use Crustum\Mongo\ODM\Locator\LocatorAwareTrait;
use Crustum\Mongo\ODM\Query\SelectQuery;
use InvalidArgumentException;
use function Cake\Core\pluginSplit;
use function Cake\Core\triggerWarning;

/**
 * An association describes a relationship between ODM collections.
 *
 * Associations configure the source and target collections, key mapping,
 * loading strategy, property name, and dependent-delete behavior. Concrete
 * associations provide the relationship type, eager loader, and MongoDB
 * aggregation pipeline.
 *
 * @see cake60/src/ORM/Association.php
 * @see src/ODM/Association.php
 */
abstract class Association
{
    use ConventionsTrait;
    use LocatorAwareTrait;

    /**
     * Association type for many-to-one relationships.
     */
    public const string MANY_TO_ONE = 'manyToOne';

    /**
     * Association type for one-to-many relationships.
     */
    public const string ONE_TO_MANY = 'oneToMany';

    /**
     * Association type for one-to-one relationships.
     */
    public const string ONE_TO_ONE = 'oneToOne';

    /**
     * Association type for many-to-many relationships.
     */
    public const string MANY_TO_MANY = 'manyToMany';

    /**
     * Strategy that loads referenced documents with a separate query.
     */
    public const string STRATEGY_SELECT = 'select';

    /**
     * Strategy that loads referenced documents with an aggregation lookup.
     */
    public const string STRATEGY_LOOKUP = 'lookup';

    /**
     * Strategy that hydrates documents from the root document.
     */
    public const string STRATEGY_EMBED = 'embed';

    /**
     * Association alias.
     *
     * @var string
     */
    protected string $name;

    /**
     * Target collection class name or alias.
     *
     * @var string
     */
    protected string $className;

    /**
     * Entity property populated by the association.
     *
     * @var string|null
     */
    protected ?string $propertyName = null;

    /**
     * Foreign key fields on the target collection.
     *
     * `false` disables the foreign key (cake60 semantics).
     *
     * @var array<string>|string|false|null
     */
    protected string|array|false|null $foreignKey = null;

    /**
     * Binding key fields on the source collection.
     *
     * When not configured, the owning side's primary key is used (default `_id`).
     *
     * @var array<string>|string|null
     */
    protected string|array|null $bindingKey = null;

    /**
     * Conditions always applied while loading the target.
     *
     * @var \Closure|array<string, mixed>
     */
    protected Closure|array $conditions = [];

    /**
     * Configured association loading strategy.
     *
     * @var string|null
     */
    protected ?string $strategy = null;

    /**
     * Strategies supported by this association.
     *
     * @var array<string>
     */
    protected array $validStrategies = [self::STRATEGY_SELECT, self::STRATEGY_LOOKUP, self::STRATEGY_EMBED];

    /**
     * Source collection.
     *
     * @var \Crustum\Mongo\ODM\BaseCollection|null
     */
    protected ?BaseCollection $source = null;

    /**
     * Target collection.
     *
     * @var \Crustum\Mongo\ODM\BaseCollection|null
     */
    protected ?BaseCollection $target = null;

    /**
     * Document class used for hydrated associated documents.
     *
     * @var string|null
     */
    protected ?string $documentClass = null;

    /**
     * Whether target documents depend on the source document.
     *
     * @var bool
     */
    protected bool $dependent = false;

    /**
     * Delete action used for dependent documents.
     *
     * @var string
     */
    protected string $onDelete = 'nullify';

    /**
     * Whether cascade callbacks fire for dependent operations.
     *
     * @var bool
     */
    protected bool $cascadeCallbacks = false;

    /**
     * Finder used when loading the target.
     *
     * @var array<string, mixed>|string
     */
    protected array|string $finder = 'all';

    /**
     * Constructor.
     *
     * @param string $alias Association alias.
     * @param \Crustum\Mongo\ODM\BaseCollection $source Source collection.
     * @param array<string, mixed> $options Association configuration.
     */
    public function __construct(string $alias, BaseCollection $source, array $options = [])
    {
        [, $this->name] = pluginSplit($alias);
        $this->className = $options['className'] ?? $this->name;
        $this->propertyName = $options['propertyName'] ?? null;
        $this->foreignKey = $options['foreignKey'] ?? null;
        $this->bindingKey = $options['bindingKey'] ?? null;
        $this->conditions = $options['conditions'] ?? [];
        $this->dependent = (bool)($options['dependent'] ?? false);
        $this->onDelete = (string)($options['onDelete'] ?? ($this->dependent ? 'cascade' : 'nullify'));
        $this->cascadeCallbacks = (bool)($options['cascadeCallbacks'] ?? false);
        $this->finder = $options['finder'] ?? 'all';
        if (isset($options['strategy'])) {
            $this->setStrategy((string)$options['strategy']);
        }

        if (isset($options['collectionLocator'])) {
            $this->setCollectionLocator($options['collectionLocator']);
        }

        $this->setSource($source);

        if (isset($options['target'])) {
            $this->setTarget($options['target']);
        }

        if (isset($options['documentClass'])) {
            $class = (string)$options['documentClass'];
            if (!is_a($class, Document::class, true)) {
                throw new InvalidArgumentException('The entity class must extend Document.');
            }

            $this->documentClass = $class;
        }

        $this->options($options);
    }

    /**
     * Override this function to initialize any concrete association class, it will
     * get passed the original list of options used in the constructor
     *
     * @param array<string, mixed> $options List of options used for initialization
     * @return void
     */
    protected function options(array $options): void
    {
    }

    /**
     * Gets the association name.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Gets the association alias.
     *
     * @return string
     */
    public function getAlias(): string
    {
        return $this->name;
    }

    /**
     * Gets the target property name.
     *
     * @return string
     */
    public function getProperty(): string
    {
        if ($this->propertyName === null) {
            $this->setProperty($this->propertyName());
        }

        if (
            in_array($this->propertyName, $this->getSource()->getSchema()->columns(), true)
        ) {
            triggerWarning(sprintf(
                'Association property name `%s` clashes with field of same name of table `%s`.',
                $this->propertyName,
                $this->getSource()->getCollection(),
            ));
        }

        return $this->propertyName;
    }

    /**
     * Sets the target property name.
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
     * Returns the default property name based on the association name.
     *
     * @return string
     */
    protected function propertyName(): string
    {
        [, $name] = pluginSplit($this->name);

        return Inflector::underscore($name);
    }

    /**
     * Gets the target foreign key.
     *
     * @return array<string>|string|false|null
     */
    public function getForeignKey(): string|array|false|null
    {
        return $this->foreignKey;
    }

    /**
     * Sets the target foreign key.
     *
     * @param array<string>|string|false|null $key Foreign key fields.
     * @return $this
     */
    public function setForeignKey(string|array|false|null $key): static
    {
        $this->foreignKey = $key;

        return $this;
    }

    /**
     * Gets the source binding key.
     *
     * When not manually specified, the primary key of the owning side is used.
     * The owning side is the source collection when `isOwningSide()` is true,
     * otherwise the target collection.
     *
     * @return array<string>|string
     */
    public function getBindingKey(): string|array
    {
        if ($this->bindingKey === null) {
            $this->bindingKey = $this->isOwningSide() ?
                $this->getSource()->getPrimaryKey() :
                $this->getTarget()->getPrimaryKey();
        }

        return $this->bindingKey;
    }

    /**
     * Sets the source binding key.
     *
     * @param array<string>|string $key Binding key fields.
     * @return $this
     */
    public function setBindingKey(string|array $key): static
    {
        $this->bindingKey = $key;

        return $this;
    }

    /**
     * Gets conditions applied to target queries.
     *
     * @return \Closure|array<string, mixed>
     */
    public function getConditions(): Closure|array
    {
        return $this->conditions;
    }

    /**
     * Sets conditions applied to target queries.
     *
     * @param \Closure|array<string, mixed> $conditions Target conditions.
     * @return $this
     */
    public function setConditions(Closure|array $conditions): static
    {
        $this->conditions = $conditions;

        return $this;
    }

    /**
     * Gets the source collection.
     *
     * @return \Crustum\Mongo\ODM\BaseCollection
     * @throws \InvalidArgumentException If the source is not configured.
     */
    public function getSource(): BaseCollection
    {
        return $this->source ?? throw new InvalidArgumentException('Association source is not set.');
    }

    /**
     * Sets the source collection.
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $source Source collection.
     * @return $this
     */
    public function setSource(BaseCollection $source): static
    {
        $this->source = $source;

        return $this;
    }

    /**
     * Gets the target collection.
     *
     * When no target was set explicitly, it is resolved lazily through the
     * collection locator using the association alias (matching cake60
     * `Association::getTarget()`), so `belongsTo('Users')` targets the `Users`
     * collection without explicit wiring.
     *
     * @return \Crustum\Mongo\ODM\BaseCollection
     * @throws \InvalidArgumentException If the target cannot be resolved.
     */
    public function getTarget(): BaseCollection
    {
        if (!$this->target instanceof BaseCollection) {
            if (str_contains($this->className, '.')) {
                [$plugin] = pluginSplit($this->className, true);
                $registryAlias = $plugin . $this->name;
            } else {
                $registryAlias = $this->name;
            }

            $locator = $this->getCollectionLocator();

            $config = [];
            $exists = $locator->exists($registryAlias);
            if (!$exists) {
                $config = ['className' => $this->className];
            }

            $target = $locator->get($registryAlias, $config);
            if (!$target instanceof BaseCollection) {
                throw new InvalidArgumentException(sprintf(
                    'Association `%s` target `%s` did not resolve to a BaseCollection.',
                    $this->getName(),
                    $registryAlias,
                ));
            }

            if ($exists) {
                $className = App::className($this->className, 'Model/Collection', 'Collection') ?: BaseCollection::class;
                if (!$target instanceof $className) {
                    $msg = "`%s` association `%s` of type `%s` to `%s` doesn't match the expected class `%s`. ";
                    $msg .= "You can't have an association of the same name with a different target ";
                    $msg .= '`className` option anywhere in your app.';

                    throw new DatabaseException(sprintf(
                        $msg,
                        $this->getSource()::class,
                        $this->getName(),
                        $this->type(),
                        $target::class,
                        $className,
                    ));
                }
            }

            $this->target = $target;
        }

        return $this->target;
    }

    /**
     * Sets the target collection.
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $target Target collection.
     * @return $this
     */
    public function setTarget(BaseCollection $target): static
    {
        $this->target = $target;

        return $this;
    }

    /**
     * Gets the associated document class.
     *
     * @return string
     */
    public function getDocumentClass(): string
    {
        return $this->documentClass ??= Document::class;
    }

    /**
     * Converts a key configuration into a pipeline field name.
     *
     * Composite keys are represented by their ordered dotted path here; the
     * collection query layer can provide tuple handling when required.
     *
     * @param array<string>|string|null $key Key configuration.
     * @return string
     */
    protected function fieldName(array|string|null $key): string
    {
        return implode('.', (array)$key);
    }

    /**
     * Sets the associated document class.
     *
     * @param class-string<\Crustum\Mongo\ODM\Document> $class Document class.
     * @return $this
     */
    public function setDocumentClass(string $class): static
    {
        $this->documentClass = $class;

        return $this;
    }

    /**
     * Gets whether target documents are dependent on the source.
     *
     * @return bool
     */
    public function getDependent(): bool
    {
        return $this->dependent;
    }

    /**
     * Sets whether target documents are dependent on the source.
     *
     * @param bool $dependent Dependency flag.
     * @return $this
     */
    public function setDependent(bool $dependent): static
    {
        $this->dependent = $dependent;

        return $this;
    }

    /**
     * Gets the dependent delete action.
     *
     * @return string
     */
    public function getOnDelete(): string
    {
        return $this->onDelete;
    }

    /**
     * Sets the dependent delete action.
     *
     * @param string $onDelete Delete action.
     * @return $this
     */
    public function setOnDelete(string $onDelete): static
    {
        $this->onDelete = $onDelete;

        return $this;
    }

    /**
     * Applies dependent deletion or nullification to referenced documents.
     *
     * @param \Cake\Datasource\EntityInterface $entity The source document.
     * @param array<string, mixed> $options Delete options.
     * @return bool Whether the dependent operation succeeded.
     */
    public function cascadeDelete(EntityInterface $entity, array $options = []): bool
    {
        if (!$this->dependent && $this->onDelete !== 'nullify') {
            return true;
        }

        $keys = array_combine(
            (array)$this->getForeignKey(),
            $entity->extract((array)$this->getBindingKey()),
        );
        if ($keys === [] || in_array(null, $keys, true)) {
            return true;
        }

        if ($this->onDelete === 'cascade' || $this->dependent) {
            $this->getTarget()->deleteAll($keys);

            return true;
        }

        $this->getTarget()->updateAll(array_fill_keys((array)$this->getForeignKey(), null), $keys);

        return true;
    }

    /**
     * Gets the loading strategy.
     *
     * @return string
     */
    public function getStrategy(): string
    {
        return $this->strategy ??= $this->defaultStrategy();
    }

    /**
     * Sets the loading strategy.
     *
     * @param string $strategy Strategy name.
     * @return $this
     * @throws \InvalidArgumentException If the strategy is unsupported.
     */
    public function setStrategy(string $strategy): static
    {
        if (!in_array($strategy, $this->validStrategies, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid strategy "%s". Valid strategies are: %s',
                $strategy,
                implode(', ', $this->validStrategies),
            ));
        }

        $this->strategy = $strategy;

        return $this;
    }

    /**
     * Gets the default loading strategy.
     *
     * @return string
     */
    protected function defaultStrategy(): string
    {
        return self::STRATEGY_SELECT;
    }

    /**
     * Saves the associated documents for this association.
     *
     * Default behavior is a no-op; associations that persist target documents
     * (BelongsTo, HasOne, HasMany) override this.
     *
     * @param \Cake\Datasource\EntityInterface $entity The source document.
     * @param array<string, mixed> $options Save options.
     * @return \Cake\Datasource\EntityInterface|false
     */
    public function saveAssociated(EntityInterface $entity, array $options = []): EntityInterface|false
    {
        return $entity;
    }

    /**
     * Whether this side of the association owns the link.
     *
     * The owning side holds the foreign key and drives link maintenance.
     *
     * @return bool
     */
    public function isOwningSide(): bool
    {
        return false;
    }

    /**
     * Whether the association can be loaded through an in-pipeline join.
     *
     * @return bool
     */
    public function canBeJoined(): bool
    {
        return false;
    }

    /**
     * Creates an aggregation builder for this association.
     *
     * @return \Crustum\Mongo\Database\Aggregation\AggregationBuilder
     */
    protected function buildAggregation(): AggregationBuilder
    {
        return new AggregationBuilder();
    }

    /**
     * Proxies property retrieval to the target collction. This is handy for getting this
     * association's associations
     *
     * @param string $property the property name
     * @return self
     * @throws \RuntimeException if no association with such a name exists
     */
    public function __get(string $property): self
    {
        return $this->getTarget()->{$property};
    }

    /**
     * Proxies the isset call to the target collection. This is handy to check if the
     * target table has another association with the passed name
     *
     * @param string $property the property name
     * @return bool true if the association exists
     */
    public function __isset(string $property): bool
    {
        return $this->getTarget()->hasAssociation($property);
    }

    /**
     * Proxies method calls to the target collection.
     *
     * @param string $method The method name.
     * @param array<int, mixed> $arguments The arguments.
     * @return mixed
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->getTarget()->{$method}(...$arguments);
    }

    /**
     * Gets the target collection class name.
     *
     * @return string
     */
    public function getClassName(): string
    {
        return $this->className;
    }

    /**
     * Sets the target collection class name.
     *
     * @param string $className The class name.
     * @return $this
     * @throws \InvalidArgumentException In case the class name is set after the target collection has been
     *  resolved, and it doesn't match the target collection's class name.
     */
    public function setClassName(string $className): static
    {
        if (
            $this->target instanceof BaseCollection &&
            get_class($this->target) !== App::className($className, 'Model/Collection', 'Collection')
        ) {
            throw new InvalidArgumentException(sprintf(
                "The class name `%s` doesn't match the target table class name of `%s`.",
                $className,
                $this->target::class,
            ));
        }

        $this->className = $className;

        return $this;
    }

    /**
     * Gets whether cascade callbacks are fired for dependent operations.
     *
     * @return bool
     */
    public function getCascadeCallbacks(): bool
    {
        return $this->cascadeCallbacks;
    }

    /**
     * Sets whether cascade callbacks are fired for dependent operations.
     *
     * @param bool $cascadeCallbacks Whether to fire callbacks.
     * @return $this
     */
    public function setCascadeCallbacks(bool $cascadeCallbacks): static
    {
        $this->cascadeCallbacks = $cascadeCallbacks;

        return $this;
    }

    /**
     * Gets the finder used when loading the target.
     *
     * @return array<string, mixed>|string
     */
    public function getFinder(): array|string
    {
        return $this->finder;
    }

    /**
     * Sets the finder used when loading the target.
     *
     * @param array<string, mixed>|string $finder The finder name or `[name, options]`.
     * @return $this
     */
    public function setFinder(array|string $finder): static
    {
        $this->finder = $finder;

        return $this;
    }

    /**
     * Helper method to infer the requested finder and its options.
     *
     * Returns the inferred options from the finder $type.
     *
     * ### Examples:
     *
     * The following will call the finder 'translations' with the value of the finder as its options:
     * $query->contain(['Comments' => ['finder' => ['translations']]]);
     * $query->contain(['Comments' => ['finder' => ['translations' => []]]]);
     * $query->contain(['Comments' => ['finder' => ['translations' => ['locales' => ['en_US']]]]]);
     *
     * @param array<int|string, mixed>|string $finderData The finder name or an array having the name as key
     * and options as value.
     * @return array{0: string, 1: mixed}
     */
    protected function extractFinder(array|string $finderData): array
    {
        $finderData = (array)$finderData;

        if (is_numeric(key($finderData))) {
            return [current($finderData), []];
        }

        return [key($finderData), current($finderData)];
    }

    /**
     * Sort order applied when loading target documents.
     *
     * @var \Closure|array<string, mixed>|string|null
     */
    protected Closure|array|string|null $sort = null;

    /**
     * Sets the sort order in which target documents should be returned.
     *
     * @param \Closure|array<string, mixed>|string $sort A find() compatible order clause.
     * @return $this
     */
    public function setSort(Closure|array|string $sort): static
    {
        $this->sort = $sort;

        return $this;
    }

    /**
     * Gets the sort order in which target documents should be returned.
     *
     * @return \Closure|array<string, mixed>|string|null
     */
    public function getSort(): Closure|array|string|null
    {
        return $this->sort;
    }

    /**
     * Whether eager loading requires the owning collection's binding keys.
     *
     * @param array<string, mixed> $options Options containing the strategy.
     * @return bool
     */
    public function requiresKeys(array $options = []): bool
    {
        $strategy = $options['strategy'] ?? $this->getStrategy();

        return $strategy === self::STRATEGY_SELECT;
    }

    /**
     * Sets the default association property value on a result row.
     *
     * @param array<string, mixed> $row The result row.
     * @param bool $joined Whether the row came from a join.
     * @return array<string, mixed>
     */
    public function defaultRowValue(array $row, bool $joined): array
    {
        $row[$this->getProperty()] = $this->defaultValue();

        return $row;
    }

    /**
     * Gets the default association property value when no rows are loaded.
     *
     * @return mixed
     */
    protected function defaultValue(): mixed
    {
        return null;
    }

    /**
     * Proxies a find to the target collection with the association conditions.
     *
     * @param array<string, mixed>|string|null $type The finder name.
     * @param mixed ...$args Finder arguments.
     * @return \Cake\Datasource\QueryInterface
     */
    public function find(array|string|null $type = null, mixed ...$args): QueryInterface
    {
        $type = $type ?: $this->getFinder();
        [$type, $opts] = $this->extractFinder($type);
        $args += $opts;

        return $this->getTarget()
            ->find($type, ...$args)
            ->where($this->getConditions());
    }

    /**
     * Proxies an existence check to the target collection.
     *
     * @param \Closure|array<string, mixed>|string|null $conditions Filter conditions.
     * @return bool
     */
    public function exists(array|Closure|string|null $conditions): bool
    {
        return $this->getTarget()->exists($conditions);
    }

    /**
     * Proxies an update to the target collection.
     *
     * @param \Closure|array<string, mixed>|string $fields Update specification.
     * @param \Closure|array<string, mixed>|string|null $conditions Filter conditions.
     * @return int
     */
    public function updateAll(array|Closure|string $fields, array|Closure|string|null $conditions): int
    {
        return $this->getTarget()->updateAll($fields, $conditions);
    }

    /**
     * Proxies a delete to the target collection.
     *
     * @param \Closure|array<string, mixed>|string|null $conditions Filter conditions.
     * @return int
     */
    public function deleteAll(array|Closure|string|null $conditions): int
    {
        return $this->getTarget()->deleteAll($conditions);
    }

    /**
     * Triggers `Collection.beforeFind` on the target collection for a query.
     *
     * @param \Crustum\Mongo\ODM\Query\SelectQuery $query The query being prepared.
     * @return void
     */
    protected function dispatchBeforeFind(SelectQuery $query): void
    {
    }

    /**
     * Gets a repository alias.
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $repository Repository instance.
     * @return string
     */
    protected function repositoryAlias(BaseCollection $repository): string
    {
        return $repository->getAlias();
    }

    /**
     * Gets the relationship type.
     *
     * @return string
     */
    abstract public function type(): string;

    /**
     * Builds the result eager-loader callable.
     *
     * @param array<string, mixed> $options Loader options.
     * @return \Closure
     */
    abstract public function eagerLoader(array $options): Closure;

    /**
     * Builds MongoDB aggregation stages for this association.
     *
     * @param array<string, mixed> $options Pipeline options.
     * @return array<int, array<string, mixed>>
     */
    abstract public function buildPipeline(array $options = []): array;
}

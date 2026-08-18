<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM;

use Cake\Collection\CollectionInterface;
use Cake\Core\App;
use Cake\Core\ConventionsTrait;
use Cake\Database\Exception\DatabaseException;
use Cake\Database\Expression\OrderClauseExpression;
use Cake\Database\ExpressionInterface;
use Cake\Database\ValueBinder;
use Cake\Datasource\EntityInterface;
use Cake\Datasource\QueryInterface;
use Cake\Datasource\ResultSetInterface;
use Cake\Utility\Inflector;
use Closure;
use Crustum\Mongo\Database\Aggregation\AggregationBuilder;
use Crustum\Mongo\ODM\Locator\LocatorAwareTrait;
use Crustum\Mongo\ODM\Query\SelectQuery;
use InvalidArgumentException;
use MongoDB\BSON\ObjectId;
use MongoDB\Driver\Exception\InvalidArgumentException as InvalidArgumentExceptionDriver;
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
     * cake60 join strategy compatibility constant.
     *
     * The ODM has no SQL joins; this maps to the aggregation lookup strategy.
     */
    public const string STRATEGY_JOIN = 'join';

    /**
     * cake60 subquery strategy compatibility constant.
     *
     * The ODM has no subqueries; this maps to the separate-query strategy.
     */
    public const string STRATEGY_SUBQUERY = 'subquery';

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
     * @var string
     */
    protected string $propertyName;

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
     * SQL-style join type (kept for cake API compatibility; Mongo has no joins).
     *
     * @var string
     */
    protected string $joinType = 'LEFT';

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
        $this->className = $options['className'] ?? $alias;
        if (isset($options['propertyName'])) {
            $this->propertyName = (string)$options['propertyName'];
        }

        $this->foreignKey = $options['foreignKey'] ?? null;
        $this->bindingKey = $options['bindingKey'] ?? null;
        $this->conditions = $options['conditions'] ?? [];
        if (isset($options['joinType'])) {
            $this->joinType = (string)$options['joinType'];
        }

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

        $this->source = $source;

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
        if (!isset($this->propertyName)) {
            $this->setProperty($this->propertyName());
        }

        if (
            in_array($this->propertyName, $this->getSource()->getSchema()->columns(), true)
        ) {
            triggerWarning(sprintf(
                'Association property name `%s` clashes with field of same name of collection `%s`.',
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
     * collection query layer can provide tuple handling when required. A
     * `false`/`null` key (disabled foreign key) yields an empty string.
     *
     * @param array<string>|string|false|null $key Key configuration.
     * @return string
     */
    protected function fieldName(array|string|false|null $key): string
    {
        if ($key === false || $key === null) {
            return '';
        }

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
     * @param \Cake\Datasource\EntityInterface $document The source document.
     * @param array<string, mixed> $options Delete options.
     * @return bool Whether the dependent operation succeeded.
     */
    public function cascadeDelete(EntityInterface $document, array $options = []): bool
    {
        if (!$this->dependent && $this->onDelete !== 'nullify') {
            return true;
        }

        $foreignKeys = array_values(array_filter(
            (array)$this->getForeignKey(),
            is_string(...),
        ));
        $keys = array_combine($foreignKeys, $document->extract((array)$this->getBindingKey()));
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
     * @param \Cake\Datasource\EntityInterface $document The source document.
     * @param array<string, mixed> $options Save options.
     * @return \Cake\Datasource\EntityInterface|false
     */
    public function saveAssociated(EntityInterface $document, array $options = []): EntityInterface|false
    {
        return $document;
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
     * Whether the association can be expressed as an in-pipeline `$lookup`.
     *
     * Cake ORM uses `strategy === join`. The ODM analog is `lookup`.
     *
     * @param array<string, mixed> $options Containment options that may override strategy.
     * @return bool
     */
    public function canBeJoined(array $options = []): bool
    {
        $strategy = $options['strategy'] ?? $this->getStrategy();

        return $strategy === self::STRATEGY_LOOKUP;
    }

    /**
     * Builds the surrogate target query used before lookup attachment.
     *
     * @param array<string, mixed> $options Attachment options.
     * @return \Crustum\Mongo\ODM\Query\SelectQuery
     */
    public function buildAttachSurrogateQuery(array $options): SelectQuery
    {
        $options += [
            'foreignKey' => $this->getForeignKey(),
            'conditions' => [],
            'finder' => $this->getFinder(),
        ];

        [$finder, $finderOptions] = $this->extractFinder($options['finder']);
        $dummy = $this->find($finder, ...$finderOptions);
        if (!$dummy instanceof SelectQuery) {
            throw new DatabaseException(sprintf(
                'Association `%s` target finder did not return a select query.',
                $this->getName(),
            ));
        }

        $dummy->eagerLoaded(true);

        if (!empty($options['queryBuilder']) && is_callable($options['queryBuilder'])) {
            $built = $options['queryBuilder']($dummy);
            if (!$built instanceof SelectQuery) {
                throw new DatabaseException(sprintf(
                    'Query builder for association `%s` did not return a query.',
                    $this->getName(),
                ));
            }

            $dummy = $built;
        }

        $conditions = $options['conditions'];
        if (is_array($conditions) && $conditions !== []) {
            $dummy->where($conditions);
        }

        $associationConditions = $this->getConditions();
        if (is_array($associationConditions) && $associationConditions !== []) {
            $dummy->where($associationConditions);
        }

        $this->dispatchBeforeFind($dummy);

        return $dummy;
    }

    /**
     * Merges a surrogate target query back into lookup containment options.
     *
     * @param \Crustum\Mongo\ODM\Query\SelectQuery $surrogate The surrogate query.
     * @param array<string, mixed> $options Attachment options.
     * @return array<string, mixed>
     */
    public function mergeSurrogateIntoConfig(SelectQuery $surrogate, array $options): array
    {
        $compiled = $surrogate->compile();
        /** @var array<string, mixed> $filter */
        $filter = $compiled['filter'] ?? [];
        if ($filter !== []) {
            $options['conditions'] = array_merge(
                is_array($options['conditions'] ?? null) ? $options['conditions'] : [],
                $filter,
            );
        }

        $projection = $surrogate->clause('select');
        if ($projection !== [] && empty($options['fields'])) {
            $options['fields'] = array_keys($projection);
        }

        $sort = $surrogate->clause('order') ?? [];
        if ($sort !== [] && !isset($options['sort'])) {
            $options['sort'] = $sort;
        }

        $pipeline = $surrogate->clause('pipeline');
        if (is_array($pipeline) && $pipeline !== []) {
            $options['targetPipeline'] = $pipeline;
        }

        return $options;
    }

    /**
     * Applies surrogate formatters to the nested association property on a query.
     *
     * @param \Crustum\Mongo\ODM\Query\SelectQuery $query The source query.
     * @param \Crustum\Mongo\ODM\Query\SelectQuery $surrogate The target surrogate query.
     * @param array<string, mixed> $options Options including `propertyPath`.
     * @return void
     * @see cake60/src/ORM/Association.php (formatAssociationResults)
     */
    public function formatAssociationResults(SelectQuery $query, SelectQuery $surrogate, array $options): void
    {
        $formatters = $surrogate->getResultFormatters();

        if (!$formatters || empty($options['propertyPath'])) {
            return;
        }

        $property = $options['propertyPath'];
        $propertyPath = explode('.', (string)$property);
        $query->formatResults(
            function (CollectionInterface $results, SelectQuery $query) use ($formatters, $property, $propertyPath): CollectionInterface {
                $extracted = [];
                foreach ($results as $result) {
                    foreach ($propertyPath as $propertyPathItem) {
                        if (!isset($result[$propertyPathItem])) {
                            $result = null;
                            break;
                        }

                        $result = $result[$propertyPathItem];
                    }

                    $extracted[] = $result;
                }

                $extracted = $query->resultSetFactory()->createResultSet($extracted);
                $resultSetClass = $query->resultSetFactory()->getResultSetClass();
                foreach ($formatters as $callable) {
                    $extracted = $callable($extracted, $query);
                    if (!$extracted instanceof ResultSetInterface) {
                        $extracted = new $resultSetClass($extracted);
                    }
                }

                $results = $results->insert($property, $extracted);
                if ($query->isHydrationEnabled()) {
                    return $results->map(function (EntityInterface $result): EntityInterface {
                        $result->clean();

                        return $result;
                    });
                }

                return $results;
            },
            SelectQuery::PREPEND,
        );
    }

    /**
     * Copies nested containments from a surrogate query onto the source query.
     *
     * @param \Crustum\Mongo\ODM\Query\SelectQuery $query The source query.
     * @param \Crustum\Mongo\ODM\Query\SelectQuery $surrogate The target surrogate query.
     * @param array<string, mixed> $options Options including `aliasPath`.
     * @return void
     * @see cake60/src/ORM/Association.php (bindNewAssociations)
     */
    public function bindNewAssociations(SelectQuery $query, SelectQuery $surrogate, array $options): void
    {
        $loader = $surrogate->getEagerLoader();
        $contain = $loader->getContain();

        if ($contain === []) {
            return;
        }

        $aliasPath = $options['aliasPath'] ?? $this->getName();
        $newContain = [];
        foreach ($contain as $alias => $value) {
            $newContain[$aliasPath . '.' . $alias] = $value;
        }

        $query->getEagerLoader()->contain($newContain);
    }

    /**
     * Attaches the association to a query as an in-pipeline lookup load.
     *
     * The ODM analog of cake60 `Association::attachTo()`: where cake builds a
     * SQL join, this registers the association with the query's eager loader
     * under the `lookup` strategy, so `EagerLoader::dispatch()` appends the
     * association's `$lookup` pipeline stages and `ResultSet` deconstructs the
     * loaded documents into the association property. `contain`/`matching`/
     * `joinWith` flows share this single path.
     *
     * @param \Crustum\Mongo\ODM\Query\SelectQuery $query The query to attach to.
     * @param array<string, mixed> $options Attachment options.
     * @return void
     * @see cake60/src/ORM/Association.php (attachTo)
     */
    public function attachTo(SelectQuery $query, array $options = []): void
    {
        $options += [
            'includeFields' => true,
            'foreignKey' => $this->getForeignKey(),
            'conditions' => [],
            'joinType' => $this->getJoinType(),
            'fields' => [],
            'finder' => $this->getFinder(),
        ];

        if ($options['fields'] === false) {
            $options['fields'] = [];
            $options['includeFields'] = false;
        }

        if ($options['includeFields'] === false) {
            $options['fields'] = [];
        }

        $dummy = $this->buildAttachSurrogateQuery($options);

        if (
            !empty($options['matching'])
            && $dummy->getEagerLoader()->getContain() !== []
        ) {
            throw new DatabaseException(sprintf(
                '`%s` association cannot contain() associations when using JOIN strategy.',
                $this->getName(),
            ));
        }

        $options = $this->mergeSurrogateIntoConfig($dummy, $options);

        unset($options['queryBuilder'], $options['finder']);

        $options['strategy'] = static::STRATEGY_LOOKUP;

        $this->formatAssociationResults($query, $dummy, [
            'propertyPath' => $this->getProperty(),
        ]);
        $this->bindNewAssociations($query, $dummy, [
            'aliasPath' => $this->getName(),
        ]);

        unset($options['includeFields']);
        $options['association'] = $this;
        $query->getEagerLoader()->contain([$this->getName() => $options]);
    }

    /**
     * Normalizes a sort specification into a Mongo `$sort` object.
     *
     * Accepts `'field DESC'`, `['field' => 'DESC']`, `['field' => -1]`, and
     * `OrderClauseExpression` instances, mirroring the query-layer orderBy()
     * parsing.
     *
     * @param \Cake\Database\ExpressionInterface|\Closure|array<string, mixed>|string $sort The sort specification.
     * @return array<string, int>
     */
    protected function normalizeSort(ExpressionInterface|Closure|array|string $sort): array
    {
        if ($sort instanceof Closure) {
            $sort = $sort($this);
        }

        if ($sort instanceof OrderClauseExpression) {
            $field = $sort->getField();
            $sort = is_string($field) ? [$field => $this->orderDirection($sort)] : [];
        }

        if (is_string($sort)) {
            $parts = explode(' ', trim($sort), 2);
            if (count($parts) === 2) {
                $sort = [$parts[0] => strtolower($parts[1]) === 'desc' ? -1 : 1];
            } else {
                $sort = [$sort => 1];
            }
        }

        $normalized = [];
        foreach ($sort as $field => $direction) {
            if (is_int($field)) {
                $field = $direction;
                $direction = 1;
            } elseif (is_string($direction)) {
                $direction = strtolower($direction) === 'desc' ? -1 : 1;
            }

            $normalized[(string)$field] = (int)$direction;
        }

        return $normalized;
    }

    /**
     * Reads the direction of an order clause expression.
     *
     * @param \Cake\Database\Expression\OrderClauseExpression $expression The order clause.
     * @return string
     */
    protected function orderDirection(OrderClauseExpression $expression): string
    {
        return str_ends_with($expression->sql(new ValueBinder()), ' DESC') ? 'DESC' : 'ASC';
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
     * Applies per-association containment options as pipeline stages.
     *
     * `conditions` become a `$match`, `fields` a `$project`, `sort` a `$sort`,
     * and `limit`/`skip` their stages. This keeps eagerly loaded documents
     * slim (no full-document bloat) and filters embedded associations, mirroring
     * the `fields`/`conditions`/`sort`/`limit` containment options.
     *
     * @param \Crustum\Mongo\Database\Aggregation\AggregationBuilder $builder The pipeline builder.
     * @param array<string, mixed> $options Containment options.
     * @return void
     */
    protected function applyPipelineOptions(AggregationBuilder $builder, array $options): void
    {
        if (!empty($options['conditions'])) {
            $builder->match($this->normalizePipelineConditions($options['conditions'], (bool)($options['matching'] ?? false)));
        }

        if (!empty($options['fields'])) {
            $fields = (array)$options['fields'];
            if (array_is_list($fields)) {
                $fields = array_fill_keys($fields, 1);
            }

            $normalizedFields = [];
            foreach ($fields as $field => $value) {
                $normalizedFields[$this->resolvePipelineField((string)$field)] = $value;
            }

            $builder->project($normalizedFields);
        }

        if (!empty($options['sort'])) {
            $sort = $this->normalizeSort($options['sort']);
            $normalizedSort = [];
            foreach ($sort as $field => $direction) {
                $normalizedSort[$this->resolvePipelineField((string)$field)] = $direction;
            }

            $builder->sort($normalizedSort);
        }

        if (!empty($options['skip'])) {
            $builder->skip((int)$options['skip']);
        }

        if (!empty($options['limit'])) {
            $builder->limit((int)$options['limit']);
        }
    }

    /**
     * Applies containment options as stages inside a `$lookup` sub-pipeline.
     *
     * Conditions, sort, projection and pagination target the joined collection
     * directly — mirroring cake's join target-query options. When `fields` is set
     * and `_id` is omitted, projection excludes `_id` to avoid bloating nested
     * documents.
     *
     * @param \Crustum\Mongo\Database\Aggregation\AggregationBuilder $builder The sub-pipeline builder.
     * @param array<string, mixed> $options Containment options.
     * @param bool $limitToOne Whether to cap the result to one document (has-one).
     * @return void
     */
    protected function applyLookupSubPipeline(AggregationBuilder $builder, array $options, bool $limitToOne = false): void
    {
        if (!empty($options['conditions'])) {
            $builder->match($this->normalizePipelineConditions($options['conditions']));
        }

        if (!empty($options['sort'])) {
            $sort = $this->normalizeSort($options['sort']);
            $normalizedSort = [];
            foreach ($sort as $field => $direction) {
                $normalizedSort[$this->resolvePipelineField((string)$field)] = $direction;
            }

            $builder->sort($normalizedSort);
        }

        if (!empty($options['fields'])) {
            $fields = (array)$options['fields'];
            if (array_is_list($fields)) {
                $fields = array_fill_keys($fields, 1);
            }

            $project = [];
            foreach ($fields as $field => $value) {
                $project[$this->resolvePipelineField((string)$field)] = $value;
            }

            if (!array_key_exists('_id', $project)) {
                if ($limitToOne) {
                    $bindingField = $this->resolvePipelineField($this->fieldName($this->getBindingKey()));
                    if (!array_key_exists($bindingField, $project)) {
                        $project[$bindingField] = 1;
                    }
                } else {
                    $project['_id'] = 0;
                }
            }

            $builder->project($project);
        }

        if (!empty($options['skip'])) {
            $builder->skip((int)$options['skip']);
        }

        if (!empty($options['limit'])) {
            $builder->limit((int)$options['limit']);
        } elseif ($limitToOne) {
            $builder->limit(1);
        }
    }

    /**
     * Merges association conditions into pipeline containment options.
     *
     * @param array<string, mixed> $options Containment options.
     * @return array<string, mixed>
     */
    protected function mergePipelineConditions(array $options): array
    {
        $associationConditions = $this->getConditions();
        if (!is_array($associationConditions) || $associationConditions === []) {
            return $options;
        }

        $callConditions = $options['conditions'] ?? [];
        if (is_array($callConditions)) {
            $options['conditions'] = array_merge($associationConditions, $callConditions);
        } elseif (!isset($options['conditions'])) {
            $options['conditions'] = $associationConditions;
        }

        return $options;
    }

    /**
     * Applies a null-safe join match and containment stages inside `$lookup`.
     *
     * @param \Crustum\Mongo\Database\Aggregation\AggregationBuilder $builder The sub-pipeline builder.
     * @param string $foreignField The target join field.
     * @param array<string, mixed> $options Containment options.
     * @return void
     */
    protected function applyJoinLookupSubPipeline(
        AggregationBuilder $builder,
        string $foreignField,
        array $options,
    ): void {
        $func = $builder->func();
        $builder->match([
            '$expr' => $func->and([
                $func->ne('$$bindingValue', null),
                $func->eq('$' . $foreignField, '$$bindingValue'),
            ])->getConditions(),
        ]);

        $this->applyLookupSubPipeline($builder, $options, true);
    }

    /**
     * Strips the association alias prefix from pipeline condition fields.
     *
     * Match conditions inside a lookup pipeline address the target collection
     * directly, so `Alias.field` keys must lose their alias (`articles._id`
     * becomes `_id`). Logical groups (`OR`, `AND`, `$or`, ...) are normalized
     * recursively.
     *
     * @param array<string, mixed> $conditions The raw conditions.
     * @return array<string, mixed>
     */
    protected function normalizePipelineConditions(array $conditions, bool $preservePrefix = false): array
    {
        $alias = $this->getAlias() . '.';
        $normalized = [];
        foreach ($conditions as $field => $value) {
            $field = (string)$field;
            if (!$preservePrefix && str_starts_with($field, $alias)) {
                $field = substr($field, strlen($alias));
            }

            if (is_array($value) && in_array(strtoupper($field), ['OR', 'AND', 'NOT', '$OR', '$AND', '$NOT'], true)) {
                $value = array_map(fn(array $group): array => $this->normalizePipelineConditions($group, $preservePrefix), $value);
            } elseif (is_array($value) && array_is_list($value)) {
                $value = array_map(
                    fn(mixed $item): mixed => is_array($item) ? $this->normalizePipelineConditions($item, $preservePrefix) : $item,
                    $value,
                );
            } else {
                $value = $this->castPipelineValue($value);
            }

            $normalized[$field] = $value;
        }

        return $normalized;
    }

    /**
     * Resolves a pipeline field name against the target collection.
     *
     * @param string $field The raw field name.
     * @return string The resolved Mongo field name.
     */
    protected function resolvePipelineField(string $field): string
    {
        $alias = $this->getAlias() . '.';
        if (str_starts_with($field, $alias)) {
            $field = substr($field, strlen($alias));
        }

        return $field === 'id' ? '_id' : $field;
    }

    /**
     * Casts a pipeline match value to its database representation.
     *
     * @param mixed $value The raw value.
     * @return mixed
     */
    protected function castPipelineValue(mixed $value): mixed
    {
        if (!is_string($value) || preg_match('/^[0-9a-f]{24}$/i', $value) !== 1) {
            return $value;
        }

        try {
            return new ObjectId($value);
        } catch (InvalidArgumentExceptionDriver) {
            return $value;
        }
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
     * target collection has another association with the passed name
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
            $this->target::class !== App::className($className, 'Model/Collection', 'Collection')
        ) {
            throw new InvalidArgumentException(sprintf(
                "The class name `%s` doesn't match the target collection class name of `%s`.",
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
     * Sets the SQL-style join type for this association.
     *
     * @param string $type Join type (INNER/LEFT).
     * @return $this
     */
    public function setJoinType(string $type): static
    {
        $this->joinType = $type;

        return $this;
    }

    /**
     * Gets the SQL-style join type for this association.
     *
     * @return string
     */
    public function getJoinType(): string
    {
        return $this->joinType;
    }

    /**
     * Whether `$unwind` should keep source rows with no associated document.
     *
     * Cake INNER join drops unmatched source rows. `matching()` already does
     * that unless `negateMatch` is set. LEFT (the default) keeps them.
     *
     * @param array<string, mixed> $options Pipeline / contain options.
     * @return bool
     */
    protected function unwindPreservesNull(array $options): bool
    {
        if (!empty($options['matching'])) {
            return !empty($options['negateMatch']);
        }

        return strtoupper((string)($options['joinType'] ?? $this->getJoinType())) !== 'INNER';
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
            $finder = current($finderData);
            assert(is_string($finder));

            return [$finder, []];
        }

        $finder = key($finderData);
        assert(is_string($finder));

        return [$finder, current($finderData)];
    }

    /**
     * Sort order applied when loading target documents.
     *
     * @var \Cake\Database\ExpressionInterface|\Closure|array<string, mixed>|string|null
     */
    protected ExpressionInterface|Closure|array|string|null $sort = null;

    /**
     * Sets the sort order in which target documents should be returned.
     *
     * @param \Cake\Database\ExpressionInterface|\Closure|array<string, mixed>|string $sort A find() compatible order clause.
     * @return $this
     */
    public function setSort(ExpressionInterface|Closure|array|string $sort): static
    {
        $this->sort = $sort;

        return $this;
    }

    /**
     * Gets the sort order in which target documents should be returned.
     *
     * @return \Cake\Database\ExpressionInterface|\Closure|array<string, mixed>|string|null
     */
    public function getSort(): ExpressionInterface|Closure|array|string|null
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
     * Whether the association can load through an in-pipeline `$lookup`.
     *
     * `foreignKey => false` disables automatic join keys (cake parity) and must
     * fall back to the external select loader instead.
     *
     * @param array<string, mixed> $options Containment options.
     * @return bool
     */
    public function usesLookup(array $options = []): bool
    {
        if (!empty($options['sourcePath'])) {
            return false;
        }

        $strategy = $options['strategy'] ?? $this->getStrategy();
        if ($strategy !== self::STRATEGY_LOOKUP) {
            return false;
        }

        $foreignKey = $options['foreignKey'] ?? $this->getForeignKey();

        return $foreignKey !== false;
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
        $query = $this->find();
        if ($conditions !== null) {
            $query->where($conditions);
        }

        return $query->limit(1)->first() !== null;
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
        $query = $this->find();
        if ($conditions !== null) {
            $query->where($conditions);
        }

        $filter = [];
        if ($query instanceof SelectQuery) {
            $filter = $query->compile()['filter'] ?? [];
        }

        return $this->getTarget()->updateAll($fields, $filter);
    }

    /**
     * Proxies a delete to the target collection, applying the association
     * finder and conditions to the filter.
     *
     * @param \Closure|array<string, mixed>|string|null $conditions Filter conditions.
     * @return int
     */
    public function deleteAll(array|Closure|string|null $conditions): int
    {
        $query = $this->find();
        if ($conditions !== null) {
            $query->where($conditions);
        }

        $filter = [];
        if ($query instanceof SelectQuery) {
            $filter = $query->compile()['filter'] ?? [];
        }

        return $this->getTarget()->deleteAll($filter);
    }

    /**
     * Triggers `Collection.beforeFind` on the target collection for a query.
     *
     * @param \Crustum\Mongo\ODM\Query\SelectQuery $query The query being prepared.
     * @return void
     */
    protected function dispatchBeforeFind(SelectQuery $query): void
    {
        $query->triggerBeforeFind();
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
     * Prefixes matching conditions with the loaded property path.
     *
     * After `$unwind` the matched row lives under the association property, so
     * bare field conditions must point at that path.
     *
     * @param array<int|string, mixed> $conditions The conditions.
     * @param string $property The association property.
     * @return array<int|string, mixed>
     */
    protected function prefixMatchConditions(array $conditions, string $property): array
    {
        $prefixed = [];
        foreach ($conditions as $field => $value) {
            if (in_array(strtoupper((string)$field), ['$OR', '$AND', 'OR', 'AND'], true) && is_array($value)) {
                $prefixed[$field] = array_map(
                    fn(mixed $item): mixed => is_array($item) ? $this->prefixMatchConditions($item, $property) : $item,
                    $value,
                );
                continue;
            }

            $prefixed[$property . '.' . $field] = $value;
        }

        return $prefixed;
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
     * @return list<array<string, mixed>>
     */
    abstract public function buildPipeline(array $options = []): array;
}

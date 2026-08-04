<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Association;

use Cake\Core\ConventionsTrait;
use Cake\Datasource\EntityInterface;
use Cake\Datasource\RepositoryInterface;
use Cake\Utility\Inflector;
use Crustum\Mongo\Database\Aggregation\AggregationBuilder;
use Crustum\Mongo\ODM\Document;
use InvalidArgumentException;
use function Cake\Core\pluginSplit;

/**
 * An association describes a relationship between ODM collections.
 *
 * Associations configure the source and target collections, key mapping,
 * loading strategy, property name, and dependent-delete behavior. Concrete
 * associations provide the relationship type, eager loader, and MongoDB
 * aggregation pipeline.
 *
 * @see cake60/src/ORM/Association.php
 * @see src/ODM/Association/Association.php
 */
abstract class Association
{
    use ConventionsTrait;

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
     * @var array<string>|string|null
     */
    protected string|array|null $foreignKey = null;

    /**
     * Binding key fields on the source collection.
     *
     * @var array<string>|string
     */
    protected string|array $bindingKey = '_id';

    /**
     * Conditions always applied while loading the target.
     *
     * @var array<string, mixed>
     */
    protected array $conditions = [];

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
     * @var \Cake\Datasource\RepositoryInterface|null
     */
    protected ?RepositoryInterface $source = null;

    /**
     * Target collection.
     *
     * @var \Cake\Datasource\RepositoryInterface|null
     */
    protected ?RepositoryInterface $target = null;

    /**
     * Entity class used for hydrated associated documents.
     *
     * @var string|null
     */
    protected ?string $entityClass = null;

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
     * Constructor.
     *
     * @param string $alias Association alias.
     * @param array<string, mixed> $options Association configuration.
     */
    public function __construct(string $alias, array $options = [])
    {
        [, $this->name] = pluginSplit($alias);
        $this->className = $options['className'] ?? $this->name;
        $this->propertyName = $options['propertyName'] ?? null;
        $this->foreignKey = $options['foreignKey'] ?? null;
        $this->bindingKey = $options['bindingKey'] ?? '_id';
        $this->conditions = $options['conditions'] ?? [];
        $this->dependent = (bool)($options['dependent'] ?? false);
        $this->onDelete = (string)($options['onDelete'] ?? ($this->dependent ? 'cascade' : 'nullify'));
        if (isset($options['strategy'])) {
            $this->setStrategy((string)$options['strategy']);
        }

        if (isset($options['source'])) {
            $this->setSource($options['source']);
        }

        if (isset($options['target'])) {
            $this->setTarget($options['target']);
        }

        if (isset($options['entityClass'])) {
            $class = (string)$options['entityClass'];
            if (!is_a($class, Document::class, true)) {
                throw new InvalidArgumentException('The entity class must extend Document.');
            }

            $this->entityClass = $class;
        }
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
        return $this->propertyName ??= Inflector::underscore($this->name);
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
     * Gets the target foreign key.
     *
     * @return array<string>|string|null
     */
    public function getForeignKey(): string|array|null
    {
        return $this->foreignKey;
    }

    /**
     * Sets the target foreign key.
     *
     * @param array<string>|string|null $key Foreign key fields.
     * @return $this
     */
    public function setForeignKey(string|array|null $key): static
    {
        $this->foreignKey = $key;

        return $this;
    }

    /**
     * Gets the source binding key.
     *
     * @return array<string>|string
     */
    public function getBindingKey(): string|array
    {
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
     * @return array<string, mixed>
     */
    public function getConditions(): array
    {
        return $this->conditions;
    }

    /**
     * Sets conditions applied to target queries.
     *
     * @param array<string, mixed> $conditions Target conditions.
     * @return $this
     */
    public function setConditions(array $conditions): static
    {
        $this->conditions = $conditions;

        return $this;
    }

    /**
     * Gets the source collection.
     *
     * @return \Cake\Datasource\RepositoryInterface
     * @throws \InvalidArgumentException If the source is not configured.
     */
    public function getSource(): RepositoryInterface
    {
        return $this->source ?? throw new InvalidArgumentException('Association source is not set.');
    }

    /**
     * Sets the source collection.
     *
     * @param \Cake\Datasource\RepositoryInterface $source Source collection.
     * @return $this
     */
    public function setSource(RepositoryInterface $source): static
    {
        $this->source = $source;

        return $this;
    }

    /**
     * Gets the target collection.
     *
     * @return \Cake\Datasource\RepositoryInterface
     * @throws \InvalidArgumentException If the target is not configured.
     */
    public function getTarget(): RepositoryInterface
    {
        return $this->target ?? throw new InvalidArgumentException('Association target is not set.');
    }

    /**
     * Sets the target collection.
     *
     * @param \Cake\Datasource\RepositoryInterface $target Target collection.
     * @return $this
     */
    public function setTarget(RepositoryInterface $target): static
    {
        $this->target = $target;

        return $this;
    }

    /**
     * Gets the associated entity class.
     *
     * @return string
     */
    public function getEntityClass(): string
    {
        return $this->entityClass ??= Document::class;
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
     * Sets the associated entity class.
     *
     * @param class-string<\Crustum\Mongo\ODM\Document> $class Entity class.
     * @return $this
     */
    public function setEntityClass(string $class): static
    {
        $this->entityClass = $class;

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
     * Creates an aggregation builder for this association.
     *
     * @return \Crustum\Mongo\Database\Aggregation\AggregationBuilder
     */
    protected function buildAggregation(): AggregationBuilder
    {
        return new AggregationBuilder();
    }

    /**
     * Gets a repository alias without coupling the association to Collection.
     *
     * @param \Cake\Datasource\RepositoryInterface $repository Repository instance.
     * @return string
     */
    protected function repositoryAlias(RepositoryInterface $repository): string
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
     * @return callable
     */
    abstract public function eagerLoad(array $options): callable;

    /**
     * Builds MongoDB aggregation stages for this association.
     *
     * @param array<string, mixed> $options Pipeline options.
     * @return array<int, array<string, mixed>>
     */
    abstract public function buildPipeline(array $options = []): array;
}

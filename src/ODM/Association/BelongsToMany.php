<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Association;

use Cake\Datasource\EntityInterface;
use Cake\Datasource\QueryInterface;
use Cake\Utility\Inflector;
use Closure;
use Crustum\Mongo\ODM\Association;
use Crustum\Mongo\ODM\Association\Loader\LookupLoader;
use Crustum\Mongo\ODM\Association\Loader\SelectLoader;
use Crustum\Mongo\ODM\BaseCollection;
use InvalidArgumentException;
use RuntimeException;

/**
 * Represents a many-to-many relationship.
 *
 * When no join (`through`) collection is configured the relationship is
 * stored as an `_ids` array on the source document, which is the idiomatic
 * MongoDB shape. A join collection is supported for lookup pipelines.
 *
 * @see cake60/src/ORM/Association/BelongsToMany.php
 */
class BelongsToMany extends Association
{
    /**
     * Save strategy that appends targets without removing existing links.
     */
    public const string SAVE_APPEND = 'append';

    /**
     * Save strategy that replaces existing links with the provided targets.
     */
    public const string SAVE_REPLACE = 'replace';

    /** Valid loading strategies for this association. */
    /**
     * @var array<string>
     */
    protected array $validStrategies = [self::STRATEGY_SELECT, self::STRATEGY_LOOKUP];

    /**
     * Join collection alias or instance.
     *
     * @var \Crustum\Mongo\ODM\BaseCollection|string|null
     */
    protected BaseCollection|string|null $through = null;

    /**
     * Join collection instance resolved by {@see junction()}.
     *
     * @var \Crustum\Mongo\ODM\BaseCollection|null
     */
    protected ?BaseCollection $junctionCollection = null;

    /**
     * Foreign key from the join collection to the source.
     */
    protected ?string $joinForeignKey = null;

    /**
     * Foreign key from the join collection to the target.
     */
    protected ?string $targetForeignKey = null;

    /**
     * Property name carrying junction data on the source document.
     */
    protected string $junctionProperty = '_joinData';

    /**
     * Save strategy applied when persisting associated target documents.
     */
    protected string $saveStrategy = self::SAVE_REPLACE;

    /**
     * Constructor.
     *
     * @param string $alias Association alias.
     * @param \Crustum\Mongo\ODM\BaseCollection $source Source collection.
     * @param array<string, mixed> $options Association configuration.
     */
    public function __construct(string $alias, BaseCollection $source, array $options = [])
    {
        parent::__construct($alias, $source, $options);
        $this->through = $options['through'] ?? null;
        $this->joinForeignKey = $options['joinForeignKey'] ?? null;
        $this->targetForeignKey = $options['targetForeignKey'] ?? null;
        $this->junctionProperty = (string)($options['junctionProperty'] ?? $this->junctionProperty);
        $this->saveStrategy = (string)($options['saveStrategy'] ?? $this->saveStrategy);
        if (isset($options['sort'])) {
            $this->setSort($options['sort']);
        }
    }

    /**
     * Gets the relationship type.
     *
     * @return string
     */
    public function type(): string
    {
        return self::MANY_TO_MANY;
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
     * The source document owns the `_ids` link array.
     *
     * @return bool
     */
    public function isOwningSide(): bool
    {
        return true;
    }

    /**
     * Appends target identifiers to the source `_ids` array.
     *
     * @param \Cake\Datasource\EntityInterface $sourceEntity The source document.
     * @param array<int, mixed> $targetEntities Target documents to link.
     * @param array<string, mixed> $options Save options.
     * @return bool
     */
    public function link(EntityInterface $sourceEntity, array $targetEntities, array $options = []): bool
    {
        $this->assertNoJoinCollection();
        $property = $this->getProperty();
        $current = (array)$sourceEntity->get($property);
        $sourceEntity->set(
            $property,
            array_values(array_unique(array_merge($current, $this->extractIds($targetEntities)))),
        );
        $saved = $this->getSource()->save($sourceEntity, $options);

        return $saved instanceof EntityInterface;
    }

    /**
     * Removes target identifiers from the source `_ids` array.
     *
     * @param \Cake\Datasource\EntityInterface $sourceEntity The source document.
     * @param array<int, mixed> $targetEntities Target documents to unlink.
     * @param array<string, mixed> $options Save options.
     * @return bool
     */
    public function unlink(EntityInterface $sourceEntity, array $targetEntities, array $options = []): bool
    {
        $this->assertNoJoinCollection();
        $property = $this->getProperty();
        $removed = $this->extractIds($targetEntities);
        $current = array_filter(
            (array)$sourceEntity->get($property),
            static fn(mixed $id): bool => !in_array($id, $removed, true),
        );
        $sourceEntity->set($property, array_values($current));
        $saved = $this->getSource()->save($sourceEntity, $options);

        return $saved instanceof EntityInterface;
    }

    /**
     * Replaces the source `_ids` array with the given target identifiers.
     *
     * @param \Cake\Datasource\EntityInterface $sourceEntity The source document.
     * @param array<int, mixed> $targetEntities Target documents to keep.
     * @param array<string, mixed> $options Save options.
     * @return bool
     */
    public function replace(EntityInterface $sourceEntity, array $targetEntities, array $options = []): bool
    {
        $this->assertNoJoinCollection();
        $sourceEntity->set($this->getProperty(), $this->extractIds($targetEntities));
        $saved = $this->getSource()->save($sourceEntity, $options);

        return $saved instanceof EntityInterface;
    }

    /**
     * Rejects link mutations when a join collection is configured.
     *
     * @return void
     * @throws \RuntimeException When a join collection is configured.
     */
    protected function assertNoJoinCollection(): void
    {
        if ($this->through !== null || $this->junctionCollection !== null) {
            throw new RuntimeException(
                'Linking through a join collection is not supported until the BaseCollection layer lands.',
            );
        }
    }

    /**
     * Extracts target document identifiers.
     *
     * @param array<int, mixed> $entities Target documents.
     * @return array<int, mixed>
     */
    protected function extractIds(array $entities): array
    {
        $ids = [];
        foreach ($entities as $entity) {
            if ($entity instanceof EntityInterface) {
                $id = $entity->get('_id');
                if ($id !== null) {
                    $ids[] = $id;
                }
            }
        }

        return $ids;
    }

    /**
     * Gets the join collection foreign key for the source.
     *
     * @return array<string>|string|null
     */
    public function getForeignKey(): string|array|false|null
    {
        return $this->foreignKey ??= $this->_modelKey($this->repositoryAlias($this->getSource()));
    }

    /**
     * Gets the target entity property name.
     *
     * @return string
     */
    public function getProperty(): string
    {
        return $this->propertyName ??= Inflector::underscore($this->name);
    }

    /**
     * Gets the join collection alias or instance.
     *
     * @return \Crustum\Mongo\ODM\BaseCollection|string|null
     */
    public function getThrough(): BaseCollection|string|null
    {
        return $this->through;
    }

    /**
     * Sets the join collection, either the alias or the instance itself.
     *
     * @param \Crustum\Mongo\ODM\BaseCollection|string $through Join collection alias or instance.
     * @return $this
     */
    public function setThrough(BaseCollection|string $through): static
    {
        $this->through = $through;

        return $this;
    }

    /**
     * Gets the join collection instance.
     *
     * Resolves the configured `through` alias through the collection locator,
     * or generates the conventional `{source}_{target}` junction name when no
     * through is configured.
     *
     * @return \Crustum\Mongo\ODM\BaseCollection
     * @throws \InvalidArgumentException When source and target are the same collection.
     */
    public function junction(): BaseCollection
    {
        if ($this->junctionCollection instanceof BaseCollection) {
            return $this->junctionCollection;
        }

        $through = $this->through;
        if ($through instanceof BaseCollection) {
            return $this->junctionCollection = $through;
        }

        if ($through !== null) {
            $collection = $this->getCollectionLocator()->get($through, ['allowFallbackClass' => true]);
            if (!$collection instanceof BaseCollection) {
                throw new InvalidArgumentException(sprintf(
                    'Junction collection `%s` did not resolve to a BaseCollection.',
                    $through,
                ));
            }

            return $this->junctionCollection = $collection;
        }

        $source = $this->getSource();
        $target = $this->getTarget();
        if ($source->getAlias() === $target->getAlias()) {
            throw new InvalidArgumentException(sprintf(
                'The `%s` association on `%s` cannot target the same table.',
                $this->getName(),
                $source->getAlias(),
            ));
        }

        $names = [$source->getCollection(), $target->getCollection()];
        sort($names);
        $alias = Inflector::camelize(implode('_', $names));
        $collection = $this->getCollectionLocator()->get($alias, [
            'collection' => implode('_', $names),
            'allowFallbackClass' => true,
        ]);
        if (!$collection instanceof BaseCollection) {
            throw new InvalidArgumentException(sprintf(
                'Junction collection `%s` did not resolve to a BaseCollection.',
                $alias,
            ));
        }

        return $this->junctionCollection = $collection;
    }

    /**
     * Sets the junction property name.
     *
     * @param string $junctionProperty Property name.
     * @return $this
     */
    public function setJunctionProperty(string $junctionProperty): static
    {
        $this->junctionProperty = $junctionProperty;

        return $this;
    }

    /**
     * Gets the junction property name.
     *
     * @return string
     */
    public function getJunctionProperty(): string
    {
        return $this->junctionProperty;
    }

    /**
     * Sets the save strategy.
     *
     * @param string $strategy Strategy name.
     * @return $this
     * @throws \InvalidArgumentException When an invalid strategy is passed.
     */
    public function setSaveStrategy(string $strategy): static
    {
        if (!in_array($strategy, [self::SAVE_APPEND, self::SAVE_REPLACE], true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid save strategy "%s". Valid strategies are: %s',
                $strategy,
                implode(', ', [self::SAVE_APPEND, self::SAVE_REPLACE]),
            ));
        }

        $this->saveStrategy = $strategy;

        return $this;
    }

    /**
     * Gets the save strategy.
     *
     * @return string
     */
    public function getSaveStrategy(): string
    {
        return $this->saveStrategy;
    }

    /**
     * Gets the join collection source key.
     *
     * @return string|null
     */
    public function getJoinForeignKey(): ?string
    {
        return $this->joinForeignKey;
    }

    /**
     * Sets the join collection source key.
     *
     * @param string $key Join collection source key.
     * @return $this
     */
    public function setJoinForeignKey(string $key): static
    {
        $this->joinForeignKey = $key;

        return $this;
    }

    /**
     * Gets the join collection target key.
     *
     * @return string|null
     */
    public function getTargetForeignKey(): ?string
    {
        return $this->targetForeignKey ??= $this->_modelKey($this->repositoryAlias($this->getTarget()));
    }

    /**
     * Sets the join collection target key.
     *
     * @param string $key Join collection target key.
     * @return $this
     */
    public function setTargetForeignKey(string $key): static
    {
        $this->targetForeignKey = $key;

        return $this;
    }

    /**
     * Builds the many-to-many eager-loader callable.
     *
     * @param array<string, mixed> $options Loader options.
     * @return \Closure
     */
    public function eagerLoader(array $options): Closure
    {
        $loaderOptions = [
            'finder' => fn(): QueryInterface => $this->getTarget()->find(),
            'foreignKey' => $this->getForeignKey(),
            'bindingKey' => $this->getBindingKey(),
            'nestKey' => $this->getProperty(),
            'associationType' => $this->type(),
            'strategy' => $this->getStrategy(),
            'conditions' => $this->getConditions(),
            'sort' => $this->getSort(),
        ];
        if ($this->getStrategy() === self::STRATEGY_LOOKUP) {
            return (new LookupLoader(['association' => $this]))->buildEagerLoader($options + $loaderOptions);
        }

        return (new SelectLoader($loaderOptions))->buildEagerLoader($options);
    }

    /**
     * Builds lookup stages through the join collection.
     *
     * @param array<string, mixed> $options Pipeline options.
     * @return array<int, array<string, mixed>>
     */
    public function buildPipeline(array $options = []): array
    {
        if ($this->through === null || $this->joinForeignKey === null || $this->targetForeignKey === null) {
            return [];
        }

        $through = $this->through instanceof BaseCollection
            ? $this->through->getCollection()
            : $this->through;

        $builder = $this->buildAggregation();
        $join = '_join_' . $this->getProperty();
        $builder
            ->lookup($through)
            ->localField($this->fieldName($this->getBindingKey()))
            ->foreignField($this->joinForeignKey)
            ->alias($join);
        $builder
            ->lookup($this->getTarget()->getAlias())
            ->localField($join . '.' . $this->getTargetForeignKey())
            ->foreignField('_id')
            ->alias($this->getProperty());

        return $builder->getPipeline();
    }
}

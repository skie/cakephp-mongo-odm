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
     * Explicit junction collection name (cake60 `joinTable`).
     *
     * @var string|null
     */
    protected ?string $junctionTableName = null;

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
        if (isset($options['joinCollection'])) {
            $this->junctionTableName((string)$options['joinCollection']);
        }
    }

    /**
     * Sets or returns the junction collection name.
     *
     * @param string|null $name The junction collection name.
     * @return string
     */
    protected function junctionTableName(?string $name = null): string
    {
        if ($name === null) {
            if ($this->junctionTableName === null) {
                $names = array_map(
                    static fn(string $c): string => Inflector::underscore($c),
                    [$this->getSource()->getCollection(), $this->getTarget()->getCollection()],
                );
                sort($names);
                $this->junctionTableName = implode('_', $names);
            }

            return $this->junctionTableName;
        }

        return $this->junctionTableName = $name;
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
     * Saves the associated target documents for this association.
     *
     * With the append strategy, existing links are kept and the new targets are
     * linked. With the replace strategy, the source `_ids` array is replaced.
     *
     * @param \Cake\Datasource\EntityInterface $entity The source document.
     * @param array<string, mixed> $options Save options.
     * @return \Cake\Datasource\EntityInterface|false
     */
    public function saveAssociated(EntityInterface $entity, array $options = []): EntityInterface|false
    {
        $targetEntity = $entity->get($this->getProperty());
        $strategy = $this->getSaveStrategy();

        $isEmpty = in_array($targetEntity, [null, [], '', false], true);
        if ($isEmpty && $entity->isNew()) {
            return $entity;
        }
        if ($isEmpty) {
            $targetEntity = [];
        }

        if ($strategy === self::SAVE_APPEND) {
            return $this->saveTarget($entity, $targetEntity, $options);
        }

        if ($this->replaceLinks($entity, (array)$targetEntity, $options)) {
            return $entity;
        }

        return false;
    }

    /**
     * Persists each target document and links it to the source `_ids` array.
     *
     * @param \Cake\Datasource\EntityInterface $parentEntity The source document.
     * @param iterable<mixed> $entities Target documents to save and link.
     * @param array<string, mixed> $options Save options.
     * @return \Cake\Datasource\EntityInterface|false
     */
    protected function saveTarget(
        EntityInterface $parentEntity,
        iterable $entities,
        array $options = [],
    ): EntityInterface|false {
        $targetEntities = is_array($entities) ? $entities : iterator_to_array($entities);

        $saved = [];
        $table = $this->getTarget();
        foreach ($targetEntities as $entity) {
            if (!$entity instanceof EntityInterface) {
                throw new InvalidArgumentException(sprintf(
                    'Could not save %s, it cannot be traversed.',
                    $this->getProperty(),
                ));
            }

            $result = $table->save($entity, $options);
            if ($result instanceof EntityInterface) {
                $saved[] = $result;
            } else {
                return false;
            }
        }

        return $this->link($parentEntity, $saved, $options) ? $parentEntity : false;
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
     * Replaces the source link set with the given target identifiers.
     *
     * For the in-document `_ids` shape this is equivalent to {@see replace()}.
     *
     * @param \Cake\Datasource\EntityInterface $sourceEntity The source document.
     * @param array<int, mixed> $targetEntities Target documents to keep.
     * @param array<string, mixed> $options Save options.
     * @return bool
     */
    public function replaceLinks(EntityInterface $sourceEntity, array $targetEntities, array $options = []): bool
    {
        return $this->replace($sourceEntity, $targetEntities, $options);
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
     * through is configured. When an instance or alias is passed it is used as
     * the junction. The reciprocal source/target/junction associations are
     * generated automatically (matching cake60).
     *
     * @param \Crustum\Mongo\ODM\BaseCollection|string|null $table Junction collection instance or alias.
     * @return \Crustum\Mongo\ODM\BaseCollection
     * @throws \InvalidArgumentException When source and target are the same collection.
     */
    public function junction(BaseCollection|string|null $table = null): BaseCollection
    {
        if ($table === null && $this->junctionCollection instanceof BaseCollection) {
            return $this->junctionCollection;
        }

        if ($table instanceof BaseCollection) {
            $collection = $table;
        } else {
            $through = $table ?? $this->through;
            if ($through instanceof BaseCollection) {
                $collection = $through;
            } elseif ($through !== null) {
                $collection = $this->getCollectionLocator()->get($through, ['allowFallbackClass' => true]);
                if (!$collection instanceof BaseCollection) {
                    throw new InvalidArgumentException(sprintf(
                        'Junction collection `%s` did not resolve to a BaseCollection.',
                        $through,
                    ));
                }
            } else {
                $collection = $this->defaultJunctionCollection();
            }
        }

        $this->junctionCollection = $collection;
        $this->generateSourceAssociations($collection, $this->getSource());
        $this->generateTargetAssociations($collection, $this->getSource(), $this->getTarget());
        $this->generateJunctionAssociations($collection, $this->getSource(), $this->getTarget());

        return $collection;
    }

    /**
     * Resolves or creates the conventional junction collection.
     *
     * @return \Crustum\Mongo\ODM\BaseCollection
     * @throws \InvalidArgumentException When source and target are the same collection.
     */
    protected function defaultJunctionCollection(): BaseCollection
    {
        $source = $this->getSource();
        $target = $this->getTarget();
        if ($source->getAlias() === $target->getAlias()) {
            throw new InvalidArgumentException(sprintf(
                'The `%s` association on `%s` cannot target the same table.',
                $this->getName(),
                $source->getAlias(),
            ));
        }

        $tableName = $this->junctionTableName();
        $alias = Inflector::camelize($tableName);
        $collection = $this->getCollectionLocator()->get($alias, [
            'collection' => $tableName,
            'allowFallbackClass' => true,
        ]);
        if (!$collection instanceof BaseCollection) {
            throw new InvalidArgumentException(sprintf(
                'Junction collection `%s` did not resolve to a BaseCollection.',
                $alias,
            ));
        }

        return $collection;
    }

    /**
     * Generates the source-side associations for the junction collection.
     *
     * - source hasMany junction e.g. Articles hasMany ArticlesTags
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $junction The junction collection.
     * @param \Crustum\Mongo\ODM\BaseCollection $source The source collection.
     * @return void
     */
    protected function generateSourceAssociations(BaseCollection $junction, BaseCollection $source): void
    {
        $junctionAlias = $junction->getAlias();
        $sAlias = $source->getAlias();

        $sourceBindingKey = null;
        if ($junction->hasAssociation($sAlias)) {
            $sourceBindingKey = $junction->getAssociation($sAlias)->getBindingKey();
        }

        if (!$source->hasAssociation($junctionAlias)) {
            $source->hasMany($junctionAlias, [
                'target' => $junction,
                'bindingKey' => $sourceBindingKey,
                'foreignKey' => $this->getForeignKey(),
                'strategy' => $this->getStrategy(),
            ]);
        }
    }

    /**
     * Generates the target-side associations for the junction collection.
     *
     * - target hasMany junction e.g. Tags hasMany ArticlesTags
     * - target belongsToMany source e.g. Tags belongsToMany Articles
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $junction The junction collection.
     * @param \Crustum\Mongo\ODM\BaseCollection $source The source collection.
     * @param \Crustum\Mongo\ODM\BaseCollection $target The target collection.
     * @return void
     */
    protected function generateTargetAssociations(BaseCollection $junction, BaseCollection $source, BaseCollection $target): void
    {
        $junctionAlias = $junction->getAlias();
        $sAlias = $source->getAlias();
        $tAlias = $target->getAlias();

        $targetBindingKey = null;
        if ($junction->hasAssociation($tAlias)) {
            $targetBindingKey = $junction->getAssociation($tAlias)->getBindingKey();
        }

        if (!$target->hasAssociation($junctionAlias)) {
            $target->hasMany($junctionAlias, [
                'target' => $junction,
                'bindingKey' => $targetBindingKey,
                'foreignKey' => $this->getTargetForeignKey(),
                'strategy' => $this->getStrategy(),
            ]);
        }
        if (!$target->hasAssociation($sAlias)) {
            $target->belongsToMany($sAlias, [
                'source' => $target,
                'target' => $source,
                'foreignKey' => $this->getTargetForeignKey(),
                'targetForeignKey' => $this->getForeignKey(),
                'through' => $junction,
                'conditions' => $this->getConditions(),
                'strategy' => $this->getStrategy(),
            ]);
        }
    }

    /**
     * Generates the associations on the junction collection.
     *
     * - junction belongsTo source e.g. ArticlesTags belongsTo Articles
     * - junction belongsTo target e.g. ArticlesTags belongsTo Tags
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $junction The junction collection.
     * @param \Crustum\Mongo\ODM\BaseCollection $source The source collection.
     * @param \Crustum\Mongo\ODM\BaseCollection $target The target collection.
     * @return void
     * @throws \InvalidArgumentException When the existing associations are incompatible.
     */
    protected function generateJunctionAssociations(BaseCollection $junction, BaseCollection $source, BaseCollection $target): void
    {
        $tAlias = $target->getAlias();
        $sAlias = $source->getAlias();

        if (!$junction->hasAssociation($tAlias)) {
            $junction->belongsTo($tAlias, [
                'foreignKey' => $this->getTargetForeignKey(),
                'target' => $target,
            ]);
        } else {
            $belongsTo = $junction->getAssociation($tAlias);
            if ($belongsTo instanceof Association) {
                if (
                    $this->getTargetForeignKey() !== $belongsTo->getForeignKey() ||
                    $target !== $belongsTo->getTarget()
                ) {
                    throw new InvalidArgumentException(sprintf(
                        'The existing `%s` association on `%s` is incompatible with the `%s` association on `%s`.',
                        $tAlias,
                        $junction->getAlias(),
                        $this->getName(),
                        $source->getAlias(),
                    ));
                }
            }
        }

        if (!$junction->hasAssociation($sAlias)) {
            $junction->belongsTo($sAlias, [
                'bindingKey' => $this->getBindingKey(),
                'foreignKey' => $this->getForeignKey(),
                'target' => $source,
            ]);
        }
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

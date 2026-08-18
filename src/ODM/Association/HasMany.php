<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Association;

use Cake\Datasource\EntityInterface;
use Cake\Datasource\QueryInterface;
use Cake\Utility\Inflector;
use Closure;
use Crustum\Mongo\Database\Aggregation\AggregationBuilder;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\ODM\Association;
use Crustum\Mongo\ODM\Association\Loader\LookupLoader;
use Crustum\Mongo\ODM\Association\Loader\SelectLoader;
use Crustum\Mongo\ODM\BaseCollection;
use InvalidArgumentException;

/**
 * Represents a one-to-many relationship from the source document.
 *
 * @see cake60/src/ORM/Association/HasMany.php
 */
class HasMany extends Association
{
    /**
     * Appends new target documents to the source property on save.
     */
    public const string SAVE_APPEND = 'append';

    /**
     * Replaces the target documents on save, unlinking removed ones.
     */
    public const string SAVE_REPLACE = 'replace';

    /**
     * Save strategy for the association property.
     *
     * @var string
     */
    protected string $saveStrategy = self::SAVE_APPEND;

    /** Valid loading strategies for this association. */
    /**
     * @var array<string>
     */
    protected array $validStrategies = [self::STRATEGY_SUBQUERY, self::STRATEGY_SELECT, self::STRATEGY_LOOKUP];

    /**
     * Gets the relationship type.
     *
     * @return string
     */
    public function type(): string
    {
        return self::ONE_TO_MANY;
    }

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
    }

    /**
     * Handles HasMany-specific constructor options.
     *
     * @param array<string, mixed> $options Association configuration.
     * @return void
     */
    protected function options(array $options): void
    {
        if (isset($options['saveStrategy'])) {
            $this->setSaveStrategy((string)$options['saveStrategy']);
        }

        if (isset($options['sort'])) {
            $this->setSort($options['sort']);
        }
    }

    /**
     * Gets the default loading strategy.
     *
     * @return string
     */
    protected function defaultStrategy(): string
    {
        return self::STRATEGY_SUBQUERY;
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
                'Invalid strategy `%s` was provided',
                $strategy,
            ));
        }

        $this->strategy = $strategy;

        return $this;
    }

    /**
     * Whether the association requires binding keys to be selected.
     *
     * @param array<string, mixed> $options Loader options.
     * @return bool
     */
    public function requiresKeys(array $options = []): bool
    {
        $strategy = $options['strategy'] ?? $this->strategy ?? $this->defaultStrategy();

        return $strategy === self::STRATEGY_SELECT;
    }

    /**
     * Gets the target foreign key.
     *
     * @return array<string>|string|null
     */
    public function getForeignKey(): string|array|false|null
    {
        return $this->foreignKey ??= $this->_modelKey($this->repositoryAlias($this->getSource()));
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
     * Sets the strategy used when saving associated documents.
     *
     * @param string $strategy The save strategy (append or replace).
     * @return $this
     */
    public function setSaveStrategy(string $strategy): static
    {
        if (!in_array($strategy, [self::SAVE_APPEND, self::SAVE_REPLACE], true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid save strategy `%s`',
                $strategy,
            ));
        }

        $this->saveStrategy = $strategy;

        return $this;
    }

    /**
     * Gets the strategy used when saving associated documents.
     *
     * @return string
     */
    public function getSaveStrategy(): string
    {
        return $this->saveStrategy;
    }

    /**
     * The source side owns the foreign key for a has-many association.
     *
     * @return bool
     */
    public function isOwningSide(): bool
    {
        return true;
    }

    /**
     * Whether this association can be expressed as an in-pipeline `$lookup`.
     *
     * Cake HasMany is joinable only for `matching()`, never for contain().
     *
     * @param array<string, mixed> $options Containment options.
     * @return bool
     */
    public function canBeJoined(array $options = []): bool
    {
        return !empty($options['matching']);
    }

    /**
     * Saves the associated target documents, back-filling the foreign key.
     *
     * With the replace strategy, targets removed from the source property are
     * unlinked before saving the remaining ones.
     *
     * @param \Cake\Datasource\EntityInterface $document The source document.
     * @param array<string, mixed> $options Save options.
     * @return \Cake\Datasource\EntityInterface|false
     */
    public function saveAssociated(EntityInterface $document, array $options = []): EntityInterface|false
    {
        $targetEntities = $document->get($this->getProperty());

        $isEmpty = in_array($targetEntities, [null, [], '', false], true);
        if ($isEmpty) {
            if ($document->isNew() || $this->getSaveStrategy() !== self::SAVE_REPLACE) {
                return $document;
            }

            $targetEntities = [];
        }

        if (!is_iterable($targetEntities)) {
            throw new InvalidArgumentException(sprintf(
                'Could not save %s, it cannot be traversed.',
                $this->getProperty(),
            ));
        }

        $foreignKeys = array_values(array_filter(
            (array)$this->getForeignKey(),
            is_string(...),
        ));
        $foreignKeyReference = array_combine(
            $foreignKeys,
            $document->extract((array)$this->getBindingKey()),
        );

        $options['sourceCollection'] = $this->getSource();

        if (
            $this->saveStrategy === self::SAVE_REPLACE
            && !$this->unlinkAssociated($foreignKeyReference, $targetEntities, $options)
        ) {
            return false;
        }

        if (!is_array($targetEntities)) {
            $targetEntities = iterator_to_array($targetEntities);
        }

        if (!$this->saveTarget($foreignKeyReference, $document, $targetEntities, $options)) {
            return false;
        }

        return $document;
    }

    /**
     * Persists each target document with the foreign key back-filled.
     *
     * @param array<string, mixed> $foreignKeyReference The source reference values.
     * @param \Cake\Datasource\EntityInterface $parentEntity The source document.
     * @param array<int, mixed> $entities Target documents.
     * @param array<string, mixed> $options Save options.
     * @return bool
     */
    protected function saveTarget(
        array $foreignKeyReference,
        EntityInterface $parentEntity,
        array $entities,
        array $options,
    ): bool {
        $foreignKey = array_keys($foreignKeyReference);
        $collection = $this->getTarget();
        $original = $entities;

        foreach ($entities as $k => $document) {
            if (!$document instanceof EntityInterface) {
                break;
            }

            if (!empty($options['atomic'])) {
                $document = clone $document;
            }

            if ($foreignKeyReference !== $document->extract($foreignKey)) {
                $document->patch($foreignKeyReference, ['guard' => false]);
            }

            $saved = $collection->save($document, $options);
            if ($saved instanceof EntityInterface) {
                $entities[$k] = $saved;

                continue;
            }

            if (!empty($options['atomic']) && isset($original[$k]) && $original[$k] instanceof EntityInterface) {
                $original[$k]->setErrors($document->getErrors());

                return false;
            }
        }

        $parentEntity->set($this->getProperty(), $entities);

        return true;
    }

    /**
     * Unlinks target documents that no longer belong to the source.
     *
     * When the target supports `deleteAll`, rows matching the foreign key that
     * are not part of the given list are removed.
     *
     * @param array<string, mixed> $foreignKeyReference The source reference values.
     * @param iterable<mixed> $targetEntities The remaining target documents.
     * @param array<string, mixed> $options Save options.
     * @return bool
     */
    protected function unlinkAssociated(
        array $foreignKeyReference,
        iterable $targetEntities,
        array $options = [],
    ): bool {
        $target = $this->getTarget();

        $ids = [];
        foreach ($targetEntities as $targetEntity) {
            if ($targetEntity instanceof EntityInterface) {
                $id = $targetEntity->get('_id');
                if ($id !== null) {
                    $ids[] = $id;
                }
            }
        }

        $conditions = $foreignKeyReference;
        if ($ids !== []) {
            $conditions['_id NOT IN'] = $ids;
        }

        $foreignKey = array_keys($foreignKeyReference);
        if ($this->getDependent() || !$this->foreignKeyAcceptsNull($target, $foreignKey)) {
            if ($this->getCascadeCallbacks()) {
                $related = array_filter(
                    $target->find('all')->where($conditions)->toArray(),
                    static fn(mixed $document): bool => $document instanceof EntityInterface,
                );

                return $target->deleteMany($related, $options) !== false;
            }

            $this->deleteAll($conditions);

            return true;
        }

        $this->updateAll(array_fill_keys($foreignKey, null), $conditions);

        return true;
    }

    /**
     * Whether the foreign key fields accept null values.
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $target The target collection.
     * @param array<int, string> $foreignKey The foreign key fields.
     * @return bool
     */
    protected function foreignKeyAcceptsNull(BaseCollection $target, array $foreignKey): bool
    {
        $schema = $target->describeSchema();
        foreach ($foreignKey as $field) {
            if (!$schema->isNullable($field)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Appends target documents to the source property and persists the link.
     *
     * @param \Cake\Datasource\EntityInterface $sourceEntity The source document.
     * @param array<int, mixed> $targetEntities Target documents to link.
     * @param array<string, mixed> $options Save options.
     * @return bool
     */
    public function link(EntityInterface $sourceEntity, array $targetEntities, array $options = []): bool
    {
        $saveStrategy = $this->getSaveStrategy();
        $this->setSaveStrategy(self::SAVE_APPEND);
        $property = $this->getProperty();

        $currentEntities = (array)$sourceEntity->get($property);
        if ($currentEntities === []) {
            $currentEntities = $targetEntities;
        } else {
            $pkFields = (array)$this->getTarget()->getPrimaryKey();
            // Keep every new (unsaved) target; drop only persisted targets that
            // already exist in the current set (cake60 reject semantics).
            $targetEntities = array_values(array_filter(
                $targetEntities,
                function (mixed $document) use ($currentEntities, $pkFields): bool {
                    if (!$document instanceof EntityInterface || $document->isNew()) {
                        return true;
                    }

                    return !array_any(
                        $currentEntities,
                        fn(mixed $cEntity): bool => $cEntity instanceof EntityInterface
                            && $document->extract($pkFields) === $cEntity->extract($pkFields),
                    );
                },
            ));

            $currentEntities = array_merge($currentEntities, $targetEntities);
        }

        $sourceEntity->set($property, $currentEntities);

        $connection = $this->getSource()->getConnection();
        assert($connection instanceof Connection);
        $savedEntity = $connection->transactional(
            fn(): EntityInterface|false => $this->saveAssociated($sourceEntity, $options),
        );
        $ok = $savedEntity instanceof EntityInterface;

        $this->setSaveStrategy($saveStrategy);

        if ($ok) {
            $sourceEntity->set($property, $savedEntity->get($property));
            $sourceEntity->setDirty($property, false);
        }

        return $ok;
    }

    /**
     * Removes target documents from the source property and nullifies the link.
     *
     * @param \Cake\Datasource\EntityInterface $sourceEntity The source document.
     * @param array<int, mixed> $targetEntities Target documents to unlink.
     * @param array<string, mixed> $options Save options.
     * @return bool
     */
    public function unlink(EntityInterface $sourceEntity, array $targetEntities, array|bool $options = []): bool
    {
        if (is_bool($options)) {
            $options = ['cleanProperty' => $options];
        } else {
            $options += ['cleanProperty' => true];
        }

        if ($targetEntities === []) {
            return true;
        }

        $foreignKey = array_values(array_filter(
            (array)$this->getForeignKey(),
            is_string(...),
        ));
        $target = $this->getTarget();
        if ($this->getDependent() || !$this->foreignKeyAcceptsNull($target, $foreignKey)) {
            $targetIds = [];
            foreach ($targetEntities as $targetEntity) {
                if ($targetEntity instanceof EntityInterface && $targetEntity->get('_id') !== null) {
                    $targetIds[] = $targetEntity->get('_id');
                }
            }

            $conditions = ['_id IN' => $targetIds];
            if ($this->getCascadeCallbacks()) {
                $related = array_filter(
                    $target->find('all')->where($conditions)->toArray(),
                    static fn(mixed $document): bool => $document instanceof EntityInterface,
                );
                if ($target->deleteMany($related, $options) === false) {
                    return false;
                }
            } else {
                $this->deleteAll($conditions);
            }
        }

        $property = $this->getProperty();
        $originalProperty = $sourceEntity->get($property);
        $currentEntities = (array)$sourceEntity->get($property);
        $targetIds = [];
        foreach ($targetEntities as $targetEntity) {
            if ($targetEntity instanceof EntityInterface) {
                $targetIds[] = $targetEntity->get('_id');
            }
        }

        $remaining = array_values(array_filter(
            $currentEntities,
            static function (mixed $document) use ($targetIds): bool {
                if (!$document instanceof EntityInterface || $document->get('_id') === null) {
                    return true;
                }

                return !in_array($document->get('_id'), $targetIds, true);
            },
        ));

        $sourceEntity->set($property, $remaining);
        $saved = $this->saveAssociated($sourceEntity, $options);
        if ($saved instanceof EntityInterface) {
            if (!$options['cleanProperty']) {
                $sourceEntity->set($property, $originalProperty, ['guard' => false]);
            } else {
                $sourceEntity->set($property, $saved->get($property));
            }

            $sourceEntity->setDirty($property, false);
        }

        return $saved instanceof EntityInterface;
    }

    /**
     * Replaces the source property contents with the given target documents.
     *
     * @param \Cake\Datasource\EntityInterface $sourceEntity The source document.
     * @param array<int, mixed> $targetEntities Target documents to keep.
     * @param array<string, mixed> $options Save options.
     * @return bool
     */
    public function replace(EntityInterface $sourceEntity, array $targetEntities, array $options = []): bool
    {
        $property = $this->getProperty();
        $sourceEntity->set($property, $targetEntities);
        $saveStrategy = $this->getSaveStrategy();
        $this->setSaveStrategy(self::SAVE_REPLACE);
        $saved = $this->saveAssociated($sourceEntity, $options);
        $ok = $saved instanceof EntityInterface;
        $this->setSaveStrategy($saveStrategy);

        if ($ok) {
            $sourceEntity->set($property, $saved->get($property));
            $sourceEntity->setDirty($property, false);
        }

        return $ok;
    }

    /**
     * Builds the has-many eager-loader callable.
     *
     * @param array<string, mixed> $options Loader options.
     * @return \Closure
     */
    public function eagerLoader(array $options): Closure
    {
        $loaderOptions = [
            'finder' => $options['finder'] ?? fn(): QueryInterface => $this->getTarget()->find('all'),
            'foreignKey' => $this->getForeignKey(),
            'bindingKey' => $this->getBindingKey(),
            'nestKey' => $this->getProperty(),
            'associationType' => $this->type(),
            'sort' => $this->getSort(),
            'strategy' => $this->getStrategy(),
            'conditions' => $this->getConditions(),
        ];
        $isNestedLoad = !empty($options['sourcePath']);
        if ($this->getStrategy() === self::STRATEGY_LOOKUP && !$isNestedLoad) {
            return (new LookupLoader(['association' => $this]))->buildEagerLoader($options + $loaderOptions);
        }

        return (new SelectLoader($loaderOptions))->buildEagerLoader($options);
    }

    /**
     * Builds a lookup pipeline for the association.
     *
     * @param array<string, mixed> $options Pipeline options.
     * @return list<array<string, mixed>>
     */
    public function buildPipeline(array $options = []): array
    {
        $foreignKey = $options['foreignKey'] ?? $this->getForeignKey();
        $this->assertJoinKeyCounts($foreignKey, $this->getBindingKey());
        $localFields = $this->prefixLookupFields($this->fieldNames($this->getBindingKey()), $options);
        $foreignFields = $this->fieldNames($foreignKey);
        $composite = count($localFields) > 1;
        $let = $composite ? $this->lookupLet($localFields) : [];

        $builder = $this->buildAggregation();
        $lookup = $builder
            ->lookup($this->getTarget()->getCollection())
            ->alias($this->getProperty());
        if (!$composite && $localFields !== [] && $foreignFields !== []) {
            $lookup->localField($localFields[0])->foreignField($foreignFields[0]);
        } elseif ($composite) {
            $lookup->let($let);
        }

        $applyJoinSubPipeline = function (AggregationBuilder $sub, array $subOptions) use (
            $composite,
            $let,
            $foreignFields,
        ): void {
            if ($composite) {
                $this->applyJoinLookupSubPipeline($sub, $foreignFields, $let, $subOptions, false);

                return;
            }

            $this->applyLookupSubPipeline($sub, $subOptions);
        };

        if (!empty($options['matching'])) {
            $builder->unwind('$' . $this->getProperty(), [
                'preserveNullAndEmptyArrays' => !empty($options['negateMatch']),
            ]);
        }

        $pipelineOptions = $options;
        if (!empty($options['matching']) && !empty($options['negateMatch']) && empty($options['deferNegateMatch'])) {
            $lookup->pipeline(function (AggregationBuilder $sub) use ($applyJoinSubPipeline, $options): void {
                $applyJoinSubPipeline($sub, $options);
            });
            $builder->unwind('$' . $this->getProperty(), ['preserveNullAndEmptyArrays' => true]);
            $builder->match([$this->getProperty() => null]);

            return $builder->getPipeline();
        }

        if (!empty($options['matching']) && !empty($pipelineOptions['conditions'])) {
            $property = $this->getProperty();
            $pipelineOptions['conditions'] = $this->prefixMatchConditions(
                $pipelineOptions['conditions'],
                $property,
            );
            if ($composite) {
                $lookup->pipeline(function (AggregationBuilder $sub) use ($applyJoinSubPipeline): void {
                    $applyJoinSubPipeline($sub, []);
                });
            }
        } elseif (empty($options['matching'])) {
            $lookup->pipeline(function (AggregationBuilder $sub) use ($applyJoinSubPipeline, $pipelineOptions): void {
                $applyJoinSubPipeline($sub, $pipelineOptions);
            });
            unset($pipelineOptions['conditions'], $pipelineOptions['sort'], $pipelineOptions['fields'], $pipelineOptions['skip'], $pipelineOptions['limit']);
        } elseif ($composite) {
            $lookup->pipeline(function (AggregationBuilder $sub) use ($applyJoinSubPipeline): void {
                $applyJoinSubPipeline($sub, []);
            });
        }

        unset($pipelineOptions['fields']);
        $this->applyPipelineOptions($builder, $pipelineOptions);

        if (!empty($options['negateMatch']) && empty($options['deferNegateMatch'])) {
            $builder->match([$this->getProperty() => null]);
        }

        return $builder->getPipeline();
    }

    /**
     * @inheritDoc
     */
    public function cascadeDelete(EntityInterface $document, array $options = []): bool
    {
        return (new DependentDeleteHelper())->cascadeDelete($this, $document, $options);
    }
}

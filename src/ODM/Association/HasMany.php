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
        if (isset($options['saveStrategy'])) {
            $this->setSaveStrategy((string)$options['saveStrategy']);
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
     * Has-many targets are never joined into the source pipeline.
     *
     * @return bool
     */
    public function canBeJoined(): bool
    {
        return false;
    }

    /**
     * Saves the associated target documents, back-filling the foreign key.
     *
     * With the replace strategy, targets removed from the source property are
     * unlinked before saving the remaining ones.
     *
     * @param \Cake\Datasource\EntityInterface $entity The source document.
     * @param array<string, mixed> $options Save options.
     * @return \Cake\Datasource\EntityInterface|false
     */
    public function saveAssociated(EntityInterface $entity, array $options = []): EntityInterface|false
    {
        $targetEntities = $entity->get($this->getProperty());

        $isEmpty = in_array($targetEntities, [null, [], '', false], true);
        if ($isEmpty) {
            if ($entity->isNew() || $this->getSaveStrategy() !== self::SAVE_REPLACE) {
                return $entity;
            }

            $targetEntities = [];
        }

        if (!is_iterable($targetEntities)) {
            throw new InvalidArgumentException(sprintf(
                'Could not save %s, it cannot be traversed.',
                $this->getProperty(),
            ));
        }

        $foreignKeyReference = array_combine(
            (array)$this->getForeignKey(),
            $entity->extract((array)$this->getBindingKey()),
        );

        $options['_sourceTable'] = $this->getSource();

        if (
            $this->saveStrategy === self::SAVE_REPLACE
            && !$this->unlinkAssociated($foreignKeyReference, $targetEntities, $options)
        ) {
            return false;
        }

        if (!is_array($targetEntities)) {
            $targetEntities = iterator_to_array($targetEntities);
        }

        if (!$this->saveTarget($foreignKeyReference, $entity, $targetEntities, $options)) {
            return false;
        }

        return $entity;
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
        $table = $this->getTarget();
        $original = $entities;

        foreach ($entities as $k => $entity) {
            if (!$entity instanceof EntityInterface) {
                break;
            }

            if (!empty($options['atomic'])) {
                $entity = clone $entity;
            }

            if ($foreignKeyReference !== $entity->extract($foreignKey)) {
                $entity->patch($foreignKeyReference, ['guard' => false]);
            }

            $saved = $table->save($entity, $options);
            if ($saved instanceof EntityInterface) {
                $entities[$k] = $saved;

                continue;
            }

            if (!empty($options['atomic']) && isset($original[$k]) && $original[$k] instanceof EntityInterface) {
                $original[$k]->setErrors($entity->getErrors());

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
                foreach ($target->find('all')->where($conditions)->toArray() as $related) {
                    if (!$target->delete($related, $options)) {
                        return false;
                    }
                }

                return true;
            }

            $target->deleteAll($conditions);

            return true;
        }

        $target->updateAll(array_fill_keys($foreignKey, null), $conditions);

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
        $schema = $target->getSchema();
        foreach ($foreignKey as $field) {
            if (method_exists($schema, 'isNullable') && !$schema->isNullable($field)) {
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
        $currentEntities = $currentEntities === [] ? $targetEntities : array_merge($currentEntities, $targetEntities);

        $sourceEntity->set($property, $currentEntities);
        $saved = $this->saveAssociated($sourceEntity, $options);

        $this->setSaveStrategy($saveStrategy);

        return $saved instanceof EntityInterface;
    }

    /**
     * Removes target documents from the source property and nullifies the link.
     *
     * @param \Cake\Datasource\EntityInterface $sourceEntity The source document.
     * @param array<int, mixed> $targetEntities Target documents to unlink.
     * @param array<string, mixed> $options Save options.
     * @return bool
     */
    public function unlink(EntityInterface $sourceEntity, array $targetEntities, array $options = []): bool
    {
        $property = $this->getProperty();
        $currentEntities = (array)$sourceEntity->get($property);
        $targetIds = [];
        foreach ($targetEntities as $targetEntity) {
            if ($targetEntity instanceof EntityInterface) {
                $targetIds[] = $targetEntity->get('_id');
            }
        }

        $remaining = array_values(array_filter(
            $currentEntities,
            static function (mixed $entity) use ($targetIds): bool {
                if (!$entity instanceof EntityInterface || $entity->get('_id') === null) {
                    return true;
                }

                return !in_array($entity->get('_id'), $targetIds, true);
            },
        ));

        $sourceEntity->set($property, $remaining);
        $saved = $this->saveAssociated($sourceEntity, $options);

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
        $sourceEntity->set($this->getProperty(), $targetEntities);
        $saved = $this->saveAssociated($sourceEntity, $options + ['replace' => true]);

        return $saved instanceof EntityInterface;
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
            'finder' => fn(): QueryInterface => $this->getTarget()->find(),
            'foreignKey' => $this->getForeignKey(),
            'bindingKey' => $this->getBindingKey(),
            'nestKey' => $this->getProperty(),
            'associationType' => $this->type(),
            'sort' => $this->getSort(),
            'strategy' => $this->getStrategy(),
            'conditions' => $this->getConditions(),
        ];
        if ($this->getStrategy() === self::STRATEGY_LOOKUP) {
            return (new LookupLoader(['association' => $this]))->buildEagerLoader($options + $loaderOptions);
        }

        return (new SelectLoader($loaderOptions))->buildEagerLoader($options);
    }

    /**
     * Builds a lookup pipeline for the association.
     *
     * @param array<string, mixed> $options Pipeline options.
     * @return array<int, array<string, mixed>>
     */
    public function buildPipeline(array $options = []): array
    {
        $builder = $this->buildAggregation();
        $builder
            ->lookup($this->getTarget()->getCollection())
            ->localField($this->fieldName($this->getBindingKey()))
            ->foreignField($this->fieldName($this->getForeignKey()))
            ->alias($this->getProperty());
        $this->applyPipelineOptions($builder, $options);

        return $builder->getPipeline();
    }

    /**
     * @inheritDoc
     */
    public function cascadeDelete(EntityInterface $entity, array $options = []): bool
    {
        return (new DependentDeleteHelper())->cascadeDelete($this, $entity, $options);
    }
}

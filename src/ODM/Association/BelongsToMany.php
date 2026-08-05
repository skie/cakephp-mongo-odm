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
    /** Valid loading strategies for this association. */
    /**
     * @var array<string>
     */
    protected array $validStrategies = [self::STRATEGY_SELECT, self::STRATEGY_LOOKUP];

    /**
     * Join collection alias.
     */
    protected ?string $through = null;

    /**
     * Foreign key from the join collection to the source.
     */
    protected ?string $joinForeignKey = null;

    /**
     * Foreign key from the join collection to the target.
     */
    protected ?string $targetForeignKey = null;

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
        if ($this->through !== null) {
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
    public function getForeignKey(): string|array|null
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
     * Gets the join collection alias.
     *
     * @return string|null
     */
    public function getThrough(): ?string
    {
        return $this->through;
    }

    /**
     * Sets the join collection alias.
     *
     * @param string $through Join collection alias.
     * @return $this
     */
    public function setThrough(string $through): static
    {
        $this->through = $through;

        return $this;
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

        $builder = $this->buildAggregation();
        $join = '_join_' . $this->getProperty();
        $builder
            ->lookup($this->through)
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

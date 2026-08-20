<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Association;

use Cake\Collection\CollectionInterface;
use Cake\Datasource\EntityInterface;
use Cake\Datasource\QueryInterface;
use Cake\Datasource\ResultSetInterface;
use Cake\Utility\Inflector;
use Closure;
use Crustum\Mongo\Database\Aggregation\AggregationBuilder;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Database\Expression\FunctionExpression;
use Crustum\Mongo\Database\FunctionsBuilder;
use Crustum\Mongo\Database\QueryBuilder;
use Crustum\Mongo\Database\Type\ObjectIdType;
use Crustum\Mongo\ODM\Association;
use Crustum\Mongo\ODM\Association\Loader\LookupLoader;
use Crustum\Mongo\ODM\Association\Loader\SelectLoader;
use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\ODM\Query\SelectQuery;
use InvalidArgumentException;
use MongoDB\BSON\ObjectId;
use SplObjectStorage;
use Throwable;

/**
 * Represents a many-to-many relationship.
 *
 * When no join (`through`) collection is configured the relationship is
 * stored as an `_ids` array on the source document, which is the idiomatic
 * MongoDB shape. A join collection is supported for lookup pipelines.
 *
 * @inspired-by \Cake\ORM\Association\BelongsToMany
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
    protected array $validStrategies = [self::STRATEGY_SUBQUERY, self::STRATEGY_SELECT, self::STRATEGY_LOOKUP];

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
    protected ?string $junctionCollectionName = null;

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
        $this->dependent = (bool)($options['dependent'] ?? true);
        $this->through = $options['through'] ?? null;
        $this->joinForeignKey = $options['joinForeignKey'] ?? null;
        $this->targetForeignKey = $options['targetForeignKey'] ?? null;
        $this->junctionProperty = (string)($options['junctionProperty'] ?? $this->junctionProperty);
        if (isset($options['saveStrategy'])) {
            $this->setSaveStrategy((string)$options['saveStrategy']);
        }

        if (isset($options['sort'])) {
            $this->setSort($options['sort']);
        }

        if (isset($options['joinCollection'])) {
            $this->junctionCollectionName((string)$options['joinCollection']);
        }
    }

    /**
     * Sets or returns the junction collection name.
     *
     * @param string|null $name The junction collection name.
     * @return string
     */
    protected function junctionCollectionName(?string $name = null): string
    {
        if ($name === null) {
            if ($this->junctionCollectionName === null) {
                $names = array_map(
                    Inflector::underscore(...),
                    [$this->getSource()->getCollection(), $this->getTarget()->getCollection()],
                );
                sort($names);
                $this->junctionCollectionName = implode('_', $names);
            }

            return $this->junctionCollectionName;
        }

        return $this->junctionCollectionName = $name;
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
     * Sets the loading strategy.
     *
     * `subquery` (cake60 default) maps to the aggregation lookup pipeline;
     * `select` uses a separate batched query. `join` is unsupported in the ODM.
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
     * Gets the loading strategy.
     *
     * @return string
     */
    public function getStrategy(): string
    {
        $strategy = $this->strategy ??= $this->defaultStrategy();
        if ($strategy === self::STRATEGY_SUBQUERY) {
            return self::STRATEGY_LOOKUP;
        }

        return $strategy;
    }

    /**
     * Whether the association requires binding keys to be selected.
     *
     * @param array<string, mixed> $options Loader options.
     * @return bool
     */
    public function requiresKeys(array $options = []): bool
    {
        $strategy = $this->strategy ?? $this->defaultStrategy();

        return $strategy === self::STRATEGY_SELECT;
    }

    /**
     * Whether this association can be expressed as an in-pipeline `$lookup`.
     *
     * Cake BelongsToMany is joinable only for `matching()`, never for contain().
     * Default subquery→lookup is a loader mapping, not a joinable contain().
     *
     * @param array<string, mixed> $options Containment options.
     * @return bool
     */
    public function canBeJoined(array $options = []): bool
    {
        return !empty($options['matching']);
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
     * @param \Cake\Datasource\EntityInterface $document The source document.
     * @param array<string, mixed> $options Save options.
     * @return \Cake\Datasource\EntityInterface|false
     */
    public function saveAssociated(EntityInterface $document, array $options = []): EntityInterface|false
    {
        $targetEntity = $document->get($this->getProperty());
        $strategy = $this->getSaveStrategy();

        $isEmpty = in_array($targetEntity, [null, [], '', false], true);
        if ($isEmpty && $document->isNew()) {
            return $document;
        }

        if ($isEmpty) {
            $targetEntity = [];
        }

        if ($strategy === self::SAVE_APPEND) {
            return $this->saveTarget($document, $targetEntity, $options);
        }

        if ($this->replaceLinks($document, (array)$targetEntity, $options)) {
            return $document;
        }

        return false;
    }

    /**
     * Cascades deletes to the junction collection.
     *
     * When `dependent` is enabled the junction links for the source entity are
     * removed. With `cascadeCallbacks` each link is deleted through the
     * collection (firing events); otherwise a bulk `deleteAll()` runs.
     *
     * @param \Cake\Datasource\EntityInterface $document The source document.
     * @param array<string, mixed> $options Delete options.
     * @return bool
     */
    public function cascadeDelete(EntityInterface $document, array $options = []): bool
    {
        if (!$this->getDependent()) {
            return true;
        }

        /** @var array<string> $foreignKeys */
        $foreignKeys = (array)$this->getForeignKey();
        $bindingKeys = (array)$this->getBindingKey();
        $conditions = [];

        if ($bindingKeys !== []) {
            $conditions = array_combine($foreignKeys, $document->extract($bindingKeys));
        }

        $collection = $this->junction();
        $hasMany = $this->getSource()->getAssociation($collection->getAlias());
        if ($this->getCascadeCallbacks()) {
            /** @var \Cake\Datasource\EntityInterface $related */
            foreach ($hasMany->find('all')->where($conditions)->toArray() as $related) {
                $success = $collection->delete($related, $options);
                if (!$success) {
                    return false;
                }
            }

            return true;
        }

        $assocConditions = $hasMany->getConditions();
        if (is_array($assocConditions)) {
            $conditions = array_merge($conditions, $assocConditions);
        }

        $collection->deleteAll($conditions);

        return true;
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
        $joinAssociations = false;
        if (isset($options['associated']) && is_array($options['associated'])) {
            if (!empty($options['associated'][$this->junctionProperty]['associated'])) {
                $joinAssociations = $options['associated'][$this->junctionProperty]['associated'];
            }

            unset($options['associated'][$this->junctionProperty]);
        }

        $targetEntities = is_array($entities) ? $entities : iterator_to_array($entities);

        $collection = $this->getTarget();
        $original = $targetEntities;
        $persisted = [];

        foreach ($targetEntities as $k => $document) {
            if (!$document instanceof EntityInterface) {
                break;
            }

            if (!empty($options['atomic'])) {
                $document = clone $document;
            }

            $saved = $collection->save($document, $options);
            if ($saved instanceof EntityInterface) {
                $targetEntities[$k] = $document;
                $persisted[] = $document;
                continue;
            }

            if (!empty($options['atomic'])) {
                $originalEntity = $original[$k] ?? null;
                if ($originalEntity instanceof EntityInterface) {
                    $originalEntity->setErrors($document->getErrors());
                }
            }

            return false;
        }

        $options['associated'] = $joinAssociations;
        $success = $this->saveLinks($parentEntity, $persisted, $options);
        if (!$success && !empty($options['atomic'])) {
            $parentEntity->set($this->getProperty(), $original);

            return false;
        }

        $parentEntity->set($this->getProperty(), $targetEntities);

        return $parentEntity;
    }

    /**
     * Associates the source document to each of the target documents provided by
     * creating links in the junction collection. Both the source document and each
     * of the target documents are assumed to be already persisted.
     *
     * This method does not check link uniqueness.
     *
     * @param \Cake\Datasource\EntityInterface $sourceEntity the row belonging to the `source` side
     *   of this association
     * @param array<\Cake\Datasource\EntityInterface> $targetEntities list of entities belonging to the `target` side
     *   of this association
     * @param array<string, mixed> $options list of options to be passed to the internal `save` call
     * @throws \InvalidArgumentException when any of the values in $targetEntities is
     *   detected to not be already persisted
     * @return bool true on success, false otherwise
     */
    public function link(EntityInterface $sourceEntity, array $targetEntities, array $options = []): bool
    {
        $this->checkPersistenceStatus($sourceEntity, $targetEntities);
        $property = $this->getProperty();
        $links = $sourceEntity->get($property) ?: [];
        $links = array_merge($links, $targetEntities);

        $sourceEntity->set($property, $links);

        $connection = $this->getSource()->getConnection();
        assert($connection instanceof Connection);

        return $connection->transactional(
            fn(): bool => $this->saveLinks($sourceEntity, $targetEntities, $options),
        );
    }

    /**
     * Removes all links between the passed source entity and each of the provided
     * target entities. This method assumes that all passed objects are already persisted
     * in the database and that each of them contain a primary key value.
     *
     * ### Options
     *
     * Additionally to the default options accepted by `Collection::delete()`, the following
     * keys are supported:
     *
     * - cleanProperty: Whether to remove all the objects in `$targetEntities` that
     * are stored in `$sourceEntity` (default: true)
     *
     * @param \Cake\Datasource\EntityInterface $sourceEntity An entity persisted in the source collection for
     *   this association.
     * @param array<\Cake\Datasource\EntityInterface> $targetEntities List of entities persisted in the target collection for
     *   this association.
     * @param array<string, mixed>|bool $options List of options to be passed to the internal `delete` call,
     *   or a `boolean` as `cleanProperty` key shortcut.
     * @throws \InvalidArgumentException If non-persisted entities are passed or if
     *   any of them is lacking a primary key value.
     * @return bool Success
     */
    public function unlink(EntityInterface $sourceEntity, array $targetEntities, array|bool $options = []): bool
    {
        if (is_bool($options)) {
            $options = [
                'cleanProperty' => $options,
            ];
        } else {
            $options += ['cleanProperty' => true];
        }

        $this->checkPersistenceStatus($sourceEntity, $targetEntities);
        $property = $this->getProperty();

        $links = $this->collectJointEntities($sourceEntity, $targetEntities);
        $return = $this->junction()->deleteMany($links, $options);
        if ($return === false) {
            return false;
        }

        /** @var array<\Cake\Datasource\EntityInterface> $existing */
        $existing = $sourceEntity->get($property) ?: [];
        if (!$options['cleanProperty'] || empty($existing)) {
            return true;
        }

        /** @var \SplObjectStorage<\Cake\Datasource\EntityInterface, null> $storage */
        $storage = new SplObjectStorage();
        foreach ($targetEntities as $e) {
            $storage->offsetSet($e);
        }

        foreach ($existing as $k => $e) {
            if ($storage->offsetExists($e)) {
                unset($existing[$k]);
            }
        }

        $sourceEntity->set($property, array_values($existing));
        $sourceEntity->setDirty($property, false);

        return true;
    }

    /**
     * Replaces the source link set with the given target documents.
     *
     * For the junction-collection shape this delegates to {@see replaceLinks()}.
     *
     * @param \Cake\Datasource\EntityInterface $sourceEntity The source document.
     * @param array<int, mixed> $targetEntities Target documents to keep.
     * @param array<string, mixed> $options Save options.
     * @return bool
     */
    public function replace(EntityInterface $sourceEntity, array $targetEntities, array $options = []): bool
    {
        return $this->replaceLinks($sourceEntity, $targetEntities, $options);
    }

    /**
     * Replaces the source link set with the given target documents.
     *
     * @param \Cake\Datasource\EntityInterface $sourceEntity The source document.
     * @param array<int, mixed> $targetEntities Target documents to keep.
     * @param array<string, mixed> $options Save options.
     * @return bool
     */
    public function replaceLinks(EntityInterface $sourceEntity, array $targetEntities, array $options = []): bool
    {
        $bindingKey = (array)$this->getBindingKey();
        $primaryValue = $sourceEntity->extract($bindingKey);

        if (count(array_filter($primaryValue, static fn(mixed $v): bool => $v !== null)) !== count($bindingKey)) {
            throw new InvalidArgumentException('Could not find primary key value for source entity');
        }

        $connection = $this->junction()->getConnection();
        assert($connection instanceof Connection);

        return $connection->transactional(
            function () use ($sourceEntity, $targetEntities, $primaryValue, $options): bool {
                $junction = $this->junction();
                $target = $this->getTarget();

                /** @var array<string> $foreignKey */
                $foreignKey = array_values(array_filter((array)$this->getForeignKey(), is_string(...)));
                $assocForeignKey = array_values(array_filter(
                    (array)$junction->getAssociation($target->getAlias())->getForeignKey(),
                    is_string(...),
                ));

                $existing = $this->findExistingLinks($junction, $foreignKey, $assocForeignKey, $primaryValue);
                $jointEntities = $this->collectJointEntities($sourceEntity, $targetEntities);
                [$inserts, $deletes] = $this->diffLinks($existing, $jointEntities, $targetEntities);

                if ($inserts && !$this->saveTarget($sourceEntity, $inserts, $options)) {
                    return false;
                }

                foreach ($deletes as $document) {
                    if (!$junction->delete($document, $options) && !empty($options['atomic'])) {
                        return false;
                    }
                }

                $property = $this->getProperty();

                if ($inserts !== []) {
                    $inserted = array_combine(
                        array_keys($inserts),
                        (array)$sourceEntity->get($property),
                    );
                    $targetEntities = $inserted + $targetEntities;
                }

                ksort($targetEntities);
                $sourceEntity->set($property, array_values($targetEntities));
                $sourceEntity->setDirty($property, false);

                return true;
            },
        );
    }

    /**
     * Throws an exception should any of the passed entities is not persisted.
     *
     * @param \Cake\Datasource\EntityInterface $sourceEntity the row belonging to the `source` side
     *   of this association
     * @param array<\Cake\Datasource\EntityInterface> $targetEntities list of entities belonging to the `target` side
     *   of this association
     * @return void
     * @throws \InvalidArgumentException
     */
    protected function checkPersistenceStatus(EntityInterface $sourceEntity, array $targetEntities): void
    {
        if ($sourceEntity->isNew()) {
            throw new InvalidArgumentException('Source entity needs to be persisted before links can be created or removed.');
        }

        foreach ($targetEntities as $document) {
            if ($document->isNew()) {
                throw new InvalidArgumentException('Cannot link entities that have not been persisted yet.');
            }
        }
    }

    /**
     * Creates links between the source entity and each of the passed target entities.
     *
     * @param \Cake\Datasource\EntityInterface $sourceEntity the entity from source collection in this
     *   association
     * @param array<\Cake\Datasource\EntityInterface> $targetEntities list of entities to link to the source entity
     * @param array<string, mixed> $options list of options accepted by `BaseCollection::save()`
     * @return bool success
     */
    protected function saveLinks(EntityInterface $sourceEntity, array $targetEntities, array $options): bool
    {
        $target = $this->getTarget();
        $junction = $this->junction();
        $entityClass = $junction->getDocumentClass();
        $belongsTo = $junction->getAssociation($target->getAlias());
        /** @var array<string> $foreignKey */
        $foreignKey = (array)$this->getForeignKey();
        /** @var array<string> $assocForeignKey */
        $assocForeignKey = (array)$belongsTo->getForeignKey();
        $targetBindingKey = (array)$belongsTo->getBindingKey();
        $bindingKey = (array)$this->getBindingKey();
        $jointProperty = $this->junctionProperty;
        $junctionRegistryAlias = $junction->getRegistryAlias();

        foreach ($targetEntities as $e) {
            $joint = $e->get($jointProperty);
            if (!($joint instanceof EntityInterface)) {
                $joint = new $entityClass([], ['markNew' => true, 'source' => $junctionRegistryAlias]);
            }

            $sourceKeys = array_combine($foreignKey, $sourceEntity->extract($bindingKey));
            $targetKeys = array_combine($assocForeignKey, $e->extract($targetBindingKey));

            $changedKeys = $this->associationKeysDiffer($sourceKeys, $joint->extract($foreignKey)) ||
                $this->associationKeysDiffer($targetKeys, $joint->extract($assocForeignKey));

            if ($changedKeys) {
                $joint->setNew(true);
                $joint->unset($junction->getPrimaryKey());
                $joint->patch(array_merge($sourceKeys, $targetKeys), ['guard' => false]);
            }

            // A hydrated `_joinData` from `groupResult` has no junction primary
            // key (`_id` is stripped from `_joinData` for cake parity), so the
            // link must be inserted, not updated.
            if (!$joint->isNew() && !$joint->has($junction->getPrimaryKey())) {
                $joint->setNew(true);
            }

            $saved = $junction->save($joint, $options);

            if (!$saved && !empty($options['atomic'])) {
                return false;
            }

            $e->set($jointProperty, $joint);
            $e->setDirty($jointProperty, false);
        }

        return true;
    }

    /**
     * Returns whether two association key maps differ.
     *
     * ObjectId values are compared by their hex string so two instances of the
     * same identifier are treated as equal. Mirrors cake60 `diffLinks()` object
     * equality (`==`) for value objects, which PHP `!==` on arrays does not.
     *
     * @param array<string, mixed> $left Left-hand key map.
     * @param array<string, mixed> $right Right-hand key map.
     * @return bool
     */
    protected function associationKeysDiffer(array $left, array $right): bool
    {
        foreach ($left as $field => $value) {
            if (!$this->associationKeyEquals($value, $right[$field] ?? null)) {
                return true;
            }
        }

        foreach ($right as $field => $value) {
            if (!array_key_exists($field, $left) && !$this->associationKeyEquals(null, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns whether two association key values are equal.
     *
     * @param mixed $left Left-hand value.
     * @param mixed $right Right-hand value.
     * @return bool
     */
    protected function associationKeyEquals(mixed $left, mixed $right): bool
    {
        if ($left instanceof ObjectId || $right instanceof ObjectId) {
            return (string)$left === (string)$right;
        }

        if (is_object($left) && is_object($right)) {
            return $left == $right;
        }

        return $left === $right;
    }

    /**
     * Returns the list of joint entities that exist between the source entity
     * and each of the passed target entities.
     *
     * @param \Cake\Datasource\EntityInterface $sourceEntity The row belonging to the source side
     *   of this association.
     * @param array<int, mixed> $targetEntities The rows belonging to the target side of this
     *   association.
     * @return array<\Cake\Datasource\EntityInterface>
     */
    protected function collectJointEntities(EntityInterface $sourceEntity, array $targetEntities): array
    {
        $target = $this->getTarget();
        $source = $this->getSource();
        $junction = $this->junction();
        $jointProperty = $this->junctionProperty;
        $primary = (array)$target->getPrimaryKey();

        $result = [];
        $missing = [];

        foreach ($targetEntities as $document) {
            if (!($document instanceof EntityInterface)) {
                continue;
            }

            $joint = $document->get($jointProperty);

            // `_joinData` hydration drops the junction `_id` (G9), so a joint
            // without its primary key must be re-fetched by foreign keys before
            // it can be deleted.
            $junctionKey = $junction->getPrimaryKey();
            $junctionKey = is_array($junctionKey) ? ($junctionKey[0] ?? null) : $junctionKey;
            if (!($joint instanceof EntityInterface) || ($junctionKey !== null && !$joint->has($junctionKey))) {
                $missing[] = $document->extract($primary);
                continue;
            }

            $result[] = $joint;
        }

        if ($missing === []) {
            return $result;
        }

        $belongsTo = $junction->getAssociation($target->getAlias());
        $hasMany = $source->getAssociation($junction->getAlias());
        /** @var array<string> $foreignKey */
        $foreignKey = (array)$this->getForeignKey();
        /** @var array<string> $assocForeignKey */
        $assocForeignKey = (array)$belongsTo->getForeignKey();
        $sourceKey = $sourceEntity->extract((array)$source->getPrimaryKey());

        $conditions = [];
        foreach ($missing as $key) {
            $conditions[] = array_combine(
                $assocForeignKey,
                array_values($key),
            );
        }

        $found = $hasMany->find()
            ->where(array_combine($foreignKey, $sourceKey))
            ->where(['OR' => $conditions])
            ->toArray();

        return array_merge($result, $found);
    }

    /**
     * Finds the existing junction links for a source entity.
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $junction The junction collection.
     * @param array<string> $foreignKey The source-side foreign key fields.
     * @param array<string> $assocForeignKey The target-side foreign key fields.
     * @param array<int|string, mixed> $primaryValue The source primary key values.
     * @return array<\Cake\Datasource\EntityInterface>
     */
    protected function findExistingLinks(BaseCollection $junction, array $foreignKey, array $assocForeignKey, array $primaryValue): array
    {
        $conditions = array_combine($foreignKey, $primaryValue);
        $conditions += $this->junctionConditionFilter();

        $links = $junction->find()
            ->where($conditions)
            ->toArray();

        if ($links === []) {
            return [];
        }

        $target = $this->getTarget();
        $targetBindingKey = (array)$junction->getAssociation($target->getAlias())->getBindingKey();
        $targetIds = [];
        foreach ($links as $link) {
            $values = $link->extract($assocForeignKey);
            foreach ((array)$values as $value) {
                if ($value !== null) {
                    $targetIds[(string)$value] = $value;
                }
            }
        }

        if ($targetIds === []) {
            return [];
        }

        $matching = $this->find()
            ->where([$this->fieldName($targetBindingKey) . ' IN' => array_values($targetIds)])
            ->all();

        $matchedKeys = [];
        $targetBindingField = $targetBindingKey[0] ?? '_id';
        foreach ($matching as $row) {
            $id = $row instanceof EntityInterface
                ? $row->get($targetBindingField)
                : ($row[$targetBindingField] ?? null);
            if ($id !== null) {
                $matchedKeys[(string)$id] = true;
            }
        }

        return array_values(array_filter(
            $links,
            fn(EntityInterface $link): bool => !empty($matchedKeys[(string)(array_values($link->extract($assocForeignKey))[0] ?? null)]),
        ));
    }

    /**
     * Computes the difference between existing links and the joint entities to keep.
     *
     * Inserts are saved before deletes so a failed target save (domain rules)
     * leaves the previous junction rows in place when Mongo cannot roll back.
     *
     * @param array<\Cake\Datasource\EntityInterface> $existing existing link documents
     * @param array<\Cake\Datasource\EntityInterface> $jointEntities link documents that should be persisted
     * @param array<int, mixed> $targetEntities entities in target collection that are related to
     *   the `$jointEntities`
     * @return array{0: array<int, mixed>, 1: list<\Cake\Datasource\EntityInterface>} Remaining
     *   targets to insert, then junction documents to delete.
     */
    protected function diffLinks(
        array $existing,
        array $jointEntities,
        array $targetEntities,
    ): array {
        $junction = $this->junction();
        $target = $this->getTarget();
        $belongsTo = $junction->getAssociation($target->getAlias());
        /** @var array<string> $foreignKey */
        $foreignKey = (array)$this->getForeignKey();
        /** @var array<string> $assocForeignKey */
        $assocForeignKey = (array)$belongsTo->getForeignKey();

        $keys = array_merge($foreignKey, $assocForeignKey);
        $deletes = [];
        $unmatchedEntityKeys = [];
        $present = [];

        foreach ($jointEntities as $i => $document) {
            $unmatchedEntityKeys[$i] = $document->extract($keys);
            $present[$i] = array_values($document->extract($assocForeignKey));
        }

        foreach ($existing as $existingLink) {
            $existingKeys = $existingLink->extract($keys);
            $found = false;
            foreach ($unmatchedEntityKeys as $i => $unmatchedKeys) {
                $matched = true;
                foreach ($keys as $key) {
                    if ($existingKeys[$key] != $unmatchedKeys[$key]) {
                        $matched = false;
                        break;
                    }
                }

                if ($matched) {
                    unset($unmatchedEntityKeys[$i]);
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $deletes[] = $existingLink;
            }
        }

        $primary = (array)$target->getPrimaryKey();
        $jointProperty = $this->junctionProperty;
        foreach ($targetEntities as $k => $document) {
            if (!($document instanceof EntityInterface)) {
                continue;
            }

            $key = array_values($document->extract($primary));
            foreach ($present as $i => $data) {
                if ($key === $data && !$document->get($jointProperty)) {
                    unset($targetEntities[$k], $present[$i]);
                    break;
                }
            }
        }

        return [$targetEntities, $deletes];
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
        $this->junctionCollection = null;

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
     * @param \Crustum\Mongo\ODM\BaseCollection|string|null $collection Junction collection instance or alias.
     * @return \Crustum\Mongo\ODM\BaseCollection
     * @throws \InvalidArgumentException When source and target are the same collection.
     */
    public function junction(BaseCollection|string|null $collection = null): BaseCollection
    {
        if ($collection === null && $this->junctionCollection instanceof BaseCollection) {
            return $this->junctionCollection;
        }

        if (!$collection instanceof BaseCollection) {
            $through = $collection ?? $this->through;
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
        $this->configureJunctionSchema($collection);
        $this->generateSourceAssociations($collection, $this->getSource());
        $this->generateTargetAssociations($collection, $this->getSource(), $this->getTarget());
        $this->generateJunctionAssociations($collection, $this->getSource(), $this->getTarget());

        return $collection;
    }

    /**
     * Ensures junction foreign keys are present on the collection schema.
     *
     * Existing described fields (including extra junction columns such as
     * `foreign_model`) are kept. Only missing `_id` / FK columns are added so
     * ObjectId conversion still applies on write.
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $junction The junction collection.
     * @return void
     */
    protected function configureJunctionSchema(BaseCollection $junction): void
    {
        try {
            $schema = $junction->getSchema();
            $existing = $schema->columns();

            $needed = ['_id' => 'objectid'];
            $sourceKey = $this->getForeignKey();
            if (is_string($sourceKey)) {
                $needed[$sourceKey] = 'objectid';
            }

            $targetKey = $this->getTargetForeignKey();
            if (is_string($targetKey)) {
                $needed[$targetKey] = 'objectid';
            }

            $missing = [];
            foreach ($needed as $name => $type) {
                if (!in_array($name, $existing, true)) {
                    $missing[$name] = $type;
                }
            }

            if ($missing === []) {
                return;
            }

            $fields = [];
            foreach ($existing as $name) {
                $type = $schema->getColumnType($name);
                $fields[$name] = ['type' => $type ?? ($needed[$name] ?? 'string')];
            }

            foreach ($missing as $name => $type) {
                $fields[$name] = ['type' => $type];
            }

            $junction->setSchemaFromArray($fields);
        } catch (Throwable) {
            return;
        }
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
                'The `%s` association on `%s` cannot target the same collection.',
                $this->getName(),
                $source->getAlias(),
            ));
        }

        $collectionName = $this->junctionCollectionName();
        $alias = Inflector::camelize($collectionName);
        $locator = $this->getCollectionLocator();
        $config = [];
        if (!$locator->exists($alias)) {
            $config = ['collection' => $collectionName, 'allowFallbackClass' => true];
        }

        $collection = $locator->get($alias, $config);
        if (!$collection instanceof BaseCollection) {
            throw new InvalidArgumentException(sprintf(
                'Junction collection `%s` did not resolve to a BaseCollection.',
                $alias,
            ));
        }

        $sourceConnection = $this->getSource()->getConnection();
        if ($sourceConnection instanceof Connection && $collection->getConnection() !== $sourceConnection) {
            $collection->setConnection($sourceConnection);
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
            if ($this->getTargetForeignKey() !== $belongsTo->getForeignKey() || $target !== $belongsTo->getTarget()) {
                throw new InvalidArgumentException(sprintf(
                    'The existing `%s` association on `%s` is incompatible with the `%s` association on `%s`.',
                    $tAlias,
                    $junction->getAlias(),
                    $this->getName(),
                    $source->getAlias(),
                ));
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
                'Invalid save strategy `%s`',
                $strategy,
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
     * Proxies a find to the target collection, applying target conditions and,
     * when junction conditions exist, joining through the junction collection.
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

        $query = $this->getTarget()
            ->find($type, ...$args)
            ->where($this->targetConditions());

        if ($this->junctionConditions()) {
            $this->appendJunctionJoin($query);
        }

        return $query;
    }

    /**
     * Returns filtered conditions that reference the target collection.
     *
     * @return mixed Generally an array. If the conditions are not an array,
     *   the association conditions are returned unmodified.
     */
    protected function targetConditions(): mixed
    {
        $conditions = $this->getConditions();
        if (!is_array($conditions)) {
            return $conditions;
        }

        $matching = [];
        $alias = $this->getAlias() . '.';
        foreach ($conditions as $field => $value) {
            if (str_starts_with($field, $alias)) {
                $matching[$field] = $value;
            }
        }

        return $matching;
    }

    /**
     * Returns filtered conditions that specifically reference the junction collection.
     *
     * @return array<string, mixed>
     */
    protected function junctionConditions(): array
    {
        $matching = [];
        $conditions = $this->getConditions();
        if (!is_array($conditions)) {
            return $matching;
        }

        $alias = $this->junction()->getAlias() . '.';
        foreach ($conditions as $field => $value) {
            if (str_starts_with($field, $alias)) {
                $matching[$field] = $value;
            }

            if (QueryBuilder::isLogicalKey((string)$field)) {
                $matching[$field] = is_array($value) && array_is_list($value) === false
                    ? [$value]
                    : $value;
            }
        }

        return $matching;
    }

    /**
     * Appends a `$lookup` through the junction collection and filters the
     * target on junction conditions.
     *
     * @param \Cake\Datasource\QueryInterface $query The target query.
     * @return void
     */
    protected function appendJunctionJoin(QueryInterface $query): void
    {
        if (!$query instanceof SelectQuery) {
            return;
        }

        $junction = $this->junction();
        $target = $this->getTarget();

        $targetForeignKey = $this->targetForeignKey
            ?? $this->junctionJoinForeignKey($junction, $target);
        if ($targetForeignKey === null) {
            return;
        }

        $builder = $this->buildAggregation();
        $join = '_junction';
        $builder
            ->lookup($junction->getCollection())
            ->localField('_id')
            ->foreignField($targetForeignKey)
            ->alias($join);

        $match = [];
        $junctionAlias = $junction->getAlias() . '.';
        foreach ($this->junctionConditions() as $field => $value) {
            $field = (string)$field;
            if (QueryBuilder::isLogicalKey($field) && is_array($value)) {
                $match[$field] = $this->stripJunctionAlias($value, $junctionAlias, $join);
                continue;
            }

            if (str_starts_with($field, $junctionAlias)) {
                $field = $join . '.' . substr($field, strlen($junctionAlias));
            }

            $match[$field] = $value;
        }

        /** @var array<string, mixed> $parsedMatch */
        $parsedMatch = (new QueryBuilder())->parse($match);
        $builder->match($parsedMatch);

        $query->pipeline($builder->getPipeline());
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
            if (QueryBuilder::isLogicalKey((string)$field) && is_array($value)) {
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
     * Recursively replaces a junction alias prefix inside condition groups.
     *
     * @param array<int|string, mixed> $conditions The condition group.
     * @param string $junctionAlias The junction alias prefix.
     * @param string $join The lookup alias.
     * @return array<int|string, mixed>
     */
    protected function stripJunctionAlias(array $conditions, string $junctionAlias, string $join): array
    {
        $stripped = [];
        foreach ($conditions as $field => $value) {
            if (is_array($value) && array_keys($value) !== range(0, count($value) - 1)) {
                $stripped[$field] = $this->stripJunctionAlias($value, $junctionAlias, $join);
                continue;
            }

            if (is_array($value) && array_is_list($value)) {
                $stripped[$field] = array_map(
                    fn(mixed $item): mixed => is_array($item) ? $this->stripJunctionAlias($item, $junctionAlias, $join) : $item,
                    $value,
                );
                continue;
            }

            $field = (string)$field;
            if (str_starts_with($field, $junctionAlias)) {
                $field = $join . '.' . substr($field, strlen($junctionAlias));
            }

            $stripped[$field] = $value;
        }

        return $stripped;
    }

    /**
     * Removes junction-prefixed conditions from an association conditions array.
     *
     * Junction conditions (e.g. `SpecialTags.highlighted`) are handled by the
     * pipeline `$match` on the through lookup; keeping them in the root-level
     * conditions would target a non-existent root field.
     *
     * @param array<int|string, mixed> $conditions The association conditions.
     * @param string $junctionAlias The junction collection alias.
     * @return array<int|string, mixed> The conditions without junction-prefixed entries.
     */
    protected function stripJunctionConditions(array $conditions, string $junctionAlias): array
    {
        $stripped = [];
        $alias = $junctionAlias . '.';
        foreach ($conditions as $field => $value) {
            if (is_string($field) && str_starts_with($field, $alias)) {
                continue;
            }

            if (is_string($field) && QueryBuilder::isLogicalKey($field) && is_array($value)) {
                $nested = $this->stripJunctionConditions($value, $junctionAlias);
                if ($nested !== []) {
                    $stripped[$field] = $nested;
                }

                continue;
            }

            $stripped[$field] = $value;
        }

        return $stripped;
    }

    /**
     * Extracts junction-prefixed conditions from an association conditions array.
     *
     * Complements {@see stripJunctionConditions()}: the junction-prefixed
     * entries are returned for the through `$filter`, the rest stay as target
     * conditions.
     *
     * @param array<int|string, mixed> $conditions The association conditions.
     * @param string $junctionAlias The junction collection alias.
     * @return array<string, mixed> The junction-prefixed conditions.
     */
    protected function extractJunctionConditions(array $conditions, string $junctionAlias): array
    {
        $extracted = [];
        $alias = $junctionAlias . '.';
        foreach ($conditions as $field => $value) {
            if (is_string($field) && str_starts_with($field, $alias)) {
                $extracted[$field] = $value;

                continue;
            }

            if (is_string($field) && QueryBuilder::isLogicalKey($field) && is_array($value)) {
                $nested = $this->extractJunctionConditions($value, $junctionAlias);
                if ($nested !== []) {
                    $extracted[$field] = $nested;
                }
            }
        }

        return $extracted;
    }

    /**
     * Junction conditions with collection aliases stripped for a direct find.
     *
     * `PolymorphicTagged.foreign_model => Articles` becomes
     * `foreign_model => Articles` so replace/unlink only sees matching links.
     *
     * @return array<string, mixed>
     */
    protected function junctionConditionFilter(): array
    {
        $conditions = $this->getConditions();
        if (!is_array($conditions) || $conditions === []) {
            return [];
        }

        $junctionAlias = $this->junction()->getAlias() . '.';
        $extracted = $this->extractJunctionConditions($conditions, $this->junction()->getAlias());
        $filter = [];
        foreach ($extracted as $field => $value) {
            if (!QueryBuilder::isLogicalKey($field)) {
                if (str_starts_with($field, $junctionAlias)) {
                    $field = substr($field, strlen($junctionAlias));
                }

                $filter[$field === 'id' ? '_id' : $field] = $value;
                continue;
            }

            $filter[$field] = $value;
        }

        return $filter;
    }

    /**
     * Builds a `$filter` `cond` expression from junction-prefixed conditions.
     *
     * `SpecialTags.highlighted => true` becomes
     * `{$and: [{$eq: ['$$item.highlighted', true]}]}` so the through rows inside
     * the `_join_<property>` lookup array can be filtered before the target
     * lookup runs. Operators compose through `FunctionsBuilder` (`eq`, `and`,
     * `or`, `not`), never raw arrays.
     *
     * @param array<int|string, mixed> $conditions The junction conditions (alias-prefixed).
     * @param string $junctionAlias The junction alias including the trailing dot.
     * @param \Crustum\Mongo\Database\Aggregation\AggregationBuilder $builder The pipeline builder.
     * @return \Crustum\Mongo\Database\Expression\FunctionExpression
     */
    protected function junctionCondExpression(
        array $conditions,
        string $junctionAlias,
        AggregationBuilder $builder,
    ): FunctionExpression {
        $func = $builder->func();
        $compiler = new QueryBuilder();
        $parts = [];
        foreach ($conditions as $field => $value) {
            $field = (string)$field;
            $upper = strtoupper(ltrim($field, '$'));
            if (QueryBuilder::isLogicalKey($field) && is_array($value)) {
                $parts[] = $this->junctionGroupExpression($upper, $value, $junctionAlias, $builder);
                continue;
            }

            $parts[] = $this->itemCondComparison($compiler, $func, $field, $value, $junctionAlias, false);
        }

        return $parts === [] ? $func->literal(true) : $func->and($parts);
    }

    /**
     * Builds a `$and`/`$or`/`$nor` group expression for junction conditions.
     *
     * @param string $operator The uppercase conjunction name (AND/OR/NOT).
     * @param array<int|string, mixed> $conditions The nested condition group.
     * @param string $junctionAlias The junction alias including the trailing dot.
     * @param \Crustum\Mongo\Database\Aggregation\AggregationBuilder $builder The pipeline builder.
     * @return \Crustum\Mongo\Database\Expression\FunctionExpression
     */
    protected function junctionGroupExpression(
        string $operator,
        array $conditions,
        string $junctionAlias,
        AggregationBuilder $builder,
    ): FunctionExpression {
        $func = $builder->func();
        $compiler = new QueryBuilder();
        $parts = [];
        foreach ($conditions as $field => $value) {
            $field = (string)$field;
            $upper = strtoupper(ltrim($field, '$'));
            if (QueryBuilder::isLogicalKey($field) && is_array($value)) {
                $parts[] = $this->junctionGroupExpression($upper, $value, $junctionAlias, $builder);
                continue;
            }

            $parts[] = $this->itemCondComparison($compiler, $func, $field, $value, $junctionAlias, false);
        }

        return match ($operator) {
            'OR' => $func->or($parts),
            'NOT' => $func->nor($parts),
            default => $func->and($parts),
        };
    }

    /**
     * Builds a `$filter` expression for conditions on the target collection.
     *
     * Containment `conditions` referencing the target (`Tags._id`, ...) filter
     * the joined target array element-wise, mirroring `junctionCondExpression()`
     * but addressing `$$item.<field>` on the target documents.
     *
     * @param array<int|string, mixed> $conditions The target conditions.
     * @param string $targetAlias The target alias including the trailing dot.
     * @param \Crustum\Mongo\Database\Aggregation\AggregationBuilder $builder The pipeline builder.
     * @return \Crustum\Mongo\Database\Expression\FunctionExpression
     */
    protected function targetCondExpression(
        array $conditions,
        string $targetAlias,
        AggregationBuilder $builder,
    ): FunctionExpression {
        $func = $builder->func();
        $compiler = new QueryBuilder();
        $parts = [];
        foreach ($conditions as $field => $value) {
            $field = (string)$field;
            $upper = strtoupper(ltrim($field, '$'));
            if (QueryBuilder::isLogicalKey($field) && is_array($value)) {
                $parts[] = $this->targetGroupExpression($upper, $value, $targetAlias, $builder);
                continue;
            }

            $parts[] = $this->itemCondComparison($compiler, $func, $field, $value, $targetAlias, true);
        }

        return $parts === [] ? $func->literal(true) : $func->and($parts);
    }

    /**
     * Builds a `$and`/`$or`/`$nor` group expression for target conditions.
     *
     * @param string $operator The uppercase conjunction name (AND/OR/NOT).
     * @param array<int|string, mixed> $conditions The nested condition group.
     * @param string $targetAlias The target alias including the trailing dot.
     * @param \Crustum\Mongo\Database\Aggregation\AggregationBuilder $builder The pipeline builder.
     * @return \Crustum\Mongo\Database\Expression\FunctionExpression
     */
    protected function targetGroupExpression(
        string $operator,
        array $conditions,
        string $targetAlias,
        AggregationBuilder $builder,
    ): FunctionExpression {
        $func = $builder->func();
        $compiler = new QueryBuilder();
        $parts = [];
        foreach ($conditions as $field => $value) {
            $field = (string)$field;
            $upper = strtoupper(ltrim($field, '$'));
            if (QueryBuilder::isLogicalKey($field) && is_array($value)) {
                $parts[] = $this->targetGroupExpression($upper, $value, $targetAlias, $builder);
                continue;
            }

            $parts[] = $this->itemCondComparison($compiler, $func, $field, $value, $targetAlias, true);
        }

        return match ($operator) {
            'OR' => $func->or($parts),
            'NOT' => $func->nor($parts),
            default => $func->and($parts),
        };
    }

    /**
     * Compiles one `$filter` comparison through the Database condition parser.
     *
     * @param \Crustum\Mongo\Database\QueryBuilder $compiler The condition compiler.
     * @param \Crustum\Mongo\Database\FunctionsBuilder $func The aggregation functions factory.
     * @param string $field The condition key, possibly alias-prefixed.
     * @param mixed $value The condition value.
     * @param string $aliasPrefix The alias prefix to strip (`Tags.`).
     * @param bool $mapIdToPrimaryKey Whether `id` maps to `_id` and ObjectId-casts.
     * @return \Crustum\Mongo\Database\Expression\FunctionExpression
     */
    protected function itemCondComparison(
        QueryBuilder $compiler,
        FunctionsBuilder $func,
        string $field,
        mixed $value,
        string $aliasPrefix,
        bool $mapIdToPrimaryKey,
    ): FunctionExpression {
        $bareKey = str_starts_with($field, $aliasPrefix) ? substr($field, strlen($aliasPrefix)) : $field;
        [$name] = QueryBuilder::splitConditionKey($bareKey);
        if ($mapIdToPrimaryKey && $name === 'id') {
            $name = '_id';
        }

        if ($mapIdToPrimaryKey && $name === '_id') {
            $value = ObjectIdType::tryFrom($value);
        }

        return $compiler->aggregationCompare($func, '$$item.' . $name, $bareKey, $value);
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
     * Applies target and junction formatters to contained many-to-many rows.
     *
     * Cake's SelectWithPivotLoader runs target finders (including `indexBy`)
     * and junction `beforeFind` formatters on the fetch query. ODM contain
     * uses `$lookup`, so those formatters are applied per parent row here.
     *
     * @param \Crustum\Mongo\ODM\Query\SelectQuery $query The source query.
     * @param \Crustum\Mongo\ODM\Query\SelectQuery $surrogate The target surrogate query.
     * @param array<string, mixed> $options Options including `propertyPath`.
     * @return void
     */
    public function formatAssociationResults(SelectQuery $query, SelectQuery $surrogate, array $options): void
    {
        $property = (string)($options['propertyPath'] ?? '');
        if ($property === '' || str_contains($property, '.')) {
            parent::formatAssociationResults($query, $surrogate, $options);
            $this->formatJunctionAssociationResults($query, $options);

            return;
        }

        $targetFormatters = $surrogate->getResultFormatters();
        $formatterQuery = $options['formatterQuery'] ?? $surrogate;
        if (!$formatterQuery instanceof SelectQuery) {
            $formatterQuery = $surrogate;
        }

        [$junctionFormatters, $junctionQuery] = $this->junctionFormatterContext();
        if ($targetFormatters === [] && $junctionFormatters === []) {
            return;
        }

        $junctionProperty = $this->getJunctionProperty();

        $query->formatResults(
            fn(CollectionInterface $results, SelectQuery $sourceQuery): CollectionInterface => $results->map(function (mixed $row) use (
                $targetFormatters,
                $formatterQuery,
                $junctionFormatters,
                $junctionQuery,
                $property,
                $junctionProperty,
                $sourceQuery,
            ): mixed {
                $nested = $this->readAssociationProperty($row, $property);
                if (!is_array($nested)) {
                    return $row;
                }

                if ($junctionFormatters !== [] && $junctionQuery instanceof SelectQuery) {
                    $this->applyFormattersToJoinData(
                        $nested,
                        $junctionFormatters,
                        $junctionQuery,
                        $sourceQuery,
                        $junctionProperty,
                    );
                }

                if ($targetFormatters === []) {
                    return $row;
                }

                $nested = $this->applyFormattersToList(
                    $nested,
                    $targetFormatters,
                    $formatterQuery,
                    $sourceQuery,
                );
                $row = $this->writeAssociationProperty($row, $property, $nested);
                if ($row instanceof EntityInterface) {
                    $row->clean();
                }

                return $row;
            }),
            SelectQuery::PREPEND,
        );
    }

    /**
     * Builds lookup stages through the junction collection.
     *
     * The junction is resolved through {@see junction()} (either the configured
     * `through` or the conventional `{source}_{target}` collection). Keys are
     * taken from the junction's belongsTo associations when not configured.
     *
     * @param array<string, mixed> $options Pipeline options.
     * @return list<array<string, mixed>>
     */
    public function buildPipeline(array $options = []): array
    {
        $junction = $this->junction();
        $target = $this->getTarget();

        $joinForeignKey = $this->joinForeignKey
            ?? $this->junctionJoinForeignKey($junction, $this->getSource());
        $targetForeignKey = $this->targetForeignKey
            ?? $this->junctionJoinForeignKey($junction, $target);
        if ($joinForeignKey === null || $targetForeignKey === null) {
            return [];
        }

        $targetBindingKey = $this->junctionTargetBindingKey($junction, $target);
        $through = $junction->getCollection();

        $builder = $this->buildAggregation();
        $join = $options['lookupJoinAlias'] ?? '_join_' . $this->getProperty();
        $lookupAlias = $options['lookupAlias'] ?? $this->getProperty();
        $localKey = $this->fieldName($this->getBindingKey());
        if (!empty($options['lookupPrefix'])) {
            $localKey = $options['lookupPrefix'] . '.' . $localKey;
        }

        $builder
            ->lookup($through)
            ->localField($localKey)
            ->foreignField($joinForeignKey)
            ->alias($join);

        $negateMatch = !empty($options['negateMatch']);
        $leftJoinMatch = !empty($options['matching'])
            && strtoupper((string)($options['joinType'] ?? $this->getJoinType())) === 'LEFT';
        $deferNegateMatch = !empty($options['deferNegateMatch']);
        $pipelineOptions = $options + $this->associationPipelineOptions();

        $junctionConditions = [];
        if (!empty($pipelineOptions['conditions']) && is_array($pipelineOptions['conditions'])) {
            $junctionConditions = $this->extractJunctionConditions($pipelineOptions['conditions'], $junction->getAlias());
            $pipelineOptions['conditions'] = $this->stripJunctionConditions(
                $pipelineOptions['conditions'],
                $junction->getAlias(),
            );
        }

        if ($junctionConditions !== []) {
            $junctionAlias = $junction->getAlias() . '.';
            $builder->addFields()->field(
                $join,
                $builder->func()->filter(
                    '$' . $join,
                    'item',
                    $this->junctionCondExpression($junctionConditions, $junctionAlias, $builder),
                ),
            );
        }

        $targetConditions = $pipelineOptions['conditions'] ?? [];

        $lookupTags = $builder
            ->lookup($target->getCollection())
            ->localField($join . '.' . $targetForeignKey)
            ->foreignField($targetBindingKey)
            ->alias($lookupAlias);
        if (($negateMatch || $leftJoinMatch) && is_array($targetConditions) && $targetConditions !== []) {
            $lookupTags->pipeline(function (AggregationBuilder $sub) use ($targetConditions): void {
                $sub->match($this->normalizePipelineConditions($targetConditions));
            });
        }

        if (!empty($options['matching'])) {
            $builder->unwind('$' . $lookupAlias, [
                'preserveNullAndEmptyArrays' => $this->unwindPreservesNull($options),
            ]);
        }

        $pipelineFields = $pipelineOptions['fields'] ?? null;
        if (empty($options['matching'])) {
            unset($pipelineOptions['fields']);
        }

        if ($negateMatch && !$deferNegateMatch) {
            unset($pipelineOptions['conditions']);
            $this->applyPipelineOptions($builder, $pipelineOptions);
            $builder->match([$lookupAlias => null]);
        } else {
            $targetConditions = $pipelineOptions['conditions'] ?? [];
            if (empty($options['matching']) && is_array($targetConditions) && $targetConditions !== []) {
                $property = $this->getProperty();
                $targetAlias = $target->getAlias() . '.';
                $builder->addFields()->field(
                    $property,
                    $builder->func()->filter(
                        '$' . $property,
                        'item',
                        $this->targetCondExpression($targetConditions, $targetAlias, $builder),
                    ),
                );
                unset($pipelineOptions['conditions']);
            }

            if (!empty($options['matching']) && !empty($pipelineOptions['conditions']) && !$leftJoinMatch) {
                $property = $this->getProperty();
                $pipelineOptions['conditions'] = $this->prefixMatchConditions(
                    $pipelineOptions['conditions'],
                    $property,
                );
            }

            if ($leftJoinMatch) {
                unset($pipelineOptions['conditions']);
            }

            $this->applyPipelineOptions($builder, $pipelineOptions);
        }

        $this->applyAssociationSort($builder);
        $this->applyFinderConditions($builder);
        if (empty($options['matching'])) {
            $this->applyFieldsProjection($builder, $pipelineFields);
        }

        return $builder->getPipeline();
    }

    /**
     * Projects the loaded target array onto the requested fields.
     *
     * Containment `fields`/`select` options are applied to each element of the
     * loaded property array via a `$map` expression.
     *
     * @param \Crustum\Mongo\Database\Aggregation\AggregationBuilder $builder The pipeline builder.
     * @param list<string>|array<string, mixed>|null $fields Containment fields/select options.
     * @return void
     */
    protected function applyFieldsProjection(AggregationBuilder $builder, mixed $fields): void
    {
        if (in_array($fields, [null, [], false], true)) {
            return;
        }

        $fields = (array)$fields;
        if (array_is_list($fields)) {
            $fields = array_fill_keys($fields, 1);
        }

        $alias = $this->getTarget()->getAlias();
        $projection = [];
        foreach ($fields as $field => $value) {
            if (is_int($field)) {
                $field = (string)$value;
                $value = 1;
            }

            if (str_starts_with($field, $alias . '.')) {
                $field = substr($field, strlen($alias) + 1);
            }

            if (is_array($value)) {
                $projection[$field] = $value;

                continue;
            }

            if ((int)$value === 0) {
                continue;
            }

            $projection[$field] = '$$item.' . $field;
        }

        $property = $this->getProperty();
        $builder->addFields()->field(
            $property,
            $builder->func()->map('$' . $property, 'item', $projection),
        );
    }

    /**
     * Applies the association finder's conditions to the loaded target array.
     *
     * The finder is executed on a scratch target query and its compiled filter
     * is applied as a `$match` on the loaded property array.
     *
     * @param \Crustum\Mongo\Database\Aggregation\AggregationBuilder $builder The pipeline builder.
     * @return void
     */
    protected function applyFinderConditions(AggregationBuilder $builder): void
    {
        $finder = $this->getFinder();
        if (is_array($finder)) {
            [$finder, $opts] = $this->extractFinder($finder);
        }

        if (!$finder || $finder === 'all') {
            return;
        }

        $query = $this->getTarget()->find($finder, ...(is_array($opts ?? null) ? $opts : []));
        $compiled = $query->compile();
        $filter = $compiled['filter'] ?? [];
        if ($filter === []) {
            return;
        }

        $property = $this->getProperty();
        $builder->addFields()->field(
            $property,
            $builder->func()->filter(
                '$' . $property,
                'item',
                $this->finderFilterExpression($filter, '$$item', $builder),
            ),
        );
    }

    /**
     * Converts a compiled finder filter into an `$expr`-style condition expression.
     *
     * @param array<string, mixed> $filter The Mongo filter.
     * @param string $var The item variable prefix (`$$item`).
     * @param \Crustum\Mongo\Database\Aggregation\AggregationBuilder $builder The pipeline builder.
     * @return \Crustum\Mongo\Database\Expression\FunctionExpression
     */
    protected function finderFilterExpression(
        array $filter,
        string $var,
        AggregationBuilder $builder,
    ): FunctionExpression {
        $func = $builder->func();
        $expr = [];
        foreach ($filter as $field => $value) {
            if (QueryBuilder::isLogicalKey((string)$field) && strtoupper(ltrim((string)$field, '$')) === 'AND' && is_array($value)) {
                foreach ($value as $nested) {
                    if (is_array($nested)) {
                        $expr[] = $this->finderFilterExpression($nested, $var, $builder);
                    }
                }

                continue;
            }

            if (str_starts_with((string)$field, '$')) {
                continue;
            }

            $expr[] = $func->eq($var . '.' . $field, $value);
        }

        return $expr === [] ? $func->literal(true) : $func->and($expr);
    }

    /**
     * Sorts the loaded target array using `$sortArray`.
     *
     * A regular `$sort` sorts the top-level documents, not the in-array lookup
     * results, so the configured association sort is applied to the loaded
     * property array directly.
     *
     * @param \Crustum\Mongo\Database\Aggregation\AggregationBuilder $builder The pipeline builder.
     * @return void
     */
    protected function applyAssociationSort(AggregationBuilder $builder): void
    {
        $sort = $this->getSort();
        if ($sort === null) {
            return;
        }

        $normalized = $this->normalizeSort($sort);
        if ($normalized === []) {
            return;
        }

        $alias = $this->getTarget()->getAlias();
        $stripped = [];
        foreach ($normalized as $field => $direction) {
            if (str_starts_with((string)$field, $alias . '.')) {
                $field = substr((string)$field, strlen($alias) + 1);
            }

            $stripped[(string)$field] = $direction;
        }

        $property = $this->getProperty();
        $builder->addFields()->field(
            $property,
            $builder->func()->sortArray('$' . $property, $stripped),
        );
    }

    /**
     * Collects pipeline options configured on the association itself.
     *
     * `conditions`, `sort`, `fields`, `limit` and `skip` set through the
     * association (not the containment array) apply to the loaded targets.
     *
     * @return array<string, mixed>
     */
    protected function associationPipelineOptions(): array
    {
        $options = [];
        $conditions = $this->getConditions();
        if ($conditions !== []) {
            $options['conditions'] = $conditions;
        }

        $sort = $this->getSort();
        if ($sort !== null) {
            $options['sort'] = $sort;
        }

        return $options;
    }

    /**
     * Resolves the junction foreign key for one side of the link.
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $junction The junction collection.
     * @param \Crustum\Mongo\ODM\BaseCollection $side The source or target collection.
     * @return string|null
     */
    protected function junctionJoinForeignKey(BaseCollection $junction, BaseCollection $side): ?string
    {
        $association = $junction->getAssociation($side->getAlias());

        $key = $association->getForeignKey();
        if (is_array($key)) {
            return $key[0] ?? null;
        }

        return $key === false ? null : $key;
    }

    /**
     * Resolves the target binding key used by the junction's belongsTo association.
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $junction The junction collection.
     * @param \Crustum\Mongo\ODM\BaseCollection $target The target collection.
     * @return string
     */
    protected function junctionTargetBindingKey(BaseCollection $junction, BaseCollection $target): string
    {
        $association = $junction->getAssociation($target->getAlias());
        $key = $association->getBindingKey();
        if (is_string($key) && $key !== '') {
            return $key;
        }

        return '_id';
    }

    /**
     * Applies junction `beforeFind` formatters using a dotted property path.
     *
     * Used when the containment is nested and target formatters fall back to
     * the generic extract/insert path.
     *
     * @param \Crustum\Mongo\ODM\Query\SelectQuery $query The source query.
     * @param array<string, mixed> $options Options including `propertyPath`.
     * @return void
     */
    protected function formatJunctionAssociationResults(SelectQuery $query, array $options): void
    {
        $property = (string)($options['propertyPath'] ?? '');
        if ($property === '') {
            return;
        }

        [$junctionFormatters, $junctionQuery] = $this->junctionFormatterContext();
        if ($junctionFormatters === [] || !$junctionQuery instanceof SelectQuery) {
            return;
        }

        $propertyPath = explode('.', $property);
        $junctionProperty = $this->getJunctionProperty();

        $query->formatResults(
            fn(CollectionInterface $results, SelectQuery $sourceQuery): CollectionInterface => $results->map(function (mixed $row) use (
                $junctionFormatters,
                $junctionQuery,
                $propertyPath,
                $junctionProperty,
                $sourceQuery,
            ): mixed {
                $nested = $row;
                foreach ($propertyPath as $segment) {
                    $nested = $this->readAssociationProperty($nested, $segment);
                    if ($nested === null) {
                        return $row;
                    }
                }

                if (is_array($nested)) {
                    $this->applyFormattersToJoinData(
                        $nested,
                        $junctionFormatters,
                        $junctionQuery,
                        $sourceQuery,
                        $junctionProperty,
                    );
                }

                return $row;
            }),
            SelectQuery::PREPEND,
        );
    }

    /**
     * Builds a junction query and collects `beforeFind` formatters.
     *
     * @return array{0: array<int, callable>, 1: \Crustum\Mongo\ODM\Query\SelectQuery|null}
     */
    protected function junctionFormatterContext(): array
    {
        $surrogate = $this->junction()->find();

        $surrogate->eagerLoaded(true);
        $surrogate->triggerBeforeFind();

        return [$surrogate->getResultFormatters(), $surrogate];
    }

    /**
     * Runs formatters against `_joinData` on each associated target.
     *
     * @param array<array-key, mixed> $tags Associated target rows.
     * @param array<int, callable> $formatters Junction result formatters.
     * @param \Crustum\Mongo\ODM\Query\SelectQuery $formatterQuery Query passed to formatters.
     * @param \Crustum\Mongo\ODM\Query\SelectQuery $sourceQuery The source query.
     * @param string $junctionProperty Junction property name.
     * @return void
     */
    protected function applyFormattersToJoinData(
        array $tags,
        array $formatters,
        SelectQuery $formatterQuery,
        SelectQuery $sourceQuery,
        string $junctionProperty,
    ): void {
        $joinDocs = [];
        foreach ($tags as $i => $tag) {
            $join = $this->readAssociationProperty($tag, $junctionProperty);
            if ($join !== null) {
                $joinDocs[$i] = $join;
            }
        }

        if ($joinDocs === []) {
            return;
        }

        $formatted = $this->applyFormattersToList(
            $joinDocs,
            $formatters,
            $formatterQuery,
            $sourceQuery,
        );
        foreach ($formatted as $i => $join) {
            if (!isset($tags[$i])) {
                continue;
            }

            $this->writeAssociationProperty($tags[$i], $junctionProperty, $join);
        }
    }

    /**
     * Applies result formatters to a list of associated rows.
     *
     * @param array<array-key, mixed> $items Associated rows.
     * @param array<int, callable> $formatters Result formatters.
     * @param \Crustum\Mongo\ODM\Query\SelectQuery $formatterQuery Query passed to formatters.
     * @param \Crustum\Mongo\ODM\Query\SelectQuery $sourceQuery The source query.
     * @return array<array-key, mixed>
     */
    protected function applyFormattersToList(
        array $items,
        array $formatters,
        SelectQuery $formatterQuery,
        SelectQuery $sourceQuery,
    ): array {
        $extracted = $sourceQuery->resultSetFactory()->createResultSet($items);
        $resultSetClass = $sourceQuery->resultSetFactory()->getResultSetClass();
        foreach ($formatters as $callable) {
            $extracted = $callable($extracted, $formatterQuery);
            if (!$extracted instanceof ResultSetInterface) {
                $extracted = new $resultSetClass($extracted);
            }
        }

        return iterator_to_array($extracted);
    }

    /**
     * Reads an association property from an entity or array row.
     *
     * @param mixed $row The row.
     * @param string $property Property name.
     * @return mixed
     */
    protected function readAssociationProperty(mixed $row, string $property): mixed
    {
        if ($row instanceof EntityInterface) {
            return $row->get($property);
        }

        if (is_array($row)) {
            return $row[$property] ?? null;
        }

        return null;
    }

    /**
     * Writes an association property onto an entity or array row.
     *
     * @param mixed $row The row.
     * @param string $property Property name.
     * @param mixed $value The value to assign.
     * @return mixed
     */
    protected function writeAssociationProperty(mixed $row, string $property, mixed $value): mixed
    {
        if ($row instanceof EntityInterface) {
            $row->set($property, $value, ['guard' => false]);
            $row->setDirty($property, false);

            return $row;
        }

        if (is_array($row)) {
            $row[$property] = $value;
        }

        return $row;
    }
}

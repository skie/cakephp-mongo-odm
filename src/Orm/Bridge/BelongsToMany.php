<?php
declare(strict_types=1);

namespace Crustum\Mongo\Orm\Bridge;

use Cake\Datasource\EntityInterface;
use Cake\ORM\Table;
use Cake\Utility\Inflector;
use Crustum\Mongo\ODM\BaseCollection;
use InvalidArgumentException;
use function Cake\Core\pluginSplit;

/**
 * BelongsToMany cross-boundary association (Direction 1).
 *
 * N:N between a SQL source row and Mongo target documents, supported by two
 * pivot designs (see doc 29 §5.4):
 *
 * - **junction** (default): a junction collection `{source}_{target}` holding
 *   `{source}_id` / `{target}_id` links. Load resolves the junction rows for
 *   the source keys, then fetches the target documents in one batched query.
 * - **array** pivot: each target document carries a `{source}_ids` array of
 *   source primary keys; load matches on the array containing any source key.
 *
 * @see docs/reference/29-orm-mongo-association-bridge.md §5.4
 */
class BelongsToMany extends Association
{
    /**
     * Junction-collection pivot.
     *
     * @var string
     */
    public const string PIVOT_JUNCTION = 'junction';

    /**
     * In-document `*_ids` array pivot.
     *
     * @var string
     */
    public const string PIVOT_ARRAY = 'array';

    /**
     * Pivot mode: `junction` or `array`.
     *
     * @var string
     */
    protected string $pivot;

    /**
     * Junction collection alias or instance.
     *
     * @var \Crustum\Mongo\ODM\BaseCollection|string|null
     */
    protected BaseCollection|string|null $through = null;

    /**
     * Explicit junction collection alias.
     *
     * @var string|null
     */
    protected ?string $junctionCollectionName = null;

    /**
     * Target-side link field on the junction collection (default `{target}_id`).
     *
     * @var string|null
     */
    protected ?string $targetForeignKey = null;

    /**
     * Source-side link field on the junction collection (default `{source}_id`).
     *
     * @var string|null
     */
    protected ?string $sourceForeignKey = null;

    /**
     * @inheritDoc
     */
    public function __construct(string $name, Table $source, array $options = [])
    {
        $this->pivot = (string)($options['pivot'] ?? self::PIVOT_JUNCTION);
        $this->through = $options['through'] ?? null;
        $this->junctionCollectionName = isset($options['junctionCollection'])
            ? (string)$options['junctionCollection']
            : null;
        $this->targetForeignKey = isset($options['targetForeignKey'])
            ? (string)$options['targetForeignKey']
            : null;
        $this->sourceForeignKey = isset($options['sourceForeignKey'])
            ? (string)$options['sourceForeignKey']
            : null;

        parent::__construct($name, $source, $options);
    }

    /**
     * @inheritDoc
     */
    public function loadByKeys(array $keys): array
    {
        return $this->pivot === self::PIVOT_ARRAY
            ? $this->loadByArrayPivot($keys)
            : $this->loadByJunction($keys);
    }

    /**
     * Loads via the junction collection in two batched queries.
     *
     * @param list<int|string> $keys Source key values.
     * @return array<string, list<\Cake\Datasource\EntityInterface>>
     */
    protected function loadByJunction(array $keys): array
    {
        $junction = $this->getJunction();

        $links = $junction->find()
            ->where([$this->getSourceForeignKey() . ' IN' => $keys])
            ->toArray();

        $targetIds = [];
        foreach ($links as $link) {
            $value = $link instanceof EntityInterface
                ? $link->get($this->getTargetForeignKey())
                : ($link[$this->getTargetForeignKey()] ?? null);
            if ($value !== null) {
                $targetIds[(string)$value] = $value;
            }
        }

        $targets = $targetIds === []
            ? []
            : $this->getTarget()->find()
                ->where(['_id IN' => array_values($targetIds)])
                ->toArray();

        $targetMap = [];
        foreach ($targets as $target) {
            $key = $target instanceof EntityInterface
                ? $target->get('_id')
                : ($target['_id'] ?? null);
            if ($key !== null) {
                $targetMap[(string)$key] = $target;
            }
        }

        $result = [];
        foreach ($links as $link) {
            $sourceValue = $link instanceof EntityInterface
                ? $link->get($this->getSourceForeignKey())
                : ($link[$this->getSourceForeignKey()] ?? null);
            if ($sourceValue === null) {
                continue;
            }
            $targetKey = $link instanceof EntityInterface
                ? $link->get($this->getTargetForeignKey())
                : ($link[$this->getTargetForeignKey()] ?? null);
            if ($targetKey !== null && isset($targetMap[(string)$targetKey])) {
                $result[(string)$sourceValue][] = $targetMap[(string)$targetKey];
            }
        }

        return $result;
    }

    /**
     * Loads via in-document `*_ids` arrays on the target documents.
     *
     * @param list<int|string> $keys Source key values.
     * @return array<string, list<\Cake\Datasource\EntityInterface>>
     */
    protected function loadByArrayPivot(array $keys): array
    {
        $arrayField = $this->arrayPivotField();

        $targets = $this->getTarget()->find()
            ->where([$arrayField . ' IN' => $keys])
            ->toArray();

        $result = [];
        foreach ($targets as $target) {
            $ids = $target instanceof EntityInterface
                ? $target->get($arrayField)
                : ($target[$arrayField] ?? []);
            foreach ((array)$ids as $id) {
                if (in_array((string)$id, array_map('strval', $keys), true)) {
                    $result[(string)$id][] = $target;
                }
            }
        }

        return $result;
    }

    /**
     * Gets the junction collection name (configured or conventional).
     *
     * @return string
     */
    public function getJunctionCollectionName(): string
    {
        if ($this->through instanceof BaseCollection) {
            return $this->through->getCollection();
        }

        return $this->through !== null
            ? (string)$this->through
            : ($this->junctionCollectionName ?? $this->defaultJunctionName());
    }

    /**
     * Gets the source-side junction link field.
     *
     * @return string
     */
    public function getSourceForeignKey(): string
    {
        return $this->sourceForeignKey ??= $this->defaultSourceForeignKey();
    }

    /**
     * Gets the target-side junction link field.
     *
     * @return string
     */
    public function getTargetForeignKey(): string
    {
        return $this->targetForeignKey ??= $this->defaultTargetForeignKey();
    }

    /**
     * Resolves the junction collection.
     *
     * @return \Crustum\Mongo\ODM\BaseCollection
     */
    protected function getJunction(): BaseCollection
    {
        if ($this->through instanceof BaseCollection) {
            return $this->through;
        }

        $alias = $this->getJunctionCollectionName();

        $collection = $this->source instanceof MongoCollectionAwareInterface
            ? $this->source->getMongoCollection($alias)
            : null;
        if (!$collection instanceof BaseCollection) {
            throw new InvalidArgumentException(sprintf(
                'Cannot resolve junction collection `%s` for bridge BelongsToMany `%s`.',
                $alias,
                $this->getName(),
            ));
        }

        return $collection;
    }

    /**
     * Default junction collection name: sorted `{source}_{target}` collection names.
     *
     * @return string
     */
    protected function defaultJunctionName(): string
    {
        $names = array_map(
            Inflector::underscore(...),
            [$this->getSource()->getTable(), $this->getTarget()->getCollection()],
        );
        sort($names);

        return implode('_', $names);
    }

    /**
     * Default source-side junction link field.
     *
     * @return string
     */
    protected function defaultSourceForeignKey(): string
    {
        return $this->modelKey($this->getSource()->getTable());
    }

    /**
     * Default target-side junction link field.
     *
     * @return string
     */
    protected function defaultTargetForeignKey(): string
    {
        return $this->modelKey($this->getTarget()->getAlias());
    }

    /**
     * Array pivot field on the target document (`{source}_ids`).
     *
     * @return string
     */
    protected function arrayPivotField(): string
    {
        [, $table] = pluginSplit($this->getSource()->getTable());

        return Inflector::underscore(Inflector::singularize($table)) . '_ids';
    }

    /**
     * @inheritDoc
     */
    protected function sourceKeyField(): string
    {
        return $this->bindingKey();
    }

    /**
     * @inheritDoc
     */
    protected function emptyValue(): mixed
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function cascadeDelete(EntityInterface $entity, array $options = []): bool
    {
        if (!$this->getDependent()) {
            return true;
        }

        $sourceKey = $entity->get($this->bindingKey());
        if ($sourceKey === null) {
            return true;
        }

        if ($this->pivot === self::PIVOT_ARRAY) {
            $this->getTarget()->updateQuery()
                ->pull($this->arrayPivotField(), $sourceKey)
                ->where([$this->arrayPivotField() => $sourceKey])
                ->execute();

            return true;
        }

        $this->getJunction()->deleteAll([$this->getSourceForeignKey() => $sourceKey]);

        return true;
    }

    /**
     * @inheritDoc
     */
    protected function defaultForeignKey(): string
    {
        return $this->modelKey($this->getSource()->getTable());
    }

    /**
     * @inheritDoc
     */
    protected function defaultBindingKey(): string
    {
        $pk = $this->getSource()->getPrimaryKey();

        return is_array($pk) ? ($pk[0] ?? '_id') : $pk;
    }

    /**
     * Single binding key (string) read from the source row.
     *
     * @return string
     */
    protected function bindingKey(): string
    {
        $key = $this->getBindingKey();

        return is_array($key) ? ($key[0] ?? '_id') : $key;
    }
}

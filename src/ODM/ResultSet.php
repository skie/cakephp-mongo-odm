<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM;

use Cake\Collection\CollectionTrait;
use Cake\Collection\Iterator\BufferedIterator;
use Cake\Datasource\EntityInterface;
use Cake\Datasource\ResultSetInterface;
use Crustum\Mongo\Database\Type\TypeFactory;
use Crustum\Mongo\ODM\Association\BelongsToMany;
use Crustum\Mongo\ODM\Association\Embedded;
use Crustum\Mongo\ODM\Association\HasMany;
use Crustum\Mongo\ODM\Query\SelectQuery;
use IteratorIterator;
use MongoDB\BSON\ObjectId;
use MongoDB\Model\BSONDocument;

/**
 * Represents the results obtained after executing an ODM query.
 *
 * Hydration happens lazily in `current()`: each raw Mongo row is converted
 * through the repository type map, `_id` is exposed as `id` (string form so
 * debug output stays readable), and contained / matching associations are
 * deconstructed into hydrated Documents. Result formatters, MapReduce and DTO
 * projection run on the query layer, mirroring the pre-refactor `src/ODM/ResultSet.php`.
 *
 * @see src/ODM/ResultSet.php (pre-refactor working shape)
 * @see elastic-search/src/ResultSet.php (simpler sibling)
 * @template TKey
 * @template TValue
 * @implements \Cake\Datasource\ResultSetInterface<TKey, TValue>
 */
class ResultSet extends IteratorIterator implements ResultSetInterface
{
    /** @use \Cake\Collection\CollectionTrait<TKey, TValue> */
    use CollectionTrait;

    /**
     * The query that produced these results.
     *
     * @var \Crustum\Mongo\ODM\Query\SelectQuery|null
     */
    protected ?SelectQuery $query = null;

    /**
     * Flattened association map keyed by contain path.
     *
     * @var array<string, array{instance: \Crustum\Mongo\ODM\Association, config: array<string, mixed>, nestKey: string, matching: bool}>
     */
    protected array $_containMap = [];

    /**
     * Hydrated rows cache keyed by iteration index.
     *
     * Re-iterating / calling `toArray()` / `__debugInfo()` must return the same
     * object instances (cake60 hydrates once at execution time), so hydration
     * results are cached per position.
     *
     * @var array<int, mixed>
     */
    protected array $hydrated = [];

    /**
     * Constructor.
     *
     * Mongo cursors are single-pass iterators, so the raw rows are buffered to
     * allow rewind / repeated iteration (needed by `first()`, `toArray()`,
     * `__debugInfo`, and serialization).
     *
     * @param iterable<array-key, mixed> $items The raw cursor / rows.
     * @param \Crustum\Mongo\ODM\Query\SelectQuery|null $query The query that produced these results.
     */
    public function __construct(iterable $items, ?SelectQuery $query = null)
    {
        parent::__construct(new BufferedIterator($items));
        $this->query = $query;
        if ($query !== null) {
            $this->_calculateAssociationMap($query);
        }
    }

    /**
     * Binds the originating query and rebuilds the association map.
     *
     * @param \Crustum\Mongo\ODM\Query\SelectQuery $query The query.
     * @return void
     */
    public function setQuery(SelectQuery $query): void
    {
        $this->query = $query;
        $this->_calculateAssociationMap($query);
    }

    /**
     * Returns the query that produced these results.
     *
     * @return \Crustum\Mongo\ODM\Query\SelectQuery|null
     */
    public function getQuery(): ?SelectQuery
    {
        return $this->query;
    }

    /**
     * Returns the current row, hydrated lazily and cached per position.
     *
     * Unhydrated and DTO rows are returned as arrays with `_id` exposed as
     * `id`; hydrated rows become Documents with contained / matching
     * associations deconstructed.
     *
     * @return mixed
     */
    public function current(): mixed
    {
        $index = $this->key();
        if (array_key_exists($index, $this->hydrated)) {
            return $this->hydrated[$index];
        }

        $result = $this->getInnerIterator()->current();

        if (!$this->query || !$this->query->isHydrationEnabled() || $this->query->isDtoProjectionEnabled()) {
            if (is_array($result) || $result instanceof BSONDocument) {
                $data = $this->exposeId((array)$result);
                $data = $this->convertRow($data);

                return $this->hydrated[$index] = $data;
            }

            return $this->hydrated[$index] = $result;
        }

        if (is_array($result)) {
            $result = new BSONDocument($result);
        }
        if ($result instanceof BSONDocument) {
            $data = $this->mapId((array)$result);
            $data = $this->convertRow($data);

            return $this->hydrated[$index] = $this->groupResult($data);
        }

        return $this->hydrated[$index] = $result;
    }

    /**
     * Exposes the canonical `_id` as `id` (string form) while keeping `_id`.
     *
     * Hydrated Documents keep the canonical `_id` internally (Marshaller,
     * associations, rules read `get('_id')`); `id` is prepended for cake
     * key-order parity in `toArray()` / JSON output.
     *
     * @param array<string, mixed> $row The raw row.
     * @return array<string, mixed>
     */
    protected function mapId(array $row): array
    {
        if (array_key_exists('_id', $row)) {
            $id = $row['_id'];
            $row = ['id' => $id instanceof ObjectId ? (string)$id : $id] + $row;
        }

        return $row;
    }

    /**
     * Exposes the canonical `_id` as `id` (string form) in a raw row.
     *
     * The `_id` key is renamed (not duplicated) so unhydrated rows and DTO
     * mapping see exactly what cake's result rows expose.
     *
     * @param array<string, mixed> $row The raw row.
     * @return array<string, mixed>
     */
    protected function exposeId(array $row): array
    {
        if (array_key_exists('_id', $row)) {
            $id = $row['_id'];
            $row['id'] = $id instanceof ObjectId ? (string)$id : $id;
            unset($row['_id']);
        }

        return $row;
    }

    /**
     * Converts a row through the repository type map.
     *
     * @param array<string, mixed> $row The row data.
     * @return array<string, mixed>
     */
    protected function convertRow(array $row): array
    {
        $repository = $this->query?->getRepository();
        if (!$repository instanceof BaseCollection) {
            return $row;
        }

        $schema = $repository->getSchema();
        $driver = $repository->getConnection()?->getDriver();

        foreach ($row as $field => $value) {
            $typeName = $schema->getColumnType((string)$field);
            if ($typeName === null || !$driver) {
                continue;
            }
            $row[$field] = TypeFactory::build($typeName)->toPHP($value, $driver);
        }

        return $row;
    }

    /**
     * Calculates the flattened association map for a query.
     *
     * @param \Crustum\Mongo\ODM\Query\SelectQuery $query The query.
     * @return void
     */
    protected function _calculateAssociationMap(SelectQuery $query): void
    {
        $repository = $query->getRepository();
        if (!$repository instanceof BaseCollection) {
            $this->_containMap = [];

            return;
        }

        $eagerLoader = $query->getEagerLoader();
        $contain = $eagerLoader->getContain();

        $this->_containMap = $this->_buildAssociationMap($contain, $repository);
    }

    /**
     * Builds the association map recursively.
     *
     * @param array<int|string, mixed> $contain The containments array.
     * @param \Crustum\Mongo\ODM\BaseCollection $repository The current repository.
     * @param string $path The current contain path.
     * @return array<string, array{instance: \Crustum\Mongo\ODM\Association, config: array<string, mixed>, nestKey: string, matching: bool}>
     */
    protected function _buildAssociationMap(array $contain, BaseCollection $repository, string $path = ''): array
    {
        $map = [];

        foreach ($contain as $alias => $options) {
            $association = $repository->getAssociation((string)$alias);
            if (!$association) {
                continue;
            }

            $fullPath = $path ? $path . '.' . $alias : (string)$alias;
            $config = is_array($options) ? $options : [];

            $map[$fullPath] = [
                'instance' => $association,
                'config' => $config,
                'nestKey' => (string)$alias,
                'matching' => !empty($config['matching']),
            ];

            $nested = $this->_containOptions($config);
            if ($nested !== []) {
                $target = $association->getTarget();
                $map = array_merge($map, $this->_buildAssociationMap($nested, $target, $fullPath));
            }
        }

        return $map;
    }

    /**
     * Extracts nested association options from a contain entry.
     *
     * @param array<string, mixed> $config The contain options.
     * @return array<int|string, mixed>
     */
    protected function _containOptions(array $config): array
    {
        $nested = [];
        foreach ($config as $key => $value) {
            if (
                !is_int($key) && in_array($key, [
                'strategy', 'fields', 'conditions', 'sort', 'matching', 'queryBuilder',
                'foreignKey', 'limit', 'skip', 'config',
                ], true)
            ) {
                continue;
            }
            $nested[$key] = $value;
        }

        return $nested;
    }

    /**
     * Deconstructs a row into a hydrated document with associations.
     *
     * @param array<string, mixed> $row The converted row data.
     * @return \Cake\Datasource\EntityInterface
     */
    protected function groupResult(array $row): EntityInterface
    {
        $repository = $this->query?->getRepository();
        if (!$repository instanceof BaseCollection) {
            return new Document($row, ['source' => null]);
        }

        $results = [];
        $matching = [];

        foreach ($this->_containMap as $assoc) {
            $instance = $assoc['instance'];
            $propertyName = $instance->getProperty();

            if (!array_key_exists($propertyName, $row)) {
                continue;
            }

            if ($assoc['matching']) {
                $target = $instance->getTarget();
                $matching[$propertyName] = $this->hydrateRow((array)$row[$propertyName], $target);
                unset($row[$propertyName]);
                continue;
            }

            if ($instance instanceof Embedded) {
                $results[$propertyName] = $row[$propertyName];
                continue;
            }

            $target = $instance->getTarget();
            if ($instance instanceof HasMany || $instance instanceof BelongsToMany) {
                $results[$propertyName] = array_map(
                    fn(mixed $item): EntityInterface => $this->hydrateRow((array)$item, $target),
                    (array)$row[$propertyName],
                );
            } else {
                $results[$propertyName] = $this->hydrateRow((array)$row[$propertyName], $target);
            }
        }

        $entity = $repository->newEntity($results + $row, [
            'source' => $repository->getRegistryAlias(),
            'markNew' => false,
            'markClean' => true,
        ]);

        if ($matching !== []) {
            $entity->set('_matchingData', $matching);
        }

        $entity->clean();
        $entity->setNew(false);

        return $entity;
    }

    /**
     * Hydrates a single associated row into a document.
     *
     * @param array<string, mixed> $row The row data.
     * @param \Crustum\Mongo\ODM\BaseCollection $repository The target repository.
     * @return \Cake\Datasource\EntityInterface
     */
    protected function hydrateRow(array $row, BaseCollection $repository): EntityInterface
    {
        $row = $this->mapId($row);
        $row = $this->convertRow($row);

        return $repository->newEntity($row, [
            'source' => $repository->getRegistryAlias(),
            'markNew' => false,
            'markClean' => true,
        ]);
    }

    /**
     * Returns the total number of documents.
     *
     * Delegates to the query's count so lazy Mongo cursors are never consumed
     * just to count.
     *
     * @return int
     */
    public function count(): int
    {
        if ($this->query !== null) {
            return $this->query->count();
        }

        return count($this->toArray());
    }

    /**
     * Returns an array for serializing this object.
     *
     * Hydrated values are stored so unserializing preserves Documents.
     *
     * @return array<int, mixed>
     */
    public function __serialize(): array
    {
        return $this->toArray();
    }

    /**
     * Rebuilds the ResultSet instance.
     *
     * @param array<int, mixed> $data Data array.
     * @return void
     */
    public function __unserialize(array $data): void
    {
        $this->__construct($data);
    }

    /**
     * Returns an array that can be used to describe the internal state of this
     * object for developers.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        $items = $this->toArray();

        return [
            'count' => count($items),
            'items' => $items,
        ];
    }
}

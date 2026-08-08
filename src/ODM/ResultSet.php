<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM;

use Cake\Collection\CollectionTrait;
use Cake\Collection\Iterator\BufferedIterator;
use Cake\Datasource\EntityInterface;
use Cake\Datasource\ResultSetInterface;
use Crustum\Mongo\Database\Driver\MongoDriver;
use Crustum\Mongo\Database\Type\TypeFactory;
use Crustum\Mongo\ODM\Association\BelongsToMany;
use Crustum\Mongo\ODM\Association\Embedded;
use Crustum\Mongo\ODM\Association\HasMany;
use Crustum\Mongo\ODM\Query\SelectQuery;
use IteratorIterator;
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
 * @extends \IteratorIterator<TKey, TValue, \Traversable<TKey, TValue>>
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
        if ($query instanceof SelectQuery) {
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
                $data = $this->convertRow((array)$result);

                return $this->hydrated[$index] = $data;
            }

            return $this->hydrated[$index] = $result;
        }

        if (is_array($result) || $result instanceof BSONDocument) {
            $data = $this->convertRow((array)$result);

            return $this->hydrated[$index] = $this->groupResult($data);
        }

        return $this->hydrated[$index] = $result;
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
            if ($typeName === null) {
                continue;
            }

            if (!$driver instanceof MongoDriver) {
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

        $map = [];
        foreach ($query->getEagerLoader()->associationsMap($repository) as $entry) {
            $map[$entry['aliasPath']] = [
                'instance' => $entry['instance'],
                'config' => $entry['config'],
                'nestKey' => $entry['nestKey'],
                'matching' => $entry['matching'],
            ];
        }

        $this->_containMap = $map;
    }

    /**
     * Trims a root row to the fields selected via `select()`.
     *
     * Mongo rows are already nested, so a projection filters the root document
     * fields. Include-style projections (`field => 1`) keep only the listed
     * fields; exclude-style projections (`field => 0`) drop the listed fields.
     * Nested association data is left untouched — it is deconstructed before
     * the entity is built. A missing key (e.g. `_id` not selected) surfaces as
     * absent on the row, so eager loading can detect unusable selects.
     *
     * @param array<string, mixed> $row The converted row data.
     * @return array<string, mixed>
     */
    protected function applySelectClause(array $row): array
    {
        $projection = $this->query?->clause('select') ?? [];
        if ($projection === []) {
            return $row;
        }

        $exclude = array_all(
            $projection,
            fn(mixed $v): bool => (int)$v === 0,
        );

        if ($exclude) {
            $row = array_diff_key($row, array_flip(array_keys($projection)));
        } else {
            // Include-style projection: keep keys whose bare field matches a
            // projection key, or whose mapped value is a string field name.
            $keep = [];
            foreach ($projection as $key => $value) {
                if ((int)$value === 1) {
                    $keep[] = (string)$key;
                } elseif (is_string($value) && !str_starts_with($value, '$')) {
                    $keep[] = $value;
                }
            }

            $row = array_intersect_key($row, array_fill_keys($keep, true));
        }

        return $row;
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

        $row = $this->applySelectClause($row);

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
        if ($this->query instanceof SelectQuery) {
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
        return array_values($this->toArray());
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

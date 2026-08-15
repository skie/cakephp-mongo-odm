<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM;

use Cake\Collection\CollectionTrait;
use Cake\Collection\Iterator\BufferedIterator;
use Cake\Datasource\EntityInterface;
use Cake\Datasource\ResultSetInterface;
use Cake\I18n\DateTime as CakeDateTime;
use Countable;
use Crustum\Mongo\Database\Driver\MongoDriver;
use Crustum\Mongo\Database\Type\TypeFactory;
use Crustum\Mongo\ODM\Association\BelongsToMany;
use Crustum\Mongo\ODM\Association\Embedded;
use Crustum\Mongo\ODM\Association\HasMany;
use Crustum\Mongo\ODM\Query\SelectQuery;
use IteratorIterator;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Model\BSONArray;
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
                $data = $this->deconstructBelongsToMany($data);
                $data = $this->applyMatchingData($data);

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

        return $this->convertRowWith($row, $repository);
    }

    /**
     * Converts a row through a specific repository type map.
     *
     * @param array<string, mixed> $row The row data.
     * @param \Crustum\Mongo\ODM\BaseCollection $repository The repository whose schema drives casting.
     * @return array<string, mixed>
     */
    protected function convertRowWith(array $row, BaseCollection $repository): array
    {
        $schema = $repository->getSchema();
        $driver = $repository->getConnection()?->getDriver();

        foreach ($row as $field => $value) {
            $typeName = $schema->getColumnType((string)$field);
            if ($typeName === null && $this->query !== null) {
                $typeName = $this->query->getTypeMap()->type((string)$field);
            }
            if ($typeName === null) {
                if ($value instanceof BSONDocument || $value instanceof BSONArray) {
                    $row[$field] = self::bsonToArray($value);
                } elseif ($value instanceof ObjectId) {
                    $row[$field] = (string)$value;
                } elseif ($value instanceof UTCDateTime) {
                    $row[$field] = new CakeDateTime($value->toDateTime());
                }

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
     * Recursively converts BSON values into plain arrays.
     *
     * @param mixed $value The BSON value to convert.
     * @return mixed
     */
    protected static function bsonToArray(mixed $value): mixed
    {
        if ($value instanceof BSONArray) {
            return array_map([self::class, 'bsonToArray'], $value->getArrayCopy());
        }
        if ($value instanceof BSONDocument) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::bsonToArray($v);
            }

            return $out;
        }
        if (is_array($value)) {
            return array_map([self::class, 'bsonToArray'], $value);
        }
        if ($value instanceof ObjectId) {
            return (string)$value;
        }
        if ($value instanceof UTCDateTime) {
            return new CakeDateTime($value->toDateTime());
        }

        return $value;
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
            // A `matching()` + `contain()` on the same association yields two
            // loadables sharing an aliasPath; keep both so `_matchingData` and
            // the contained property can coexist (cake parity).
            $key = $entry['aliasPath'] . ($entry['matching'] ? '#matching' : '');
            $map[$key] = [
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
            return array_diff_key($row, array_flip(array_keys($projection)));
        }

        $keep = [];
        foreach ($projection as $key => $value) {
            if ((int)$value === 1) {
                $keep[] = (string)$key;
            } elseif (is_string($value) && str_starts_with($value, '$')) {
                $keep[] = (string)$key;
            } elseif (is_string($value) && !str_starts_with($value, '$')) {
                $keep[] = $value;
            }
        }

        return array_intersect_key($row, array_fill_keys($keep, true));
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

        $document = $repository->newEmptyDocument();
        $document->setSource($repository->getRegistryAlias());

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
                $matching[$assoc['nestKey']] = $this->hydrateRow((array)$row[$propertyName], $target);

                if ($instance instanceof BelongsToMany) {
                    $junctionKey = '_join_' . $propertyName;
                    if (isset($row[$junctionKey])) {
                        $junction = $instance->junction();
                        $matching[$junction->getAlias()] = $this->hydrateRow((array)$row[$junctionKey], $junction);
                        unset($row[$junctionKey]);
                    }
                }

                unset($row[$propertyName]);
                continue;
            }

            // A `contain()` on the same association that is `matching()`ed has
            // its property provided by the external loader (the row value is the
            // pipeline-matched document, not the contained collection).
            if (in_array($propertyName, $this->dualMatchingProperties(), true)) {
                continue;
            }

            if ($instance instanceof Embedded) {
                $results[$propertyName] = $instance->hydrateEmbedded($row[$propertyName], $document, $assoc['config']);
                continue;
            }

            $target = $instance->getTarget();
            if ($instance instanceof HasMany) {
                $results[$propertyName] = array_map(
                    fn(mixed $item): EntityInterface => $this->hydrateRow((array)$item, $target),
                    (array)$row[$propertyName],
                );
            } elseif ($instance instanceof BelongsToMany) {
                $junctionKey = '_join_' . $propertyName;
                $junctionRows = (array)($row[$junctionKey] ?? []);
                $junction = $instance->junction();

                $targetFk = $instance->getTargetForeignKey();
                $junctionProperty = $instance->getJunctionProperty();
                $junctionMap = [];
                foreach ($junctionRows as $junctionRow) {
                    $junctionRow = (array)$junctionRow;
                    $joinKey = (string)($junctionRow[$targetFk] ?? $junctionRow['_id'] ?? '');
                    if ($joinKey === '') {
                        continue;
                    }

                    unset($junctionRow['_id']);
                    $junctionMap[$joinKey] = $this->hydrateRow($junctionRow, $junction);
                }

                $results[$propertyName] = array_map(
                    function (mixed $item) use ($target, $junctionMap, $junctionProperty): EntityInterface {
                        $tag = $this->hydrateRow((array)$item, $target);
                        $joinKey = $tag->get('_id');
                        if ($joinKey !== null && isset($junctionMap[(string)$joinKey])) {
                            $tag->set($junctionProperty, $junctionMap[(string)$joinKey], ['guard' => false]);
                        }

                        return $tag;
                    },
                    (array)$row[$propertyName],
                );

                unset($row[$junctionKey]);
            } else {
                $results[$propertyName] = $this->hydrateRow((array)$row[$propertyName], $target);
            }
        }

        $document->patch($results + $row, ['guard' => false]);

        if ($matching !== []) {
            $document->set('_matchingData', $matching);
        }

        $document->clean();
        $document->setNew(false);

        return $document;
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

        return $repository->newDocument($row, [
            'source' => $repository->getRegistryAlias(),
            'markNew' => false,
            'markClean' => true,
        ]);
    }

    /**
     * Moves matching association rows into `_matchingData` on an unhydrated row.
     *
     * Matching pipelines expose the joined row under the association property;
     * unhydrated output nests it under `_matchingData.<Alias>` (hydrated rows
     * do this in `groupResult()`).
     *
     * @param array<string, mixed> $row The converted row data.
     * @return array<string, mixed>
     */
    protected function applyMatchingData(array $row): array
    {
        foreach ($this->_containMap as $assoc) {
            if (empty($assoc['matching'])) {
                continue;
            }

            $instance = $assoc['instance'];
            $propertyName = $instance->getProperty();
            $junctionKey = $instance instanceof BelongsToMany ? '_join_' . $propertyName : null;
            $negateMatch = (bool)($assoc['config']['negateMatch'] ?? false);
            $fields = $assoc['config']['fields'] ?? null;

            if (!array_key_exists($propertyName, $row)) {
                if ($junctionKey !== null && array_key_exists($junctionKey, $row)) {
                    unset($row[$junctionKey]);
                }
                continue;
            }

            if ($negateMatch) {
                if ($junctionKey !== null) {
                    unset($row[$junctionKey]);
                }
                unset($row[$propertyName]);
                continue;
            }

            // `joinWith`/`innerJoinWith` matching with `fields => false` is a
            // filter only: the joined row is not exposed (cake parity). An
            // explicit `select()` in the builder re-enables it via `fields`.
            if ($fields === false) {
                unset($row[$propertyName]);
                if ($junctionKey !== null) {
                    unset($row[$junctionKey]);
                }
                continue;
            }

            $matchingKey = (string)$assoc['nestKey'];
            $row['_matchingData'][$matchingKey] = $row[$propertyName];
            unset($row[$propertyName]);

            if ($instance instanceof BelongsToMany && $junctionKey !== null && isset($row[$junctionKey])) {
                $junction = $instance->junction();
                $junctionRows = (array)$row[$junctionKey];
                $targetFk = $instance->getTargetForeignKey();
                $matched = (array)$row['_matchingData'][$matchingKey];
                $matchedId = (string)($matched['_id'] ?? '');
                $selected = null;
                foreach ($junctionRows as $junctionRow) {
                    $junctionRow = (array)$junctionRow;
                    if ($matchedId !== '' && (string)($junctionRow[$targetFk] ?? '') === $matchedId) {
                        $selected = $junctionRow;
                        break;
                    }
                }

                if ($selected === null && isset($junctionRows[0])) {
                    $selected = (array)$junctionRows[0];
                }
                if (is_array($selected)) {
                    unset($selected['_id']);
                }

                $row['_matchingData'][$junction->getAlias()] = $selected;
                unset($row[$junctionKey]);
            }
        }

        return $row;
    }

    /**
     * Properties that are both `matching()`ed and `contain()`ed.
     *
     * @return list<string>
     */
    protected function dualMatchingProperties(): array
    {
        $matching = [];
        foreach ($this->_containMap as $assoc) {
            if (!empty($assoc['matching'])) {
                $matching[] = $assoc['instance']->getProperty();
            }
        }

        $dual = [];
        foreach ($this->_containMap as $assoc) {
            if (empty($assoc['matching']) && in_array($assoc['instance']->getProperty(), $matching, true)) {
                $dual[] = $assoc['instance']->getProperty();
            }
        }

        return array_values(array_unique($dual));
    }

    /**
     * Deconstructs belongsToMany join data on an unhydrated row.
     *
     * Lookup pipelines expose the joined target rows under the property and the
     * junction rows under `_join_<property>`. Hydration handles this in
     * `groupResult()`; arrays must resolve `_joinData` the same way and drop
     * the intermediate `_join_` field.
     *
     * @param array<string, mixed> $row The converted row data.
     * @return array<string, mixed>
     */
    protected function deconstructBelongsToMany(array $row): array
    {
        foreach ($this->_containMap as $assoc) {
            if (!empty($assoc['matching'])) {
                continue;
            }

            $instance = $assoc['instance'];
            if (!$instance instanceof BelongsToMany) {
                continue;
            }

            $propertyName = $instance->getProperty();
            if (!array_key_exists($propertyName, $row)) {
                continue;
            }

            $junctionKey = '_join_' . $propertyName;
            $junctionRows = (array)($row[$junctionKey] ?? []);
            $junction = $instance->junction();
            $target = $instance->getTarget();
            $targetFk = $instance->getTargetForeignKey();
            $junctionProperty = $instance->getJunctionProperty();
            $junctionMap = [];
            foreach ($junctionRows as $junctionRow) {
                $junctionRow = $this->convertRowWith((array)$junctionRow, $junction);
                unset($junctionRow['_id']);
                $joinKey = (string)($junctionRow[$targetFk] ?? $junctionRow['_id'] ?? '');
                if ($joinKey === '') {
                    continue;
                }

                $junctionMap[$joinKey] = $junctionRow;
            }

            $tags = (array)($row[$propertyName] ?? []);
            $row[$propertyName] = array_map(
                function (mixed $item) use ($target, $junctionMap, $junctionProperty): mixed {
                    $item = $this->convertRowWith(is_array($item) ? $item : (array)$item, $target);
                    $joinKey = (string)($item['_id'] ?? '');
                    if ($joinKey !== '' && isset($junctionMap[$joinKey])) {
                        $item[$junctionProperty] = $junctionMap[$joinKey];
                    }

                    return $item;
                },
                $tags,
            );

            unset($row[$junctionKey]);
        }

        return $row;
    }

    /**
     * Returns the number of rows buffered in this result set.
     *
     * Counts the buffered rows (mirroring cake's BufferedIterator::count), not
     * the total documents matching the query — the latter is available via
     * `SelectQuery::count()`. Counting the buffer avoids re-hitting Mongo.
     *
     * @return int
     */
    public function count(): int
    {
        $inner = $this->getInnerIterator();
        if ($inner instanceof Countable) {
            return $inner->count();
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

<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Query;

use ArrayObject;
use Cake\Collection\Iterator\MapReduce;
use Cake\Database\ExpressionInterface;
use Cake\Database\ValueBinder;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\Datasource\QueryCacher;
use Cake\Datasource\QueryInterface;
use Cake\Datasource\ResultSetInterface;
use Closure;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Database\Query\SelectQuery as DatabaseSelectQuery;
use Crustum\Mongo\ODM\Association;
use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\ODM\EagerLoader;
use Crustum\Mongo\ODM\ResultSet;
use Crustum\Mongo\ODM\ResultSetFactory;
use InvalidArgumentException;
use JsonSerializable;
use Psr\SimpleCache\CacheInterface;
use Throwable;
use Traversable;

/**
 * ODM select query with repository binding, hydration, and containment.
 *
 * The database query remains responsible for MongoDB clauses. This layer
 * wraps its executed rows in Documents and applies ODM result formatters.
 *
 * @see cake60/src/ORM/Query/SelectQuery.php
 */
class SelectQuery extends DatabaseSelectQuery implements JsonSerializable, QueryInterface
{
    use CommonQueryTrait;

    /**
     * Appends the result formatter to the stack.
     *
     * @var int
     */
    public const int APPEND = 0;

    /**
     * Prepends the result formatter to the stack.
     *
     * @var int
     */
    public const int PREPEND = 1;

    /**
     * Replaces the whole formatter stack.
     *
     * @var bool
     */
    public const bool OVERWRITE = true;

    /**
     * Whether executed rows should be hydrated as Documents.
     *
     * @var bool
     */
    protected bool $hydrate = true;

    /**
     * Containment/eager-loading coordinator.
     *
     * @var \Crustum\Mongo\ODM\EagerLoader
     */
    protected EagerLoader $eagerLoader;

    /**
     * Whether the `Collection.beforeFind` event has already been fired.
     *
     * @var bool
     */
    protected bool $beforeFindFired = false;

    /**
     * Whether this query is being executed as part of an eager load.
     *
     * @var bool
     */
    protected bool $eagerLoaded = false;

    /**
     * Custom count callback.
     *
     * @var \Closure|null
     */
    protected ?Closure $counter = null;

    /**
     * Cached result of the last `count()` call, cleared on any modification.
     *
     * @var int|null
     */
    protected ?int $resultsCount = null;

    /**
     * Result formatters applied after hydration.
     *
     * @var array<int, callable>
     */
    protected array $formatters = [];

    /**
     * Registered MapReduce routines applied to the raw results.
     *
     * @var list<array{mapper: \Closure, reducer: \Closure|null}>
     */
    protected array $mapReduce = [];

    /**
     * Optional DTO class for result projection.
     *
     * @var class-string|null
     */
    protected ?string $dtoClass = null;

    /**
     * Result set factory used to decorate executed rows.
     *
     * @var \Crustum\Mongo\ODM\ResultSetFactory<array<string, mixed>|\Crustum\Mongo\ODM\Document>|null
     */
    protected ?ResultSetFactory $resultSetFactory = null;

    /**
     * Cache handler for this query, when caching is enabled.
     *
     * @var \Cake\Datasource\QueryCacher|null
     */
    protected ?QueryCacher $cacher = null;

    /**
     * Whether to automatically append repository fields to the projection.
     *
     * `null` means unset; `true`/`false` mirror `enableAutoFields()` /
     * `disableAutoFields()`. Unlike SQL, this never builds a `SELECT *` — Mongo
     * already returns full documents when the projection is empty. Setting it
     * to `true` re-expands a limited projection with the schema columns so
     * computed `select()` fields do not silently hide document fields.
     *
     * @var bool|null
     */
    protected ?bool $autoFields = null;

    /**
     * Constructor.
     *
     * @param \Crustum\Mongo\ODM\BaseCollection|null $repository Repository to bind.
     */
    public function __construct(
        ?BaseCollection $repository = null,
    ) {
        $connection = $repository?->getConnection();
        $collection = $repository?->getCollection() ?? '';
        parent::__construct($connection, $collection);
        $this->eagerLoader = new EagerLoader();
        if ($repository instanceof BaseCollection) {
            $this->setRepository($repository);
            $this->addDefaultTypes();
        }
    }

    /**
     * Enables or disables document hydration.
     *
     * @param bool $enable Whether to hydrate rows.
     * @return $this
     */
    public function hydrate(bool $enable = true): static
    {
        $this->hydrate = $enable;
        $this->dirty();

        return $this;
    }

    /**
     * Enables document hydration (cake-compatible alias of `hydrate()`).
     *
     * @param bool $enable Whether to hydrate rows.
     * @return $this
     */
    public function enableHydration(bool $enable = true): static
    {
        return $this->hydrate($enable);
    }

    /**
     * Disables document hydration (cake-compatible alias of `hydrate(false)`).
     *
     * @return $this
     */
    public function disableHydration(): static
    {
        return $this->hydrate(false);
    }

    /**
     * Returns whether document hydration is enabled.
     *
     * @return bool
     */
    public function isHydrationEnabled(): bool
    {
        return $this->hydrate;
    }

    /**
     * Enables automatically appending repository fields to the projection.
     *
     * @param bool $value Set true to enable, false to disable.
     * @return $this
     */
    public function enableAutoFields(bool $value = true): static
    {
        $this->autoFields = $value;

        return $this;
    }

    /**
     * Disables automatically appending repository fields to the projection.
     *
     * @return $this
     */
    public function disableAutoFields(): static
    {
        $this->autoFields = false;

        return $this;
    }

    /**
     * Gets whether repository fields are automatically appended to the projection.
     *
     * @return bool|null The current value. Returns null if neither enabled nor disabled yet.
     */
    public function isAutoFieldsEnabled(): ?bool
    {
        return $this->autoFields;
    }

    /**
     * Returns whether this query is part of an eager load.
     *
     * @return bool
     */
    public function isEagerLoaded(): bool
    {
        return $this->eagerLoaded;
    }

    /**
     * Marks this query as being executed as part of an eager load.
     *
     * @param bool $value Whether the query is eagerly loaded.
     * @return $this
     */
    public function eagerLoaded(bool $value): static
    {
        $this->eagerLoaded = $value;

        return $this;
    }

    /**
     * Marks the query dirty so the next `all()` re-executes it.
     *
     * @return $this
     */
    public function clearResult(): static
    {
        $this->dirty();

        return $this;
    }

    /**
     * Marks the query dirty, discarding any cached result.
     *
     * Resetting `$results`/`$resultsCount` mirrors cake6 ORM
     * `SelectQuery::dirty()`, so a re-executed query always reflects the latest
     * clauses instead of serving the previously decorated ResultSet or count.
     *
     * @return void
     */
    protected function dirty(): void
    {
        $this->results = null;
        $this->resultsCount = null;
        parent::dirty();
    }

    /**
     * Sets a custom callback used by `count()`.
     *
     * @param \Closure|null $counter The counter callable.
     * @return $this
     */
    public function counter(?Closure $counter): static
    {
        $this->counter = $counter;

        return $this;
    }

    /**
     * Returns a clean clone of this query for sub-query use.
     *
     * The clone keeps the repository, projections, conditions and eager
     * loader, but drops offset/limit/order, auto fields and formatters so it
     * can be reused (cake6 `SelectQuery::cleanCopy()` parity).
     *
     * @return static
     */
    public function cleanCopy(): static
    {
        $clone = clone $this;
        $clone->disableAutoFields();
        $clone->limit(null);
        $clone->orderBy([], true);
        $clone->offset(null);
        $clone->mapReduce(null, null, true);
        $clone->formatResults(null, true);

        return $clone;
    }

    /**
     * Creates an aliased field expression.
     *
     * @param string $field Field name.
     * @param string|null $alias Alias prefix.
     * @return array<string, string>
     */
    public function aliasField(string $field, ?string $alias = null): array
    {
        $alias ??= $this->repository?->getAlias() ?? $this->getCollection();

        return [str_contains($field, '.') ? $field : $alias . '.' . $field => $field];
    }

    /**
     * Aliases a list of fields.
     *
     * @param array<int|string, string> $fields Fields to alias.
     * @param string|null $defaultAlias Default alias.
     * @return array<int|string, string>
     */
    public function aliasFields(array $fields, ?string $defaultAlias = null): array
    {
        $result = [];
        foreach ($fields as $key => $field) {
            $alias = $defaultAlias ?? $this->repository?->getAlias() ?? $this->getCollection();
            $result[$key] = str_contains($field, '.') ? $field : $alias . '.' . $field;
        }

        return $result;
    }

    /**
     * Applies common query options.
     *
     * @param array<string, mixed> $options Query options.
     * @return $this
     */
    public function applyOptions(array $options): static
    {
        ksort($options);
        foreach ($options as $key => $value) {
            if ($value === null) {
                continue;
            }

            match ($key) {
                'fields', 'select' => $this->select($value),
                'conditions', 'where' => $this->where($value),
                'limit' => $this->limit($value),
                'offset', 'skip' => $this->skip($value),
                'order', 'orderBy' => $this->orderBy($value),
                'group', 'groupBy' => $this->groupBy($value),
                'having' => $this->having($value),
                'page' => $this->page($value),
                'contain' => $this->contain($value),
                default => $this->options([$key => $value]),
            };
        }

        return $this;
    }

    /**
     * Sets the field projection.
     *
     * Field aliasing is handled by the Database-layer field resolver
     * (configured in {@see \Crustum\Mongo\ODM\Query\CommonQueryTrait::configureFieldResolver()}),
     * so this is a plain passthrough.
     *
     * A `BaseCollection`/`Association` argument selects every schema column of
     * that repository (cake6 `SelectQuery::select()` parity).
     *
     * @param \Cake\Database\ExpressionInterface|\Crustum\Mongo\ODM\BaseCollection|\Crustum\Mongo\ODM\Association|\Closure|array<int|string, mixed>|string|float|int $fields Fields to include/exclude.
     * @param bool $overwrite Whether to overwrite the existing projection.
     * @return $this
     */
    public function select(
        ExpressionInterface|BaseCollection|Association|Closure|array|string|float|int $fields = [],
        bool $overwrite = false,
    ): static {
        if ($fields instanceof Association) {
            $fields = $fields->getTarget();
        }

        if ($fields instanceof BaseCollection) {
            $fields = $fields->getSchema()->columns();
        }

        if (is_array($fields)) {
            $this->registerSelectAliasTypes($fields);
        }

        return parent::select($fields, $overwrite);
    }

    /**
     * Registers schema types for aliased select fields.
     *
     * `select(['updated_time' => 'updated'])` projects the source field under a
     * new name; the alias inherits the source field's schema type so results
     * cast (e.g. `updated` date → `updated_time` DateTime).
     *
     * @param array<int|string, mixed> $fields The select fields.
     * @return void
     */
    protected function registerSelectAliasTypes(array $fields): void
    {
        if ($this->repository === null) {
            return;
        }

        $schema = $this->repository->getSchema();
        foreach ($fields as $key => $value) {
            if (!is_string($key) || !is_string($value) || str_starts_with($value, '$')) {
                continue;
            }

            $source = $value;
            if (str_contains($source, '.')) {
                $source = substr($source, (int)strrpos($source, '.') + 1);
            }
            if ($source === 'id') {
                $source = '_id';
            }

            $type = $schema->getColumnType($source);
            if ($type !== null) {
                $this->getTypeMap()->addDefaults([(string)$key => $type]);
            }
        }
    }

    /**
     * Adds fields to group by.
     *
     * Field aliasing is handled by the Database-layer field resolver.
     *
     * @param \Cake\Database\ExpressionInterface|\Closure|array<string>|string $fields Fields to group by.
     * @param bool $overwrite Whether to overwrite the existing group fields.
     * @return $this
     */
    public function groupBy(ExpressionInterface|Closure|array|string $fields, bool $overwrite = false): static
    {
        if ($fields instanceof Closure) {
            $fields = $fields($this);
        }

        return parent::groupBy($fields, $overwrite);
    }

    /**
     * Provides the legacy query order alias required by QueryInterface.
     *
     * @param \Closure|array<string, mixed>|string $fields Sort fields.
     * @param bool $overwrite Whether to replace sorting.
     * @return $this
     */
    public function order(Closure|array|string $fields, bool $overwrite = false): static
    {
        return $this->orderBy($fields, $overwrite);
    }

    /**
     * Returns the applied query options.
     *
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return $this->getBuilder()->getOptions();
    }

    /**
     * Returns the first result or throws when no result exists.
     *
     * @return mixed
     * @throws \Cake\Datasource\Exception\RecordNotFoundException If empty.
     */
    public function firstOrFail(): mixed
    {
        $result = $this->all()->first();
        if ($result === null) {
            $collection = '?';
            $repository = $this->getRepository();
            if (method_exists($repository, 'getCollection')) {
                $collection = $repository->getCollection();
            }

            throw new RecordNotFoundException(sprintf(
                'Record not found in collection `%s`.',
                $collection,
            ));
        }

        return $result;
    }

    /**
     * Returns the first result from the executed query, or `null`.
     *
     * On a fresh (unmodified) query a `limit(1)` is applied so Mongo returns a
     * single document instead of buffering the full result set.
     *
     * @return mixed The first row (Document, DTO, or array) or null.
     */
    public function first(): mixed
    {
        if ($this->dirty) {
            $this->limit(1);
        }

        return $this->all()->first();
    }

    /**
     * Returns the number of matching documents.
     *
     * The result is cached until the query is modified; a subsequent `count()`
     * on an unchanged query reuses the previous value without re-querying
     * (cake6 `SelectQuery::count()` parity).
     *
     * @return int
     */
    public function count(): int
    {
        return $this->resultsCount ??= $this->performCount();
    }

    /**
     * Performs and returns the count for this query.
     *
     * When the query carries an in-pipeline eager load (`matching()` /
     * `contain()` via `$lookup`), the count must run through the pipeline
     * because rows are joined, filtered and possibly unwound by those stages —
     * a bare `countDocuments($filter)` would ignore them. The pipeline is built
     * on a clone (mirroring cake's `cleanCopy()`) so the source query is never
     * mutated, then a `$count` stage is appended so the server aggregates
     * instead of streaming every row. Otherwise the fast collection-level count
     * is used.
     *
     * @return int
     */
    protected function performCount(): int
    {
        $connection = $this->getConnection();
        if (!$connection instanceof Connection) {
            return 0;
        }

        if ($this->counter !== null) {
            $counter = $this->counter;
            $clone = clone $this;
            $clone->counter = null;

            return (int)$counter($clone);
        }

        $builder = $this->getBuilder();
        $complex = (
            $builder->getGroup() !== [] ||
            $builder->getHaving() !== [] ||
            $builder->getDistinct() !== [] ||
            $this->getEagerLoader()->hasInPipelineLoads()
        );

        if ($complex) {
            $clone = clone $this;
            $clone->addDefaultFields();
            if ($clone->repository instanceof BaseCollection) {
                $clone->eagerLoader->attachAssociations($clone, $clone->repository);
            }

            $clone->select([], true);
            $clone->getBuilder()->count('total');
            $rows = $clone->parentExecute();
            if ($rows instanceof Traversable) {
                $rows = iterator_to_array($rows);
            }

            $first = $rows[0] ?? null;
            if (is_object($first) && property_exists($first, 'total')) {
                return (int)$first->total;
            }

            return (int)($first['total'] ?? 0);
        }

        return $connection->getCollection($this->getCollection())->countDocuments($builder->getFilter());
    }

    /**
     * Appends fields to the projection without overwriting the existing list.
     *
     * @param \Cake\Database\ExpressionInterface|\Crustum\Mongo\ODM\BaseCollection|\Crustum\Mongo\ODM\Association|\Closure|array<int|string, mixed>|string|float|int ...$fields Fields to add.
     * @return $this
     */
    public function selectAlso(ExpressionInterface|BaseCollection|Association|Closure|array|string|float|int ...$fields): static
    {
        foreach ($fields as $field) {
            $this->select($field);
        }

        $this->autoFields = true;

        return $this;
    }

    /**
     * Selects all fields for the given collection except the excluded ones.
     *
     * The `_id` primary key is always kept (Mongo documents must be
     * addressable); when the collection exposes a schema the excluded fields
     * are removed from the field list, otherwise an exclusion projection is
     * built instead.
     *
     * @param \Crustum\Mongo\ODM\BaseCollection|\Crustum\Mongo\ODM\Association $collection The collection (or its association) to select fields from.
     * @param array<string> $excludedFields The un-aliased field names not to select.
     * @param bool $overwrite Whether to overwrite the existing projection.
     * @return $this
     */
    public function selectAllExcept(BaseCollection|Association $collection, array $excludedFields, bool $overwrite = false): static
    {
        if ($collection instanceof Association) {
            $collection = $collection->getTarget();
        }

        $fields = $collection->getSchema()->columns();
        if (!in_array('_id', $fields, true) && !in_array('_id', $excludedFields, true)) {
            array_unshift($fields, '_id');
        }

        if ($fields === []) {
            return $this->select(array_fill_keys($excludedFields, 0), $overwrite);
        }

        return $this->select(array_values(array_diff($fields, $excludedFields)), $overwrite);
    }

    /**
     * Returns the schema columns for a collection, when available.
     *
     * @param string $collection The collection name.
     * @return list<string>
     */
    protected function collectionFields(string $collection): array
    {
        $connection = $this->getConnection();
        if (!$connection instanceof Connection) {
            return [];
        }

        try {
            $schema = $connection->getSchemaCollection()->describe($collection);
        } catch (Throwable) {
            return [];
        }

        return array_values($schema->columns());
    }

    /**
     * Returns all results as a plain array.
     *
     * @return array<int|string, mixed>
     */
    public function toArray(): array
    {
        return $this->all()->toArray();
    }

    /**
     * Configures result caching for this query.
     *
     * The key may be a string or a callable that receives this query and
     * returns a string. Pass `false` to disable caching. Cached results are
     * hydrated or projected before being stored, matching the ORM contract.
     *
     * @param \Closure|string|false $key Cache key or key generator.
     * @param \Psr\SimpleCache\CacheInterface|string $config Cache config name or engine.
     * @return $this
     */
    public function cache(Closure|string|false $key, CacheInterface|string $config = 'default'): static
    {
        $this->cacher = $key === false ? null : new QueryCacher($key, $config);

        return $this;
    }

    /**
     * Returns the configured cache handler.
     *
     * @return \Cake\Datasource\QueryCacher|null
     */
    public function getCache(): ?QueryCacher
    {
        return $this->cacher;
    }

    /**
     * Creates a non-hydrating query that preserves all clauses.
     *
     * @return \Crustum\Mongo\ODM\Query\UnhydratedSelectQuery
     */
    public function unhydrated(): UnhydratedSelectQuery
    {
        $builder = $this->getBuilder();
        $query = new UnhydratedSelectQuery($this->repository);
        $query->getBuilder()->where($builder->getFilter());
        $query->getBuilder()->select($builder->getProjection());
        $query->getBuilder()->orderBy($builder->getSort());
        $query->getBuilder()->groupBy($builder->getGroup());
        $query->getBuilder()->having($builder->getHaving());
        $query->getBuilder()->limit($builder->getLimit());
        $query->getBuilder()->skip($builder->getSkip());
        $query->getBuilder()->pipeline($builder->getPipeline());
        $query->getBuilder()->options($builder->getOptions());

        return $query;
    }

    /**
     * Clones the eager loader so cloned queries never share mutable
     * containment normalization state.
     */
    public function __clone()
    {
        parent::__clone();
        $this->eagerLoader = clone $this->eagerLoader;
    }

    /**
     * Adds containment configuration.
     *
     * @param array<int|string, mixed>|string $associations Associations to contain.
     * @param \Closure|bool $override Whether to replace existing containment, or a query builder closure.
     * @return $this
     */
    public function contain(array|string $associations, Closure|bool $override = false): static
    {
        $this->removeEagerPipeline();

        $queryBuilder = null;
        if ($override === true) {
            $this->eagerLoader->clearContain();
        }

        if ($override instanceof Closure) {
            $queryBuilder = $override;
        }

        if ($associations) {
            $this->eagerLoader->contain($associations, $queryBuilder);
        }

        return $this;
    }

    /**
     * Removes previously attached eager-load pipeline stages.
     *
     * @return void
     */
    protected function removeEagerPipeline(): void
    {
        $stages = $this->eagerLoader->getAttachedPipeline();
        if ($stages !== []) {
            $this->builder->removePipelineStages($stages);
        }
        $this->eagerLoader->clearAttachedPipeline();
    }

    /**
     * Clears all configured containments.
     *
     * @return $this
     */
    public function clearContain(): static
    {
        $this->removeEagerPipeline();
        $this->eagerLoader->clearContain();

        return $this;
    }

    /**
     * Adds a matching association.
     *
     * @param string $association Association alias.
     * @param callable|null $builder Optional association query builder.
     * @return $this
     */
    public function matching(string $association, ?callable $builder = null): static
    {
        $this->getEagerLoader()->setMatching($association, $builder);
        $this->dirty();

        return $this;
    }

    /**
     * Adds filtering conditions to this query to only bring rows that have no
     * match to another from an associated collection, based on conditions in
     * the associated collection.
     *
     * @param string $association Association alias or dot separated path.
     * @param callable|null $builder Optional association query builder.
     * @return $this
     */
    public function notMatching(string $association, ?callable $builder = null): static
    {
        $this->getEagerLoader()->setMatching($association, $builder, [
            'fields' => false,
            'negateMatch' => true,
        ]);
        $this->dirty();

        return $this;
    }

    /**
     * Creates an inner matching filter with the passed association, preserving
     * the key matching and custom conditions. Selects no fields from the
     * association.
     *
     * @param string $association Association alias or dot separated path.
     * @param callable|null $builder Optional association query builder.
     * @return $this
     */
    public function innerJoinWith(string $association, ?callable $builder = null): static
    {
        $this->getEagerLoader()->setMatching($association, $builder, [
            'fields' => false,
        ]);
        $this->dirty();

        return $this;
    }

    /**
     * Creates a left matching filter with the passed association, preserving
     * the key matching and custom conditions. Selects no fields from the
     * association.
     *
     * @param string $association Association alias or dot separated path.
     * @param callable|null $builder Optional association query builder.
     * @return $this
     */
    public function leftJoinWith(string $association, ?callable $builder = null): static
    {
        $this->getEagerLoader()->setMatching($association, $builder, [
            'fields' => false,
            'joinType' => 'LEFT',
        ]);
        $this->dirty();

        return $this;
    }

    /**
     * Gets the eager loader.
     *
     * @return \Crustum\Mongo\ODM\EagerLoader
     */
    public function getEagerLoader(): EagerLoader
    {
        return $this->eagerLoader;
    }

    /**
     * Gets the configured containments.
     *
     * @return array<int|string, mixed>
     */
    public function getContain(): array
    {
        return $this->eagerLoader->getContain();
    }

    /**
     * Gets the registered result formatters.
     *
     * @return array<int, callable>
     */
    public function getResultFormatters(): array
    {
        return $this->formatters;
    }

    /**
     * Registers a MapReduce routine to run on top of the raw results.
     *
     * The routine is executed once the query runs, before result formatters.
     *
     * @param \Closure|null $mapper The mapper callback.
     * @param \Closure|null $reducer The reducer callback.
     * @param bool $overwrite Whether to replace previously registered routines.
     * @return $this
     * @throws \InvalidArgumentException When `$mapper` is null and `$overwrite` is false.
     */
    public function mapReduce(?Closure $mapper = null, ?Closure $reducer = null, bool $overwrite = false): static
    {
        if ($overwrite) {
            $this->mapReduce = [];
        }

        if (!$mapper instanceof Closure) {
            if (!$overwrite) {
                throw new InvalidArgumentException('$mapper can be null only when $overwrite is true.');
            }

            return $this;
        }

        $this->mapReduce[] = ['mapper' => $mapper, 'reducer' => $reducer];

        return $this;
    }

    /**
     * Returns the list of previously registered map reduce routines.
     *
     * @return list<array{mapper: \Closure, reducer: \Closure|null}>
     */
    public function getMapReducers(): array
    {
        return $this->mapReduce;
    }

    /**
     * Adds a result formatter.
     *
     * @param callable|null $formatter Formatter receiving an iterable result.
     * @param int|bool $mode Append, prepend, or overwrite mode.
     * @return $this
     */
    public function formatResults(?callable $formatter = null, int|bool $mode = 0): static
    {
        if ($formatter === null) {
            $this->formatters = [];
        } elseif ($mode === true) {
            $this->formatters = [$formatter];
        } elseif ($mode === 1) {
            array_unshift($this->formatters, $formatter);
        } else {
            $this->formatters[] = $formatter;
        }

        return $this;
    }

    /**
     * Projects results into DTO instances.
     *
     * The DTO class must provide a static `createFromArray(array $data): object`
     * factory. Hydration is skipped for the primary rows when a DTO is set.
     *
     * @param class-string $dtoClass DTO class name.
     * @return $this
     * @throws \InvalidArgumentException If the class does not exist.
     */
    public function projectAs(string $dtoClass): static
    {
        if (!class_exists($dtoClass)) {
            throw new InvalidArgumentException(sprintf('DTO class `%s` does not exist.', $dtoClass));
        }

        $this->dtoClass = $dtoClass;

        return $this;
    }

    /**
     * Returns the configured DTO projection class.
     *
     * @return class-string|null
     */
    public function getDtoClass(): ?string
    {
        return $this->dtoClass;
    }

    /**
     * Whether DTO projection is enabled for this query.
     *
     * @return bool
     */
    public function isDtoProjectionEnabled(): bool
    {
        return $this->dtoClass !== null;
    }

    /**
     * Gets the result set factory used to decorate executed rows.
     *
     * @return \Crustum\Mongo\ODM\ResultSetFactory<array<string, mixed>|\Crustum\Mongo\ODM\Document>
     */
    public function resultSetFactory(): ResultSetFactory
    {
        return $this->resultSetFactory ??= new ResultSetFactory();
    }

    /**
     * Pre-sets the result set returned by `all()`, skipping the database query.
     *
     * Used by `Collection.beforeFind` handlers to override the query results
     * (cake6 `SelectQuery::setResult()` parity). The iterable is decorated
     * through the ODM result pipeline (hydration, formatting) before being
     * cached on the query.
     *
     * @param iterable<mixed> $results The results to return.
     * @return $this
     */
    public function setResult(iterable $results): static
    {
        if ($results instanceof ResultSetInterface) {
            $this->results = $results;

            return $this;
        }

        $resultSet = new ResultSet($results, $this);

        foreach ($this->formatters as $formatter) {
            $formatted = $formatter($resultSet, $this);
            $resultSet = $formatted instanceof ResultSet
                ? $formatted
                : new ResultSet($formatted instanceof Traversable ? $formatted : (array)$formatted, $this);
        }

        $this->results = $resultSet;

        return $this;
    }

    /**
     * Triggers the `Collection.beforeFind` event on the query's repository.
     *
     * Fires at most once per query execution (cake6 `SelectQuery::triggerBeforeFind()`
     * parity). The repository's behavior/event hooks may modify the query before
     * it runs.
     *
     * @return void
     */
    public function triggerBeforeFind(): void
    {
        if ($this->beforeFindFired) {
            return;
        }

        $this->beforeFindFired = true;

        $repository = $this->getRepository();
        if ($repository instanceof BaseCollection) {
            $repository->dispatchEvent('Collection.beforeFind', [
                $this,
                new ArrayObject($this->getOptions()),
                !$this->eagerLoaded,
            ]);
        }
    }

    /**
     * Returns the compiled query for debugging.
     *
     * Fires `Collection.beforeFind` first (cake6 ORM
     * `SelectQuery::sql()` parity), so beforeFind hooks mutate the query
     * before its shape is inspected.
     *
     * @param \Cake\Database\ValueBinder|null $binder Ignored (Mongo has no bound values); kept for signature parity.
     * @return string
     */
    public function sql(?ValueBinder $binder = null): string
    {
        $this->triggerBeforeFind();

        if ($this->repository instanceof BaseCollection) {
            $this->eagerLoader->attachAssociations($this, $this->repository);
        }

        return parent::sql();
    }

    /**
     * Returns the executed results for JSON serialization.
     *
     * @return \Cake\Datasource\ResultSetInterface<array-key, mixed>
     */
    public function jsonSerialize(): ResultSetInterface
    {
        return $this->all();
    }

    /**
     * Executes and decorates the database query.
     *
     * Rows are hydrated into Documents (or projected into DTOs when configured),
     * then passed through result formatters. Cached result sets are returned
     * without hitting the database when a cache handler is configured.
     *
     * @return \Cake\Datasource\ResultSetInterface<array-key, mixed>
     */
    public function execute(): mixed
    {
        $this->triggerBeforeFind();
        if ($this->results !== null) {
            return $this->results instanceof ResultSetInterface ? $this->results : new ResultSet($this->results);
        }

        if ($this->cacher instanceof QueryCacher) {
            $cached = $this->cacher->fetch($this);
            if ($cached !== null) {
                return $cached instanceof ResultSet ? $cached : new ResultSet($cached);
            }
        }

        $this->addDefaultFields();

        $builder = $this->getBuilder();
        $projection = $builder->getProjection();
        if (
            $projection !== []
            && $this->autoFields !== true
            && $builder->getGroup() === []
            && $builder->getHaving() === []
            && !array_key_exists('_id', $projection)
        ) {
            $builder->select(['_id' => 0], false);
        }

        if ($this->repository instanceof BaseCollection) {
            $this->eagerLoader->attachAssociations($this, $this->repository);
        }

        $rows = parent::execute();
        $rows = $rows instanceof Traversable ? $rows : (array)$rows;

        $resultSet = $this->decorate($rows);

        if ($this->cacher instanceof QueryCacher) {
            $this->cacher->store($this, $resultSet);
        }

        return $resultSet;
    }

    /**
     * Executes the underlying database query without ODM decoration.
     *
     * Used by {@see count()} so the pipeline can be aggregated on a clone
     * without recursing into hydration / ResultSet::count().
     *
     * @return mixed The raw database result (Mongo cursor or array).
     */
    public function parentExecute(): mixed
    {
        return parent::execute();
    }

    /**
     * Appends repository schema fields to the projection when auto-fields are
     * enabled and a limited projection is in effect.
     *
     * @return void
     */
    protected function addDefaultFields(): void
    {
        if ($this->autoFields !== true || !$this->repository instanceof BaseCollection) {
            return;
        }

        $projection = $this->clause('select');
        if ($projection === []) {
            return;
        }

        foreach ($this->collectionFields($this->repository->getCollection()) as $field) {
            if (isset($projection[$field])) {
                continue;
            }

            if (isset($projection[$this->repository->getAlias() . '.' . $field])) {
                continue;
            }

            $this->select([$field]);
        }
    }

    /**
     * Hydrates, projects, and formats an executed row set.
     *
     * @param iterable<array-key, mixed> $rows Raw Mongo rows.
     * @return \Crustum\Mongo\ODM\ResultSet<array-key, mixed>
     */
    protected function decorate(iterable $rows): ResultSet
    {
        $resultSet = new ResultSet($rows, $this);

        if ($this->dtoClass === null) {
            $loaded = $this->eagerLoader->loadExternal($this, $resultSet);
            if (!$loaded instanceof ResultSet) {
                $resultSet = new ResultSet($loaded, $this);
            }
        }

        if ($this->mapReduce !== []) {
            $decorated = $resultSet;
            foreach ($this->mapReduce as $functions) {
                $decorated = new MapReduce($decorated, $functions['mapper'], $functions['reducer']);
            }

            $resultSet = new ResultSet($decorated, $this);
        }

        foreach ($this->formatters as $formatter) {
            $formatted = $formatter($resultSet, $this);
            $resultSet = $formatted instanceof ResultSet
                ? $formatted
                : new ResultSet($formatted instanceof Traversable ? $formatted : (array)$formatted, $this);
        }

        if ($this->dtoClass !== null) {
            $hydrator = $this->resultSetFactory()->getDtoHydrator($this->dtoClass);
            $mapped = $resultSet->map(fn(mixed $row): object => $hydrator((array)$row));
            $resultSet = $mapped instanceof ResultSet ? $mapped : new ResultSet($mapped, $this);
        }

        return $resultSet;
    }

    /**
     * Returns an array that can be used to describe the internal state of this
     * object.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        $eagerLoader = $this->getEagerLoader();

        return parent::__debugInfo() + [
            'hydrate' => $this->hydrate,
            'formatters' => count($this->formatters),
            'mapReducers' => count($this->mapReduce),
            'matching' => $eagerLoader->getMatching(),
            'contain' => $eagerLoader->getContain(),
            'extraOptions' => $this->getOptions(),
            'dtoClass' => $this->dtoClass,
            'repository' => $this->repository,
        ];
    }
}

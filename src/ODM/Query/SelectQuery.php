<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Query;

use Cake\Database\ExpressionInterface;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\Datasource\QueryCacher;
use Cake\Datasource\QueryInterface;
use Closure;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Database\Query\SelectQuery as DatabaseSelectQuery;
use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\ODM\EagerLoader;
use Crustum\Mongo\ODM\ResultSet;
use Crustum\Mongo\ODM\ResultSetFactory;
use InvalidArgumentException;
use Psr\SimpleCache\CacheInterface;
use Traversable;

/**
 * ODM select query with repository binding, hydration, and containment.
 *
 * The database query remains responsible for MongoDB clauses. This layer
 * wraps its executed rows in Documents and applies ODM result formatters.
 *
 * @see cake60/src/ORM/Query/SelectQuery.php
 */
class SelectQuery extends DatabaseSelectQuery implements QueryInterface
{
    use CommonQueryTrait;

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
     * Result formatters applied after hydration.
     *
     * @var array<int, callable>
     */
    protected array $formatters = [];

    /**
     * Optional DTO class for result projection.
     *
     * @var class-string|null
     */
    protected ?string $dtoClass = null;

    /**
     * Result set factory used to decorate executed rows.
     *
     * @var \Crustum\Mongo\ODM\ResultSetFactory|null
     */
    protected ?ResultSetFactory $resultSetFactory = null;

    /**
     * Cache handler for this query, when caching is enabled.
     *
     * @var \Cake\Datasource\QueryCacher|null
     */
    protected ?QueryCacher $cacher = null;

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

        return $this;
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
        foreach ($options as $key => $value) {
            match ($key) {
                'fields', 'select' => $this->select($value),
                'conditions', 'where' => $this->where($value),
                'limit' => $this->limit($value),
                'offset', 'skip' => $this->skip($value),
                'order', 'orderBy' => $this->orderBy($value),
                'page' => $this->page($value),
                'contain' => $this->contain($value),
                default => $this->options([$key => $value]),
            };
        }

        return $this;
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
     * Adds filter conditions, mapping the cake `id` field to the canonical
     * Mongo `_id` so `where(['id' => X])` hits the primary key.
     *
     * @param \Cake\Database\ExpressionInterface|\Closure|array<string, mixed>|string|null $conditions The conditions.
     * @param array<int|string, string> $types Field => type map used to cast values.
     * @param bool $overwrite Whether to overwrite existing conditions.
     * @return $this
     */
    public function where(
        ExpressionInterface|Closure|array|string|null $conditions = [],
        array $types = [],
        bool $overwrite = false,
    ): static {
        if (is_array($conditions)) {
            $conditions = $this->normalizeIdConditions($conditions);
        }

        return parent::where($conditions, $types, $overwrite);
    }

    /**
     * Recursively maps bare `id` condition keys to the canonical `_id`.
     *
     * @param array<int|string, mixed> $conditions The conditions.
     * @return array<int|string, mixed>
     */
    protected function normalizeIdConditions(array $conditions): array
    {
        foreach ($conditions as $key => $value) {
            if (is_string($key) && ($key === 'id' || preg_match('/^.+\.id$/', $key))) {
                $conditions['_id'] = $value;
                unset($conditions[$key]);
                continue;
            }

            if (is_array($value)) {
                $conditions[$key] = $this->normalizeIdConditions($value);
            }
        }

        return $conditions;
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
     * Sets the offset.
     *
     * @param int|null $offset Number of rows to skip.
     * @return $this
     */
    public function offset(?int $offset): static
    {
        return $this->skip($offset);
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
            throw new RecordNotFoundException('No result was found.');
        }

        return $result;
    }

    /**
     * Returns the first result from the executed query, or `null`.
     *
     * @return mixed The first row (Document, DTO, or array) or null.
     */
    public function first(): mixed
    {
        return $this->all()->first();
    }

    /**
     * Returns the number of matching documents.
     *
     * @return int
     */
    public function count(): int
    {
        $connection = $this->getConnection();
        if (!$connection instanceof Connection) {
            return 0;
        }

        return $connection->getCollection($this->getCollection())->countDocuments($this->getBuilder()->getFilter());
    }

    /**
     * Appends fields to the projection without overwriting the existing list.
     *
     * @param \Cake\Database\ExpressionInterface|\Closure|array<int|string, mixed>|string|float|int ...$fields Fields to add.
     * @return $this
     */
    public function selectAlso(ExpressionInterface|Closure|array|string|float|int ...$fields): static
    {
        foreach ($fields as $field) {
            $this->select($field);
        }

        return $this;
    }

    /**
     * Selects all fields for the given collection except the excluded ones.
     *
     * When the collection exposes a schema the excluded fields are removed from
     * the field list; otherwise an exclusion projection is built instead.
     *
     * @param string $collection The collection name.
     * @param array<string> $excludedFields The un-aliased field names not to select.
     * @param bool $overwrite Whether to overwrite the existing projection.
     * @return $this
     */
    public function selectAllExcept(string $collection, array $excludedFields, bool $overwrite = false): static
    {
        $fields = $this->collectionFields($collection);
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
        } catch (\Throwable) {
            return [];
        }

        return array_values($schema->columns());
    }

    /**
     * Returns all results as a plain array.
     *
     * @return array<int, mixed>
     */
    public function toArray(): array
    {
        return array_values($this->all()->toArray());
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
     * Orders results ascending by a field.
     *
     * @param string $field Field name.
     * @return $this
     */
    public function orderByAsc(string $field): static
    {
        return $this->orderBy([$field => 'ASC']);
    }

    /**
     * Orders results descending by a field.
     *
     * @param string $field Field name.
     * @return $this
     */
    public function orderByDesc(string $field): static
    {
        return $this->orderBy([$field => 'DESC']);
    }

    /**
     * Adds containment configuration.
     *
     * @param array<int|string, mixed>|string $associations Associations to contain.
     * @param bool $overwrite Whether to replace existing containment.
     * @return $this
     */
    public function contain(array|string $associations, bool $overwrite = false): static
    {
        if ($overwrite) {
            $this->eagerLoader->clearContain();
        }

        $this->eagerLoader->contain($associations);

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
        $options = ['matching' => true];
        if ($builder !== null) {
            $options['queryBuilder'] = $builder;
        }

        $this->eagerLoader->contain([$association => $options]);

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
     * @return \Crustum\Mongo\ODM\ResultSetFactory
     */
    public function resultSetFactory(): ResultSetFactory
    {
        return $this->resultSetFactory ??= new ResultSetFactory();
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
        if ($this->repository instanceof BaseCollection) {
            $this->eagerLoader->attachAssociations($this, $this->repository);
        }

        if ($this->cacher instanceof QueryCacher) {
            $cached = $this->cacher->fetch($this);
            if ($cached !== null) {
                return $cached instanceof ResultSet ? $cached : new ResultSet($cached);
            }
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
     * Hydrates, projects, and formats an executed row set.
     *
     * @param iterable<array-key, mixed> $rows Raw Mongo rows.
     * @return \Crustum\Mongo\ODM\ResultSet<array-key, mixed>
     */
    protected function decorate(iterable $rows): ResultSet
    {
        $resultSet = new ResultSet($rows, $this);

        if ($this->hydrate && $this->dtoClass === null) {
            $loaded = $this->eagerLoader->loadExternal($this, $resultSet);
            if (!$loaded instanceof ResultSet) {
                $resultSet = new ResultSet($loaded, $this);
            }
        }

        foreach ($this->formatters as $formatter) {
            $formatted = $formatter($resultSet, $this);
            $resultSet = $formatted instanceof ResultSet
                ? $formatted
                : new ResultSet($formatted instanceof Traversable ? $formatted : (array)$formatted, $this);
        }

        // DTO projection runs AFTER all other formatters so behaviors see arrays/documents
        if ($this->dtoClass !== null) {
            $hydrator = $this->resultSetFactory()->getDtoHydrator($this->dtoClass);
            $mapped = $resultSet->map(fn(mixed $row): object => $hydrator((array)$row));
            $resultSet = $mapped instanceof ResultSet ? $mapped : new ResultSet($mapped, $this);
        }

        return $resultSet;
    }
}

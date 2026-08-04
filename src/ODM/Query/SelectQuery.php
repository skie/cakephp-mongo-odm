<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Query;

use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\Datasource\QueryCacher;
use Cake\Datasource\QueryInterface;
use Cake\Datasource\RepositoryInterface;
use Closure;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Database\Expression\MongoExpressionInterface;
use Crustum\Mongo\Database\Query\SelectQuery as DatabaseSelectQuery;
use Crustum\Mongo\ODM\EagerLoader;
use Crustum\Mongo\ODM\ResultSet;
use Crustum\Mongo\ODM\ResultSetFactory;
use InvalidArgumentException;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;
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
     * Cache handler for this query, when caching is enabled.
     *
     * @var \Cake\Datasource\QueryCacher|null
     */
    protected ?QueryCacher $cacher = null;

    /**
     * Fields used to build a Mongo `$group` stage.
     *
     * @var array<string>
     */
    protected array $groupFields = [];

    /**
     * Constructor.
     *
     * @param \Crustum\Mongo\Database\Connection|null $connection Database connection.
     * @param string $collection Collection name.
     * @param \Cake\Datasource\RepositoryInterface|null $repository Repository to bind.
     */
    public function __construct(
        ?Connection $connection = null,
        string $collection = '',
        ?RepositoryInterface $repository = null,
    ) {
        parent::__construct($connection, $collection);
        $this->eagerLoader = new EagerLoader();
        if ($repository instanceof RepositoryInterface) {
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
     * Sets selected fields, accepting the complete datasource query scalar range.
     *
     * @param \Closure|array<string, mixed>|string|float|int $fields Fields to select.
     * @param bool $overwrite Whether to replace existing fields.
     * @return $this
     */
    public function select(Closure|array|string|float|int $fields, bool $overwrite = false): static
    {
        return parent::select(is_float($fields) || is_int($fields) ? (string)$fields : $fields, $overwrite);
    }

    /**
     * Sets query conditions with datasource-compatible type arguments.
     *
     * @param \Crustum\Mongo\Database\Expression\MongoExpressionInterface|\Closure|array<string, mixed>|string|null $conditions Conditions.
     * @param array<string, string>|bool $types Types or database overwrite flag.
     * @param bool $overwrite Whether to overwrite existing conditions.
     * @return $this
     */
    public function where(
        Closure|MongoExpressionInterface|array|string|null $conditions = null,
        array|bool $types = [],
        bool $overwrite = false,
    ): static {
        if (is_bool($types)) {
            $overwrite = $types;
        }

        return parent::where($conditions, $overwrite);
    }

    /**
     * Adds conditions with an AND conjunction.
     *
     * @param \Crustum\Mongo\Database\Expression\MongoExpressionInterface|\Closure|array<string, mixed>|string $conditions Conditions.
     * @param array<string, string> $types Types to apply.
     * @return $this
     */
    public function andWhere(
        Closure|MongoExpressionInterface|array|string $conditions,
        array $types = [],
    ): static {
        return parent::andWhere($conditions);
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
        $result = $this->first();
        if ($result === null) {
            throw new RecordNotFoundException('No result was found.');
        }

        return $result;
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
        $query = new UnhydratedSelectQuery($this->getConnection(), $this->getCollection(), $this->repository);
        $query->getBuilder()->where($builder->getFilter());
        $query->getBuilder()->select($builder->getProjection());
        $query->getBuilder()->orderBy($builder->getSort());
        $query->getBuilder()->limit($builder->getLimit());
        $query->getBuilder()->skip($builder->getSkip());
        $query->getBuilder()->pipeline($builder->getPipeline());
        $query->getBuilder()->options($builder->getOptions());

        return $query;
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
     * Groups results by one or more fields using a Mongo `$group` stage.
     *
     * A single field becomes the `_id` string; multiple fields are combined
     * into a compound `_id` document.
     *
     * @param array<string>|string $fields Grouping fields.
     * @return $this
     */
    public function groupBy(array|string $fields): static
    {
        $fields = array_values((array)$fields);
        $this->groupFields = $fields;
        $id = count($fields) === 1
            ? '$' . $fields[0]
            : array_combine($fields, array_map(static fn(string $field): string => '$' . $field, $fields));

        return $this->pipeline([['$group' => ['_id' => $id]]]);
    }

    /**
     * Returns the grouping fields configured for this query.
     *
     * @return array<string>
     */
    public function getGroupBy(): array
    {
        return $this->groupFields;
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
        if ($this->repository instanceof RepositoryInterface) {
            $this->eagerLoader->attachAssociations($this, $this->repository);
        }

        if ($this->cacher !== null) {
            $cached = $this->cacher->fetch($this);
            if ($cached !== null) {
                return $cached instanceof ResultSet ? $cached : new ResultSet($cached);
            }
        }

        $rows = parent::execute();
        $rows = $rows instanceof Traversable ? $rows : (array)$rows;
        $resultSet = $this->decorate($rows);

        if ($this->cacher !== null) {
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
        if ($this->dtoClass !== null) {
            $dtoClass = $this->dtoClass;
            if (!method_exists($dtoClass, 'createFromArray')) {
                throw new RuntimeException(sprintf(
                    'DTO class `%s` must provide a static createFromArray() factory.',
                    $dtoClass,
                ));
            }

            $dtos = [];
            foreach ($rows as $row) {
                $dtos[] = $dtoClass::createFromArray((array)$row);
            }
            $resultSet = new ResultSet($dtos);
        } else {
            $resultSet = (new ResultSetFactory())->createResultSet($rows, [
                'hydrate' => $this->hydrate,
                'source' => $this->repository?->getRegistryAlias() ?? $this->getCollection(),
            ]);
        }

        foreach ($this->formatters as $formatter) {
            $formatted = $formatter($resultSet);
            $resultSet = $formatted instanceof ResultSet
                ? $formatted
                : new ResultSet($formatted instanceof Traversable ? $formatted : (array)$formatted);
        }

        return $resultSet;
    }
}

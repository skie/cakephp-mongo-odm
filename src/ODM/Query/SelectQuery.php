<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Query;

use Cake\Datasource\Exception\RecordNotFoundException;
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
     * Optional DTO class for a future projection mapper.
     *
     * @var class-string|null
     */
    protected ?string $dtoClass = null;

    /**
     * Cache configuration for this query.
     *
     * @var mixed
     */
    protected mixed $cacheKey = null;

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
        if ($repository !== null) {
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
     * Configures a cache key for the query.
     *
     * Cache storage is applied by the Collection/query integration layer.
     *
     * @param \Closure|string|false $key Cache key or disabled marker.
     * @param mixed $config Cache configuration.
     * @return $this
     */
    public function cache(Closure|string|false $key, mixed $config = 'default'): static
    {
        $this->cacheKey = $key === false ? null : [$key, $config];

        return $this;
    }

    /**
     * Creates a non-hydrating clone of this query.
     *
     * @return \Crustum\Mongo\ODM\Query\UnhydratedSelectQuery
     */
    public function unhydrated(): UnhydratedSelectQuery
    {
        $query = new UnhydratedSelectQuery($this->getConnection(), $this->getCollection(), $this->repository);

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
     * Records grouping fields for Mongo pipeline consumers.
     *
     * @param array<int|string, mixed>|string $fields Grouping fields.
     * @return $this
     */
    public function groupBy(array|string $fields): static
    {
        $this->options(['group' => $fields]);

        return $this;
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
     * Projects results to a DTO class marker.
     *
     * @param string $dtoClass DTO class name.
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
     * Executes and hydrates the database query.
     *
     * @return mixed A ResultSet for select results.
     */
    public function execute(): mixed
    {
        if ($this->repository !== null) {
            $this->eagerLoader->attachAssociations($this, $this->repository);
        }
        $rows = parent::execute();
        $resultSet = new ResultSetFactory()->createResultSet($rows, [
            'hydrate' => $this->hydrate,
            'source' => $this->repository?->getRegistryAlias() ?? $this->getCollection(),
        ]);
        foreach ($this->formatters as $formatter) {
            $formatted = $formatter($resultSet);
            $resultSet = $formatted instanceof ResultSet
                ? $formatted
                : new ResultSet($formatted instanceof Traversable ? $formatted : (array)$formatted);
        }

        return $resultSet;
    }
}

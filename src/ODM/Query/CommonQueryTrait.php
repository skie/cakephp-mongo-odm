<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Query;

use Cake\Database\Expression\OrderClauseExpression;
use Cake\Database\ExpressionInterface;
use Cake\Database\ValueBinder;
use Cake\Datasource\RepositoryInterface;
use Closure;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Database\Driver\MongoDriver;
use Crustum\Mongo\Database\Type\TypeFactory;
use Crustum\Mongo\Database\TypeMapTrait;
use Crustum\Mongo\ODM\BaseCollection;
use InvalidArgumentException;

/**
 * Provides repository binding and schema type defaults for ODM queries.
 *
  * @rewritten-from \Cake\ORM\Query\CommonQueryTrait
 */
trait CommonQueryTrait
{
    use TypeMapTrait;

    /**
     * Repository used by this query.
     *
     * @var \Crustum\Mongo\ODM\BaseCollection|null
     */
    protected ?BaseCollection $repository = null;

    /**
     * Binds a repository to this query.
     *
     * @param \Cake\Datasource\RepositoryInterface $repository Repository instance.
     * @return $this
     */
    public function setRepository(RepositoryInterface $repository): static
    {
        if (!$repository instanceof BaseCollection) {
            throw new InvalidArgumentException('ODM queries require a BaseCollection repository.');
        }

        $this->repository = $repository;
        $this->from($repository->getCollection());
        $connection = $repository->getConnection();
        if ($connection instanceof Connection) {
            $this->setConnection($connection);
        }

        $this->configureFieldResolver();

        return $this;
    }

    /**
     * Configures the Database-layer field resolver for this repository.
     *
     * Mongo has no table aliases, so `Alias.field` keys are stripped to their
     * bare field (`Alias._id` → `_id`) at compilation time — the Mongo analog
     * of cake's `IdentifierQuoter`. This is set once here, shared by Select,
     * Update, and Delete queries, so `where` / `orderBy` / `select` / `groupBy`
     * / `updateAll` / `deleteAll` / `exists` all resolve identically without
     * per-method interception.
     *
     * @return void
     */
    protected function configureFieldResolver(): void
    {
        $alias = $this->repository?->getAlias();
        if ($alias === null || $alias === '') {
            return;
        }

        $primaryKey = $this->repository->getPrimaryKey();

        $this->setFieldResolver(
            static fn(string $field): string => self::resolveOdmField($field, $alias, $primaryKey),
        );
    }

    /**
     * Resolves a query field name for the bound repository.
     *
     * Strips the repository alias prefix (`Alias.field` → `field`) and maps the
     * conventional primary-key alias `id` to the Mongo `_id` field, so cake
     * style `orderBy('articles.id')` / `where(['id' => ...])` queries target
     * the actual ObjectId key. When the collection configures an application
     * primary key named `id` (a natural key distinct from the Mongo `_id`),
     * the mapping is disabled so `id` stays the natural-key field.
     *
     * @param string $field The raw field name.
     * @param string $alias The repository alias.
     * @param array<string>|string $primaryKey The repository primary key.
     * @return string The resolved Mongo field name.
     */
    protected static function resolveOdmField(string $field, string $alias, array|string $primaryKey = '_id'): string
    {
        if (str_starts_with($field, $alias . '.')) {
            $field = substr($field, strlen($alias) + 1);
        }

        if ($field === 'id' && !is_array($primaryKey) && $primaryKey === '_id') {
            return '_id';
        }

        return $field;
    }

    /**
     * Returns the repository bound to this query.
     *
     * @return \Crustum\Mongo\ODM\BaseCollection|null
     */
    public function getRepository(): ?BaseCollection
    {
        return $this->repository;
    }

    /**
     * Adds schema types for unqualified, qualified, and nested field names.
     *
     * @return $this
     */
    public function addDefaultTypes(): static
    {
        if ($this->repository === null) {
            return $this;
        }

        $alias = $this->repository->getAlias();
        $types = [];
        foreach ($this->repository->getSchema()->typeMap() as $field => $type) {
            $types[$field] = $type;
            $types[$alias . '.' . $field] = $type;
            $types[$alias . '__' . $field] = $type;
        }

        $this->getTypeMap()->addDefaults($types);

        return $this;
    }

    /**
     * Adds filter conditions.
     *
     * Field aliasing is handled by the Database-layer field resolver
     * (configured in {@see configureFieldResolver()}), so this is a plain
     * passthrough. Closures are resolved by the Database query layer
     * recursively, mirroring cake.
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
        return parent::where($conditions, $types, $overwrite);
    }

    /**
     * Adds sort order.
     *
     * Only the cake `OrderClauseExpression` form needs translation here; field
     * aliasing is handled by the Database-layer field resolver.
     *
     * @param \Cake\Database\ExpressionInterface|\Closure|array<string, mixed>|string $fields Fields to sort by.
     * @param bool $overwrite Whether to overwrite the existing sort.
     * @return $this
     */
    public function orderBy(ExpressionInterface|Closure|array|string $fields, bool $overwrite = false): static
    {
        if ($fields instanceof OrderClauseExpression) {
            $field = $fields->getField();
            if (is_string($field)) {
                $fields = [$field => $this->orderDirection($fields)];
            }
        }

        return parent::orderBy($fields, $overwrite);
    }

    /**
     * Reads the direction of an order clause expression.
     *
     * `OrderClauseExpression` exposes no direction getter; the direction is
     * derived from its rendered SQL (`field DIRECTION`), which is stable for
     * the plain field/direction constructor shape used here.
     *
     * @param \Cake\Database\Expression\OrderClauseExpression $expression The order clause.
     * @return string
     */
    protected function orderDirection(OrderClauseExpression $expression): string
    {
        return str_ends_with($expression->sql(new ValueBinder()), ' DESC') ? 'DESC' : 'ASC';
    }

    /**
     * Dispatches a repository finder.
     *
     * @param string $type Finder name.
     * @param mixed ...$args Finder arguments.
     * @return static
     */
    public function find(string $type = 'all', mixed ...$args): static
    {
        if ($this->repository === null || !$this instanceof SelectQuery) {
            return $this;
        }

        // `find('all', ...$options)` still applies the options (cake parity) —
        // `Collection::get($id, ...['contain' => ...])` relies on it. Only the
        // named (string-keyed) args are query options.
        if ($type === 'all') {
            $options = [];
            foreach ($args as $key => $value) {
                if (is_string($key)) {
                    $options[$key] = $value;
                }
            }

            if ($options !== []) {
                $this->applyOptions($options);
            }

            return $this;
        }

        $result = $this->repository->callFinder($type, $this, ...$args);
        if ($result instanceof static) {
            return $result;
        }

        return $this;
    }

    /**
     * Converts document values through the configured type map defaults.
     *
     * Only fields with a configured type are converted; the rest pass through.
     * Used by the ODM write queries so values reach Mongo in their BSON form
     * without a separate persister layer.
     *
     * @param array<string, mixed> $values The document.
     * @return array<string, mixed>
     */
    protected function convertToDatabaseValues(array $values): array
    {
        $connection = $this->getConnection();
        if (!$connection instanceof Connection) {
            return $values;
        }

        $driver = $connection->getDriver();
        if (!$driver instanceof MongoDriver) {
            return $values;
        }

        $types = $this->getTypeMap()->toArray();
        foreach ($values as $field => $value) {
            if (isset($types[$field])) {
                $values[$field] = $this->convertValueToDatabase($value, $types[$field], $driver);
            }
        }

        return $values;
    }

    /**
     * Converts a single value through its configured type.
     *
     * Lists are converted item by item; associative arrays pass through so
     * Mongo operator expressions stay untouched.
     *
     * @param mixed                                 $value The value.
     * @param string                                $type  The type name.
     * @param \Crustum\Mongo\Database\Driver\MongoDriver $driver The driver.
     * @return mixed
     */
    protected function convertValueToDatabase(mixed $value, string $type, MongoDriver $driver): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($type === 'array' && is_array($value)) {
            return $value;
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(
                    fn(mixed $item): mixed => $this->convertValueToDatabase($item, $type, $driver),
                    $value,
                );
            }

            return $value;
        }

        return TypeFactory::build($type)->toDatabase($value, $driver);
    }
}

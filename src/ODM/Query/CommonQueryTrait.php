<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Query;

use Cake\Datasource\RepositoryInterface;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Database\Driver\MongoDriver;
use Crustum\Mongo\Database\Type\TypeFactory;
use Crustum\Mongo\Database\TypeMapTrait;

/**
 * Provides repository binding and schema type defaults for ODM queries.
 *
 * @see cake60/src/ORM/Query/CommonQueryTrait.php
 */
trait CommonQueryTrait
{
    use TypeMapTrait;

    /**
     * Repository used by this query.
     *
     * @var \Cake\Datasource\RepositoryInterface|null
     */
    protected ?RepositoryInterface $repository = null;

    /**
     * Binds a repository to this query.
     *
     * @param \Cake\Datasource\RepositoryInterface $repository Repository instance.
     * @return $this
     */
    public function setRepository(RepositoryInterface $repository): static
    {
        $this->repository = $repository;
        $this->from($repository->getAlias());
        if (method_exists($repository, 'getConnection')) {
            $connection = $repository->getConnection();
            if ($connection !== null) {
                $this->setConnection($connection);
            }
        }

        return $this;
    }

    /**
     * Returns the repository bound to this query.
     *
     * @return \Cake\Datasource\RepositoryInterface|null
     */
    public function getRepository(): ?RepositoryInterface
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
        if ($this->repository === null || !method_exists($this->repository, 'getSchema')) {
            return $this;
        }

        $schema = $this->repository->getSchema();
        if (!is_object($schema) || !method_exists($schema, 'typeMap')) {
            return $this;
        }

        $alias = $this->repository->getAlias();
        $types = [];
        foreach ($schema->typeMap() as $field => $type) {
            $types[$field] = $type;
            $types[$alias . '.' . $field] = $type;
            $types[$alias . '__' . $field] = $type;
        }

        $this->getTypeMap()->addDefaults($types);

        return $this;
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
        if ($type === 'all' || $this->repository === null) {
            return $this;
        }

        if (is_callable([$this->repository, 'callFinder'])) {
            $result = $this->repository->callFinder($type, $this, ...$args);
            if ($result instanceof static) {
                return $result;
            }
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

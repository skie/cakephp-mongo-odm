<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM\Query;

use BadMethodCallException;
use Cake\Datasource\ConnectionInterface;
use Cake\Datasource\EntityInterface;
use Cake\Datasource\QueryInterface;
use Cake\Datasource\RepositoryInterface;
use Closure;
use Psr\SimpleCache\CacheInterface;

/**
 * Minimal repository stub for ODM query tests.
 *
 * Exposes `getConnection()` and `getSchema()` in addition to the datasource
 * contract so ODM query wiring can be exercised without a real Collection.
 */
final class RepositoryStub implements RepositoryInterface
{
    /**
     * @param array<string, string>      $types   Schema field => type map.
     * @param array<string, callable>    $finders Named finder callbacks.
     */
    public function __construct(
        private string $alias = 'users',
        private string $registryAlias = 'Users',
        private array $types = [],
        private ?ConnectionInterface $connection = null,
        private array $finders = [],
    ) {
    }

    public function callFinder(string $type, mixed $query, mixed ...$args): mixed
    {
        $finder = $this->finders[$type] ?? null;
        if ($finder === null) {
            throw new BadMethodCallException(sprintf('Unknown finder "%s".', $type));
        }

        return $finder($query, ...$args);
    }

    public function setAlias(string $alias): void
    {
        $this->alias = $alias;
    }

    public function getAlias(): string
    {
        return $this->alias;
    }

    public function aliasField(string $field): string
    {
        return $field;
    }

    public function setRegistryAlias(string $registryAlias): void
    {
        $this->registryAlias = $registryAlias;
    }

    public function getRegistryAlias(): string
    {
        return $this->registryAlias;
    }

    public function hasField(string $field): bool
    {
        return isset($this->types[$field]);
    }

    public function find(string $type = 'all', mixed ...$args): QueryInterface
    {
        throw new BadMethodCallException('Not implemented for the query stub.');
    }

    public function get(
        mixed $primaryKey,
        array|string $finder = 'all',
        CacheInterface|string|null $cache = null,
        Closure|string|null $cacheKey = null,
        mixed ...$args,
    ): EntityInterface {
        throw new BadMethodCallException('Not implemented for the query stub.');
    }

    public function query(): QueryInterface
    {
        throw new BadMethodCallException('Not implemented for the query stub.');
    }

    public function updateAll(Closure|array|string $fields, Closure|array|string|null $conditions): int
    {
        throw new BadMethodCallException('Not implemented for the query stub.');
    }

    public function deleteAll(Closure|array|string|null $conditions): int
    {
        throw new BadMethodCallException('Not implemented for the query stub.');
    }

    public function exists(Closure|array|string|null $conditions): bool
    {
        throw new BadMethodCallException('Not implemented for the query stub.');
    }

    public function save(EntityInterface $entity, array $options = []): EntityInterface|false
    {
        throw new BadMethodCallException('Not implemented for the query stub.');
    }

    public function delete(EntityInterface $entity, array $options = []): bool
    {
        throw new BadMethodCallException('Not implemented for the query stub.');
    }

    public function newEmptyEntity(): EntityInterface
    {
        throw new BadMethodCallException('Not implemented for the query stub.');
    }

    public function newEntity(array $data, array $options = []): EntityInterface
    {
        throw new BadMethodCallException('Not implemented for the query stub.');
    }

    public function newEntities(array $data, array $options = []): array
    {
        throw new BadMethodCallException('Not implemented for the query stub.');
    }

    public function patchEntity(EntityInterface $entity, array $data, array $options = []): EntityInterface
    {
        throw new BadMethodCallException('Not implemented for the query stub.');
    }

    public function patchEntities(iterable $entities, array $data, array $options = []): array
    {
        throw new BadMethodCallException('Not implemented for the query stub.');
    }

    public function getConnection(): ?ConnectionInterface
    {
        return $this->connection;
    }

    /**
     * Returns a schema object exposing the configured type map.
     *
     * @return object
     */
    public function getSchema(): object
    {
        return new class ($this->types) {
            /** @param array<string, string> $types */
            public function __construct(private array $types)
            {
            }

            /** @return array<string, string> */
            public function typeMap(): array
            {
                return $this->types;
            }
        };
    }
}

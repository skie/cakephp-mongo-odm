<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Query;

use Closure;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Database\Expression\MongoExpressionInterface;
use InvalidArgumentException;
use IteratorAggregate;
use Traversable;

/**
 * Select query for MongoDB find() and aggregation pipeline operations.
 *
 * @implements \IteratorAggregate<int, array<string, mixed>>
 *
 * @see cake50/src/Database/Query/SelectQuery.php
 */
class SelectQuery extends Query implements IteratorAggregate
{
    /**
     * Sets the filter conditions.
     *
     * @param \Crustum\Mongo\Database\Expression\MongoExpressionInterface|\Closure|array<string, mixed>|string|null $conditions The conditions.
     * @param bool $overwrite Whether to overwrite existing conditions.
     * @return $this
     */
    public function where(Closure|MongoExpressionInterface|array|string|null $conditions, bool $overwrite = false): static
    {
        $this->builder->where($conditions, $overwrite);

        return $this;
    }

    /**
     * Adds conditions with an `$and` operator.
     *
     * @param \Crustum\Mongo\Database\Expression\MongoExpressionInterface|\Closure|array<string, mixed>|string $conditions The conditions to add.
     * @return $this
     */
    public function andWhere(Closure|MongoExpressionInterface|array|string $conditions): static
    {
        $this->builder->andWhere($conditions);

        return $this;
    }

    /**
     * Sets the field projection.
     *
     * A `Closure` receives the query and must return the fields to project.
     *
     * @param \Closure|array<string, mixed>|string $fields Fields to include/exclude.
     * @param bool $overwrite Whether to overwrite the existing projection.
     * @return $this
     */
    public function select(Closure|array|string $fields, bool $overwrite = false): static
    {
        if ($fields instanceof Closure) {
            $fields = $fields($this);
        }

        $this->builder->select($fields, $overwrite);

        return $this;
    }

    /**
     * Sets the sort order.
     *
     * A `Closure` receives the query and must return the fields to sort by.
     *
     * @param \Closure|array<string, mixed>|string $fields Fields to sort by.
     * @param bool $overwrite Whether to overwrite the existing sort.
     * @return $this
     */
    public function orderBy(Closure|array|string $fields, bool $overwrite = false): static
    {
        if ($fields instanceof Closure) {
            $fields = $fields($this);
        }

        $this->builder->orderBy($fields, $overwrite);

        return $this;
    }

    /**
     * Sets the result limit.
     *
     * @param int|null $limit Number of results to return.
     * @return $this
     */
    public function limit(?int $limit): static
    {
        $this->builder->limit($limit);

        return $this;
    }

    /**
     * Sets the number of results to skip.
     *
     * @param int|null $skip Number of results to skip.
     * @return $this
     */
    public function skip(?int $skip): static
    {
        $this->builder->skip($skip);

        return $this;
    }

    /**
     * Sets the result page using limit/skip.
     *
     * Page numbers start at 1. When no limit is set it defaults to 25.
     *
     * @param int $page The page number.
     * @param int|null $limit The page size.
     * @return $this
     * @throws \InvalidArgumentException When the page number is below 1.
     */
    public function page(int $page, ?int $limit = null): static
    {
        if ($page < 1) {
            throw new InvalidArgumentException('Pages must start at 1.');
        }

        if ($limit !== null) {
            $this->limit($limit);
        }

        $limit = $this->builder->getLimit();
        if ($limit === null) {
            $limit = 25;
            $this->limit($limit);
        }

        $this->skip(($page - 1) * $limit);

        return $this;
    }

    /**
     * Adds aggregation pipeline stage(s).
     *
     * @param array<int, array<string, mixed>> $stages Pipeline stages to add.
     * @return $this
     */
    public function pipeline(array $stages): static
    {
        $this->builder->pipeline($stages);

        return $this;
    }

    /**
     * Sets additional MongoDB options.
     *
     * @param array<string, mixed> $options Options to set.
     * @return $this
     */
    public function options(array $options): static
    {
        $this->builder->options($options);

        return $this;
    }

    /**
     * Returns all documents as an array.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $result = $this->execute();

        return $result instanceof Traversable ? iterator_to_array($result, false) : [];
    }

    /**
     * Returns the first document or `null`.
     *
     * @return array<string, mixed>|null
     */
    public function first(): ?array
    {
        $documents = $this->all();

        return $documents[0] ?? null;
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

        return $connection->getCollection($this->collection)->countDocuments($this->builder->getFilter());
    }

    /**
     * Returns the distinct values for a field.
     *
     * @param string $field The field name.
     * @return list<mixed>
     */
    public function distinct(string $field): array
    {
        $connection = $this->getConnection();
        if (!$connection instanceof Connection) {
            return [];
        }

        return array_values($connection->getCollection($this->collection)->distinct($field, $this->builder->getFilter()));
    }

    /**
     * Returns an iterator over the executed results.
     *
     * @return \Traversable<int, array<string, mixed>>
     */
    public function getIterator(): Traversable
    {
        return $this->execute();
    }
}

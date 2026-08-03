<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Query;

use Closure;
use IteratorAggregate;
use Traversable;

/**
 * Select query for MongoDB find() and aggregation pipeline operations.
 *
 * @see cake50/src/Database/Query/SelectQuery.php
 */
class SelectQuery extends Query implements IteratorAggregate
{
    /**
     * @inheritDoc
     */
    public function where(array|string|Closure|null $conditions, bool $overwrite = false): static
    {
        $this->builder->where($conditions, $overwrite);

        return $this;
    }

    /**
     * Adds conditions with an `$and` operator.
     *
     * @param \Closure|array|string $conditions The conditions to add.
     * @return $this
     */
    public function andWhere(array|string|Closure $conditions): static
    {
        $this->builder->andWhere($conditions);

        return $this;
    }

    /**
     * Sets the field projection.
     *
     * @param array|string $fields Fields to include/exclude.
     * @param bool $overwrite Whether to overwrite the existing projection.
     * @return $this
     */
    public function select(array|string $fields, bool $overwrite = false): static
    {
        $this->builder->select($fields, $overwrite);

        return $this;
    }

    /**
     * Sets the sort order.
     *
     * @param array|string $fields Fields to sort by.
     * @param bool $overwrite Whether to overwrite the existing sort.
     * @return $this
     */
    public function orderBy(array|string $fields, bool $overwrite = false): static
    {
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
     * Adds aggregation pipeline stage(s).
     *
     * @param array $stages Pipeline stages to add.
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
     * @param array $options Options to set.
     * @return $this
     */
    public function options(array $options): static
    {
        $this->builder->options($options);

        return $this;
    }

    /**
     * Returns an iterator over the executed results.
     *
     * @return \Traversable
     */
    public function getIterator(): Traversable
    {
        return $this->execute();
    }
}

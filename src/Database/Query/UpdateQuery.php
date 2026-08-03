<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Query;

use Closure;

/**
 * Update query for MongoDB updateMany operations.
 *
 * `execute()` returns the number of modified documents.
 *
 * @see cake50/src/Database/Query/UpdateQuery.php
 */
class UpdateQuery extends Query
{
    /**
     * The compiled update operators (`$set`, `$unset`, `$inc`, ...).
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $update = [];

    /**
     * Sets the filter conditions.
     *
     * @param \Closure|array|string|null $conditions The conditions.
     * @param bool $overwrite Whether to overwrite existing conditions.
     * @return $this
     */
    public function where(array|string|Closure|null $conditions, bool $overwrite = false): static
    {
        $this->builder->where($conditions, $overwrite);

        return $this;
    }

    /**
     * Adds a `$set` assignment.
     *
     * @param array<string, mixed>|string $field Field name or map of field => value.
     * @param mixed $value The value (when `$field` is a single name).
     * @return $this
     */
    public function set(array|string $field, mixed $value = null): static
    {
        $this->update['$set'] = array_merge(
            $this->update['$set'] ?? [],
            is_array($field) ? $field : [$field => $value],
        );

        return $this;
    }

    /**
     * Adds a `$unset` for the given field(s).
     *
     * @param array<string>|string $field Field name or list of fields.
     * @return $this
     */
    public function unset(array|string $field): static
    {
        $fields = is_array($field) ? $field : [$field];
        $this->update['$unset'] = array_merge(
            $this->update['$unset'] ?? [],
            array_fill_keys($fields, ''),
        );

        return $this;
    }

    /**
     * Adds a `$inc` for the given field(s).
     *
     * @param array<string, int|float>|string $field Field name or map of field => amount.
     * @param float|int $amount The increment amount (when `$field` is a single name).
     * @return $this
     */
    public function increment(array|string $field, int|float $amount = 1): static
    {
        $this->update['$inc'] = array_merge(
            $this->update['$inc'] ?? [],
            is_array($field) ? $field : [$field => $amount],
        );

        return $this;
    }

    /**
     * Adds a negative `$inc` for the given field(s).
     *
     * @param array<string, int|float>|string $field Field name or map of field => amount.
     * @param float|int $amount The decrement amount (when `$field` is a single name).
     * @return $this
     */
    public function decrement(array|string $field, int|float $amount = 1): static
    {
        $map = is_array($field) ? $field : [$field => $amount];

        return $this->increment(array_map(
            static fn(int|float $value): int|float => -$value,
            $map,
        ));
    }

    /**
     * Adds a `$push` for the given field(s).
     *
     * @param array<string, mixed>|string $field Field name or map of field => value.
     * @param mixed $value The value to push (when `$field` is a single name).
     * @return $this
     */
    public function push(array|string $field, mixed $value = null): static
    {
        $this->update['$push'] = array_merge(
            $this->update['$push'] ?? [],
            is_array($field) ? $field : [$field => $value],
        );

        return $this;
    }

    /**
     * Adds a `$pull` for the given field(s).
     *
     * @param array<string, mixed>|string $field Field name or map of field => criteria.
     * @param mixed $value The value/criteria to pull (when `$field` is a single name).
     * @return $this
     */
    public function pull(array|string $field, mixed $value = null): static
    {
        $this->update['$pull'] = array_merge(
            $this->update['$pull'] ?? [],
            is_array($field) ? $field : [$field => $value],
        );

        return $this;
    }

    /**
     * Returns the compiled update operators.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getUpdate(): array
    {
        return $this->update;
    }

    /**
     * @inheritDoc
     */
    public function compile(): array
    {
        return [
            'type' => self::TYPE_UPDATE,
            'collection' => $this->collection,
            'filter' => $this->builder->getFilter(),
            'update' => $this->update,
            'options' => [],
        ];
    }
}

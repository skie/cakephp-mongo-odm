<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Query;

use Closure;
use Crustum\Mongo\Database\Expression\MongoExpressionInterface;

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
     * @param \Crustum\Mongo\Database\Expression\MongoExpressionInterface|\Closure|array|string|null $conditions The conditions.
     * @param bool $overwrite Whether to overwrite existing conditions.
     * @return $this
     */
    public function where(Closure|MongoExpressionInterface|array|string|null $conditions, bool $overwrite = false): static
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
     * Adds a `$addToSet` for the given field(s).
     *
     * @param array<string, mixed>|string $field Field name or map of field => value.
     * @param mixed $value The value to add (when `$field` is a single name).
     * @return $this
     */
    public function addToSet(array|string $field, mixed $value = null): static
    {
        $this->update['$addToSet'] = array_merge(
            $this->update['$addToSet'] ?? [],
            is_array($field) ? $field : [$field => $value],
        );

        return $this;
    }

    /**
     * Adds a `$pop` for the given field(s).
     *
     * @param array<string, -1|1>|string $field Field name or map of field => direction.
     * @param -1|1 $direction `1` removes the first element, `-1` the last (when `$field` is a single name).
     * @return $this
     */
    public function pop(array|string $field, int $direction = 1): static
    {
        $this->update['$pop'] = array_merge(
            $this->update['$pop'] ?? [],
            is_array($field) ? $field : [$field => $direction],
        );

        return $this;
    }

    /**
     * Adds a `$mul` for the given field(s).
     *
     * @param array<string, int|float>|string $field Field name or map of field => factor.
     * @param float|int $value The multiplier (when `$field` is a single name).
     * @return $this
     */
    public function multiply(array|string $field, int|float $value = 1): static
    {
        $this->update['$mul'] = array_merge(
            $this->update['$mul'] ?? [],
            is_array($field) ? $field : [$field => $value],
        );

        return $this;
    }

    /**
     * Adds a `$rename` for the given field(s).
     *
     * @param array<string, string>|string $field Field name or map of field => new name.
     * @param string|null $value The new field name (when `$field` is a single name).
     * @return $this
     */
    public function rename(array|string $field, ?string $value = null): static
    {
        $this->update['$rename'] = array_merge(
            $this->update['$rename'] ?? [],
            is_array($field) ? $field : [$field => $value],
        );

        return $this;
    }

    /**
     * Adds a `$min` for the given field(s).
     *
     * @param array<string, mixed>|string $field Field name or map of field => value.
     * @param mixed $value The minimum value (when `$field` is a single name).
     * @return $this
     */
    public function min(array|string $field, mixed $value = null): static
    {
        $this->update['$min'] = array_merge(
            $this->update['$min'] ?? [],
            is_array($field) ? $field : [$field => $value],
        );

        return $this;
    }

    /**
     * Adds a `$max` for the given field(s).
     *
     * @param array<string, mixed>|string $field Field name or map of field => value.
     * @param mixed $value The maximum value (when `$field` is a single name).
     * @return $this
     */
    public function max(array|string $field, mixed $value = null): static
    {
        $this->update['$max'] = array_merge(
            $this->update['$max'] ?? [],
            is_array($field) ? $field : [$field => $value],
        );

        return $this;
    }

    /**
     * Adds a `$currentDate` for the given field(s).
     *
     * @param array<string, string>|string $field Field name or map of field => type.
     * @param string|null $value The type (`date` or `timestamp`, when `$field` is a single name).
     * @return $this
     */
    public function currentDate(array|string $field, ?string $value = 'date'): static
    {
        $this->update['$currentDate'] = array_merge(
            $this->update['$currentDate'] ?? [],
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

<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Query;

use Cake\Database\ExpressionInterface;
use Closure;
use Crustum\Mongo\Database\Expression\QueryExpression;

/**
 * Delete query for MongoDB deleteMany operations.
 *
 * `execute()` returns the number of deleted documents.
 *
 * @see cake50/src/Database/Query/DeleteQuery.php
 */
class DeleteQuery extends Query
{
    /**
     * Whether to cascade delete related documents.
     *
     * @var bool
     */
    protected bool $cascade = false;

    /**
     * Sets the target collection to delete from.
     *
     * @param string|null $collection The collection name.
     * @return $this
     */
    public function delete(?string $collection = null): static
    {
        if ($collection !== null) {
            $this->collection = $collection;
        }

        return $this;
    }

    /**
     * Sets the filter conditions.
     *
     * A `Closure` receives `(QueryExpression $exp, DeleteQuery $query)` and must
     * return the conditions to merge into the filter.
     *
     * @param \Cake\Database\ExpressionInterface|\Closure|array<string, mixed>|string|null $conditions The conditions.
     * @param array<int|string, string>                                                    $types      Field => type map used to cast values.
     * @param bool                                                                         $overwrite  Whether to overwrite existing conditions.
     * @return $this
     */
    public function where(
        ExpressionInterface|Closure|array|string|null $conditions = [],
        array $types = [],
        bool $overwrite = false,
    ): static {
        if ($conditions instanceof Closure) {
            $exp = new QueryExpression();
            $conditions = $conditions($exp, $this) ?? $exp;
        }

        $types += $this->getDefaultTypes();
        $this->builder->where($conditions, $types, $overwrite);

        return $this;
    }

    /**
     * Enables/disables cascade deletion (handled by the ODM layer).
     *
     * @param bool $enabled Whether to cascade.
     * @return $this
     */
    public function cascade(bool $enabled = true): static
    {
        $this->cascade = $enabled;

        return $this;
    }

    /**
     * Returns whether cascade deletion is enabled.
     *
     * @return bool
     */
    public function isCascadeEnabled(): bool
    {
        return $this->cascade;
    }

    /**
     * @inheritDoc
     */
    public function compile(): array
    {
        return [
            'type' => self::TYPE_DELETE,
            'collection' => $this->collection,
            'filter' => $this->builder->getFilter(),
            'options' => [],
        ];
    }
}

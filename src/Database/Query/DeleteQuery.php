<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Query;

use Closure;

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

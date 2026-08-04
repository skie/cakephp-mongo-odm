<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Query;

/**
 * A `$setWindowFields` specification.
 *
 * Configures a partition expression, an optional sort order and the output
 * fields using window-function (AggregateExpression-equivalent) documents.
 *
 * @see \Crustum\Mongo\Database\Query\SelectQuery::window()
 */
class Window implements WindowInterface
{
    /**
     * The partition-by expression.
     *
     * @var array<string, mixed>|string|null
     */
    protected array|string|null $partitionBy = null;

    /**
     * The sort-by specification.
     *
     * @var array<string, int>|null
     */
    protected ?array $sortBy = null;

    /**
     * The output fields and their window-function expressions.
     *
     * @var array<string, mixed>
     */
    protected array $output = [];

    /**
     * Sets the partition-by expression.
     *
     * @param array<string, mixed>|string $expression The partition expression.
     * @return $this
     */
    public function partitionBy(array|string $expression): static
    {
        $this->partitionBy = $expression;

        return $this;
    }

    /**
     * Sets the sort-by specification.
     *
     * @param array<string, int> $sortBy The sort specification.
     * @return $this
     */
    public function sortBy(array $sortBy): static
    {
        $this->sortBy = $sortBy;

        return $this;
    }

    /**
     * Adds an output field with its window-function expression.
     *
     * @param string $field The field name.
     * @param array<string, mixed>|string $expression The window-function expression.
     * @return $this
     */
    public function output(string $field, array|string $expression): static
    {
        $this->output[$field] = $expression;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getWindow(): array
    {
        $window = ['output' => $this->output];

        if ($this->partitionBy !== null) {
            $window['partitionBy'] = $this->partitionBy;
        }

        if ($this->sortBy !== null) {
            $window['sortBy'] = $this->sortBy;
        }

        return $window;
    }
}

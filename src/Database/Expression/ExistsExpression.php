<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Expression;

use Closure;

class ExistsExpression extends AbstractExpression
{
    /**
     * The field name
     *
     * @var string
     */
    protected string $field;

    /**
     * Whether the field should exist
     *
     * @var bool
     */
    protected bool $exists;

    /**
     * Constructor
     *
     * @param string $field Field name
     * @param bool $exists Whether field should exist
     */
    public function __construct(string $field, bool $exists = true)
    {
        $this->field = $field;
        $this->exists = $exists;
    }

    /**
     * Traverse the expression tree
     *
     * @param \Closure $callback Callback function
     * @return $this
     */
    public function traverse(Closure $callback): static
    {
        $callback($this);

        return $this;
    }

    /**
     * Compile the expression to MongoDB query format
     *
     * @return array<string, mixed>
     */
    protected function compile(): array
    {
        $this->conditions = [
            $this->field => [
                '$exists' => $this->exists,
            ],
        ];

        return $this->conditions;
    }

    /**
     * Get the compiled conditions
     *
     * @return array<string, mixed>
     */
    public function getConditions(): array
    {
        return $this->compile();
    }
}

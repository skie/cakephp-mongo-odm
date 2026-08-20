<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Expression;

use Closure;
use Override;

class ModExpression extends AbstractExpression
{
    /**
     * The field name
     *
     * @var string
     */
    protected string $field;

    /**
     * The divisor
     *
     * @var int
     */
    protected int $divisor;

    /**
     * The remainder
     *
     * @var int
     */
    protected int $remainder;

    /**
     * Constructor
     *
     * @param string $field Field name
     * @param int $divisor Divisor
     * @param int $remainder Remainder
     */
    public function __construct(string $field, int $divisor, int $remainder)
    {
        $this->field = $field;
        $this->divisor = $divisor;
        $this->remainder = $remainder;
    }

    /**
     * Traverse the expression tree
     *
     * @param \Closure $callback Callback function
     * @return $this
     */
    #[Override]
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
                '$mod' => [$this->divisor, $this->remainder],
            ],
        ];

        return $this->conditions;
    }

    /**
     * Get the compiled conditions
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function getConditions(): array
    {
        return $this->compile();
    }
}

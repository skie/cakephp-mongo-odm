<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Expression;

use Closure;
use Override;

class RegexExpression extends AbstractExpression
{
    /**
     * The field name
     *
     * @var string
     */
    protected string $field;

    /**
     * The regex pattern
     *
     * @var string
     */
    protected string $pattern;

    /**
     * The regex options
     *
     * @var string
     */
    protected string $options;

    /**
     * Constructor
     *
     * @param string $field Field name
     * @param string $pattern Regex pattern
     * @param string $options Regex options
     */
    public function __construct(string $field, string $pattern, string $options = '')
    {
        $this->field = $field;
        $this->pattern = $pattern;
        $this->options = $options;
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
                '$regex' => $this->pattern,
                '$options' => $this->options,
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

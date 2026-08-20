<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Expression;

use Closure;
use Override;

class TextExpression extends AbstractExpression
{
    /**
     * The search text
     *
     * @var string
     */
    protected string $search;

    /**
     * The text search options
     *
     * @var array<string, mixed>
     */
    protected array $options;

    /**
     * Constructor
     *
     * @param string $search Search text
     * @param array<string, mixed> $options Text search options
     */
    public function __construct(string $search, array $options = [])
    {
        $this->search = $search;
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
        $query = [
            '$text' => [
                '$search' => $this->search,
            ],
        ];

        foreach (['language', 'caseSensitive', 'diacriticSensitive'] as $option) {
            if (isset($this->options[$option])) {
                $query['$text']['$' . $option] = $this->options[$option];
            }
        }

        $this->conditions = $query;

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

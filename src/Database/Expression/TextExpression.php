<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Expression;

use Closure;

class TextExpression extends AbstractExpression
{
    protected string $_search;

    protected array $_options;

    /**
     * Constructor
     *
     * @param string $search Search text
     * @param array $options Text search options
     */
    public function __construct(string $search, array $options = [])
    {
        $this->_search = $search;
        $this->_options = $options;
        $this->_compile();
    }

    /**
     * Traverse the expression tree
     *
     * @param \Closure $callback Callback function
     * @return $this
     */
    public function traverse(Closure $callback)
    {
        $callback($this);

        return $this;
    }

    /**
     * Compile the expression to MongoDB query format
     *
     * @return array
     */
    protected function _compile(): array
    {
        $query = [
            '$text' => [
                '$search' => $this->_search,
            ],
        ];

        foreach (['language', 'caseSensitive', 'diacriticSensitive'] as $option) {
            if (isset($this->_options[$option])) {
                $query['$text']['$' . $option] = $this->_options[$option];
            }
        }

        $this->_conditions = $query;

        return $this->_conditions;
    }

    /**
     * Get the compiled conditions
     *
     * @return array
     */
    public function getConditions(): array
    {
        return $this->_compile();
    }
}

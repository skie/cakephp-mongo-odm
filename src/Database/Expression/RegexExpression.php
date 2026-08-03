<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Expression;

use Closure;

class RegexExpression extends AbstractExpression
{
    protected string $_field;

    protected string $_pattern;

    protected string $_options;

    /**
     * Constructor
     *
     * @param string $field Field name
     * @param string $pattern Regex pattern
     * @param string $options Regex options
     */
    public function __construct(string $field, string $pattern, string $options = '')
    {
        $this->_field = $field;
        $this->_pattern = $pattern;
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
        $this->_conditions = [
            $this->_field => [
                '$regex' => $this->_pattern,
                '$options' => $this->_options,
            ],
        ];

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

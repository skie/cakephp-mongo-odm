<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Expression;

use Closure;

class ExistsExpression extends AbstractExpression
{
    protected string $_field;

    protected bool $_exists;

    /**
     * Constructor
     *
     * @param string $field Field name
     * @param bool $exists Whether field should exist
     */
    public function __construct(string $field, bool $exists = true)
    {
        $this->_field = $field;
        $this->_exists = $exists;
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
                '$exists' => $this->_exists,
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

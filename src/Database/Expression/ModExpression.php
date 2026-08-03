<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Expression;

use Closure;

class ModExpression extends AbstractExpression
{
    protected string $_field;

    protected int $_divisor;

    protected int $_remainder;

    /**
     * Constructor
     *
     * @param string $field Field name
     * @param int $divisor Divisor
     * @param int $remainder Remainder
     */
    public function __construct(string $field, int $divisor, int $remainder)
    {
        $this->_field = $field;
        $this->_divisor = $divisor;
        $this->_remainder = $remainder;
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
                '$mod' => [$this->_divisor, $this->_remainder],
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

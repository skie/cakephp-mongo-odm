<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Expression;

use Closure;

class TypeExpression extends AbstractExpression
{
    public const BSON_TYPES = [
        'double' => 1,
        'string' => 2,
        'object' => 3,
        'array' => 4,
        'binary' => 5,
        'objectId' => 7,
        'boolean' => 8,
        'date' => 9,
        'null' => 10,
        'regex' => 11,
        'int' => 16,
        'timestamp' => 17,
        'long' => 18,
        'decimal' => 19,
    ];

    protected string $_field;

    protected string|int $_type;

    /**
     * Constructor
     *
     * @param string $field Field name
     * @param string|int $type BSON type name or number
     */
    public function __construct(string $field, string|int $type)
    {
        $this->_field = $field;
        $this->_type = $type;
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
        $type = is_string($this->_type) ? self::BSON_TYPES[$this->_type] : $this->_type;

        $this->_conditions = [
            $this->_field => [
                '$type' => $type,
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

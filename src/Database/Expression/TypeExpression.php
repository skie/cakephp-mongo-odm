<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Expression;

use Closure;
use Override;

class TypeExpression extends AbstractExpression
{
    /**
     * BSON type name to numeric type code mapping
     *
     * @var array<string, int>
     */
    public const array BSON_TYPES = [
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

    /**
     * The field name
     *
     * @var string
     */
    protected string $field;

    /**
     * The BSON type name or code
     *
     * @var string|int
     */
    protected string|int $type;

    /**
     * Constructor
     *
     * @param string $field Field name
     * @param string|int $type BSON type name or number
     */
    public function __construct(string $field, string|int $type)
    {
        $this->field = $field;
        $this->type = $type;
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
        $type = is_string($this->type) ? self::BSON_TYPES[$this->type] : $this->type;

        $this->conditions = [
            $this->field => [
                '$type' => $type,
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

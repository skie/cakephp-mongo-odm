<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Expression;

use Closure;

class GeospatialExpression extends AbstractExpression
{
    /**
     * The field name
     *
     * @var string
     */
    protected string $field;

    /**
     * The geometry data
     *
     * @var array<string, mixed>
     */
    protected array $geometry;

    /**
     * The geospatial operator
     *
     * @var string
     */
    protected string $operator;

    /**
     * Additional options
     *
     * @var array<string, mixed>
     */
    protected array $options;

    /**
     * Constructor
     *
     * @param string $field Field name
     * @param array<string, mixed> $geometry Geometry data
     * @param string $operator Geospatial operator
     * @param array<string, mixed> $options Additional options
     */
    public function __construct(string $field, array $geometry, string $operator, array $options = [])
    {
        $this->field = $field;
        $this->geometry = $geometry;
        $this->operator = $operator;
        $this->options = $options;
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
        $query = [
            $this->field => [
                $this->operator => [
                    '$geometry' => $this->geometry,
                ],
            ],
        ];

        if ($this->operator === '$near') {
            if (isset($this->options['maxDistance'])) {
                $query[$this->field][$this->operator]['$maxDistance'] = $this->options['maxDistance'];
            }

            if (isset($this->options['minDistance'])) {
                $query[$this->field][$this->operator]['$minDistance'] = $this->options['minDistance'];
            }
        }

        $this->conditions = $query;

        return $this->conditions;
    }

    /**
     * Get the compiled conditions
     *
     * @return array<int|string, mixed>
     */
    public function getConditions(): array
    {
        return $this->conditions;
    }
}

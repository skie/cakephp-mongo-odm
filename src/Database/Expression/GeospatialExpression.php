<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Expression;

use Closure;

class GeospatialExpression extends AbstractExpression
{
    protected string $_field;

    protected array $_geometry;

    protected string $_operator;

    protected array $_options;

    /**
     * Constructor
     *
     * @param string $field Field name
     * @param array $geometry Geometry data
     * @param string $operator Geospatial operator
     * @param array $options Additional options
     */
    public function __construct(string $field, array $geometry, string $operator, array $options = [])
    {
        $this->_field = $field;
        $this->_geometry = $geometry;
        $this->_operator = $operator;
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

        if ($this->_geometry instanceof MongoExpressionInterface) {
            $this->_geometry->traverse($callback);
        }

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
            $this->_field => [
                $this->_operator => [
                    '$geometry' => $this->_geometry,
                ],
            ],
        ];

        if ($this->_operator === '$near') {
            if (isset($this->_options['maxDistance'])) {
                $query[$this->_field][$this->_operator]['$maxDistance'] = $this->_options['maxDistance'];
            }

            if (isset($this->_options['minDistance'])) {
                $query[$this->_field][$this->_operator]['$minDistance'] = $this->_options['minDistance'];
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
        return $this->_conditions;
    }
}

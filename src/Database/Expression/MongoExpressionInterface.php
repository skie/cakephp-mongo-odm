<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Expression;

use Cake\Database\ExpressionInterface;

/**
 * Interface for MongoDB expressions
 */
interface MongoExpressionInterface extends ExpressionInterface
{
    /**
     * Returns the MongoDB query conditions
     *
     * @return array
     */
    public function getConditions(): array;
}

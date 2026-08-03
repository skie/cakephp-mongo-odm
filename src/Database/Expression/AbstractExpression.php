<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Expression;

use Cake\Database\ValueBinder;

/**
 * Base class for MongoDB expressions
 */
abstract class AbstractExpression extends Expression implements MongoExpressionInterface
{
    /**
     * The MongoDB query conditions
     *
     * @var array
     */
    protected array $_conditions = [];

    /**
     * @inheritDoc
     */
    public function sql(ValueBinder $binder): string
    {
        return json_encode($this->getConditions());
    }

    /**
     * @inheritDoc
     */
    public function getConditions(): array
    {
        return $this->_conditions;
    }
}

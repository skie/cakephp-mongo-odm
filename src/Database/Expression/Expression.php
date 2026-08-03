<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Expression;

use Cake\Database\ExpressionInterface;
use Cake\Database\ValueBinder;
use Closure;

abstract class Expression implements ExpressionInterface
{
    /**
     * @inheritDoc
     */
    abstract public function sql(ValueBinder $binder): string;

    /**
     * @inheritDoc
     */
    public function traverse(Closure $callback)
    {
        $callback($this);

        return $this;
    }
}

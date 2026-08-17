<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database;

use Crustum\Mongo\Database\Expression\IdentifierExpression;
use Crustum\Mongo\Database\Expression\UpdateOperatorExpression;

/**
 * Fluent factory for Mongo update operator expressions.
 *
 * Access via `UpdateQuery::func()`. Values returned from here are meant as the
 * right-hand side of `set()` / `updateAll()` field maps — the field name on the
 * left selects the document path to update.
 *
 * ```php
 * $query->set(['post_count' => $query->func()->inc()]);
 * ```
 */
class UpdateFunctionsBuilder extends FunctionsBuilder
{
    /**
     * Atomic increment (`$inc`).
     *
     * @param float|int $amount Delta to add (default 1).
     * @return \Crustum\Mongo\Database\Expression\UpdateOperatorExpression
     */
    public function inc(int|float $amount = 1): UpdateOperatorExpression
    {
        return new UpdateOperatorExpression('$inc', $amount);
    }

    /**
     * Atomic decrement (`$inc` with a negative delta).
     *
     * @param float|int $amount Amount to subtract (default 1).
     * @return \Crustum\Mongo\Database\Expression\UpdateOperatorExpression
     */
    public function dec(int|float $amount = 1): UpdateOperatorExpression
    {
        return new UpdateOperatorExpression('$inc', -$amount);
    }

    /**
     * Atomic multiply (`$mul`).
     *
     * @param float|int $factor Multiplier.
     * @return \Crustum\Mongo\Database\Expression\UpdateOperatorExpression
     */
    public function mul(int|float $factor): UpdateOperatorExpression
    {
        return new UpdateOperatorExpression('$mul', $factor);
    }

    /**
     * SQL-style `field = field + n` sugar — resolves to `$inc` on the set key.
     *
     * When the increment amount is omitted, adds 1. When a field identifier is
     * passed, it is ignored (the map key in `set()` names the target field).
     *
     * @param \Crustum\Mongo\Database\Expression\IdentifierExpression|float|int $first Increment amount or field reference.
     * @param float|int|null $second Increment amount when `$first` is a field reference.
     * @return \Crustum\Mongo\Database\Expression\UpdateOperatorExpression
     */
    public function plus(
        int|float|IdentifierExpression $first,
        int|float|null $second = null,
    ): UpdateOperatorExpression {
        if ($first instanceof IdentifierExpression) {
            return $this->inc($second ?? 1);
        }

        return $this->inc($first);
    }
}

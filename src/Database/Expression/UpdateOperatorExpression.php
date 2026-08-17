<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Expression;

use Cake\Database\ValueBinder;

/**
 * Marks an update assignment as a Mongo update operator (`$inc`, `$mul`, …).
 *
 * Used as the value in `UpdateQuery::set(['field' => $query->func()->inc()])`
 * so `updateAll()` can express atomic updates without SQL assignment strings.
 */
class UpdateOperatorExpression extends Expression implements MongoExpressionInterface
{
    /**
     * @param string $operator Mongo update operator (`$inc`, `$mul`, …).
     * @param mixed|float|int $amount Operator payload (for example increment delta).
     */
    public function __construct(
        protected string $operator,
        protected mixed $amount,
    ) {
        $this->operator = ltrim($operator, '$') === $operator ? '$' . $operator : $operator;
    }

    /**
     * @return string
     */
    public function getOperator(): string
    {
        return $this->operator;
    }

    /**
     * @return mixed|float|int
     */
    public function getAmount(): mixed
    {
        return $this->amount;
    }

    /**
     * @inheritDoc
     */
    public function getConditions(): array
    {
        return [$this->operator => $this->amount];
    }

    /**
     * @inheritDoc
     */
    public function sql(ValueBinder $binder): string
    {
        return json_encode($this->getConditions()) ?: '{}';
    }
}

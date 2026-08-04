<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Aggregation\Stage;

use Crustum\Mongo\Database\Aggregation\AggregationBuilder;

/**
 * Wraps a literal stage array (`['$operator' => value]`) as a Stage object.
 *
 * Keeps the pipeline storage uniform: every stage is a `Stage`, never a raw
 * array. Used by `AggregationBuilder::addStage()` for custom/unknown stages.
 */
class RawStage extends Stage
{
    /**
     * The literal stage expression.
     *
     * @var array<string, mixed>
     */
    protected array $expression;

    /**
     * Constructor
     *
     * @param \Crustum\Mongo\Database\Aggregation\AggregationBuilder $builder The aggregation builder.
     * @param string                                             $operator The stage operator (e.g. '$limit', '$skip').
     * @param array<string, mixed>|string|int                    $value    The stage specification or value.
     */
    public function __construct(AggregationBuilder $builder, string $operator, array|int|string $value)
    {
        parent::__construct($builder);

        if (is_array($value)) {
            $this->expression = [$operator => $value];

            return;
        }

        $this->expression = [$operator => [$operator === '$limit' ? 'limit' : 'value' => $value]];
    }

    /**
     * Wrap an existing wire-format stage array verbatim.
     *
     * Unlike the constructor (which normalizes scalar values for the builder's
     * convenience form), this preserves the stage array exactly as given.
     *
     * @param \Crustum\Mongo\Database\Aggregation\AggregationBuilder $builder    The aggregation builder.
     * @param array<string, mixed>                                  $expression The stage expression.
     * @return static
     */
    public static function fromWire(AggregationBuilder $builder, array $expression): static
    {
        $stage = new static($builder, (string)array_key_first($expression), reset($expression));
        $stage->expression = $expression;

        return $stage;
    }

    /**
     * Get the MongoDB aggregation stage expression.
     *
     * @return array<string, mixed> The literal stage expression.
     */
    public function getExpression(): array
    {
        return $this->expression;
    }
}

<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Expression;

use Closure;
use InvalidArgumentException;

/**
 * Matches documents whose field tuple is one of several value tuples.
 *
 * Single-field tuples compile to a `$in` filter. Composite tuples compile to
 * `$or` of compound equality objects — the Mongo equivalent of SQL
 * `(a, b) IN ((1, 10), (2, 20))`.
 */
class TupleInExpression extends AbstractExpression
{
    /**
     * The target field names.
     *
     * @var list<string>
     */
    protected array $fields;

    /**
     * The tuple values to match.
     *
     * Each row is a list of values aligned with {@see $fields}.
     *
     * @var list<list<mixed>>
     */
    protected array $tuples;

    /**
     * Constructor.
     *
     * @param list<string> $fields Field names.
     * @param list<list<mixed>> $tuples Tuple rows.
     */
    public function __construct(array $fields, array $tuples)
    {
        if ($fields === []) {
            throw new InvalidArgumentException('TupleInExpression requires at least one field.');
        }

        $this->fields = array_values($fields);
        $this->tuples = array_map(
            function (array $tuple): array {
                if (count($tuple) !== count($this->fields)) {
                    throw new InvalidArgumentException(sprintf(
                        'TupleInExpression expected %d values, got %d.',
                        count($this->fields),
                        count($tuple),
                    ));
                }

                return array_values($tuple);
            },
            array_values($tuples),
        );
    }

    /**
     * Traverse the expression tree.
     *
     * @param \Closure $callback Callback function.
     * @return $this
     */
    public function traverse(Closure $callback): static
    {
        $callback($this);

        return $this;
    }

    /**
     * Compile the expression to MongoDB query format.
     *
     * @return array<string, mixed>
     */
    protected function compile(): array
    {
        if ($this->tuples === []) {
            $this->conditions = [];

            return $this->conditions;
        }

        if (count($this->fields) === 1) {
            $values = array_map(
                static fn(array $tuple): mixed => $tuple[0],
                $this->tuples,
            );
            $this->conditions = (new InExpression($this->fields[0], $values))->getConditions();

            return $this->conditions;
        }

        $branches = [];
        foreach ($this->tuples as $tuple) {
            $branch = [];
            foreach ($this->fields as $i => $field) {
                $branch[$field] = $tuple[$i];
            }
            $branches[] = $branch;
        }

        $this->conditions = ['$or' => $branches];

        return $this->conditions;
    }

    /**
     * Get the compiled conditions.
     *
     * @return array<string, mixed>
     */
    public function getConditions(): array
    {
        return $this->compile();
    }
}

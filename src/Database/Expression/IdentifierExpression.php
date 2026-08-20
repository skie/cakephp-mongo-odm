<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Expression;

/**
 * Represents a reference to a document field inside a comparison.
 *
 * Wraps a bare field name so `ComparisonExpression` can build `$expr`
 * field-to-field comparisons (`equalFields`), the Mongo analog of cake's
 * `IdentifierExpression` in SQL joins.
 *
 * @inspired-by \Cake\Database\Expression\IdentifierExpression
 */
class IdentifierExpression extends AbstractExpression
{
    /**
     * The field name.
     *
     * @var string
     */
    protected string $identifier;

    /**
     * Constructor.
     *
     * @param string $identifier The field name.
     */
    public function __construct(string $identifier)
    {
        $this->identifier = $identifier;
    }

    /**
     * Gets the wrapped field name.
     *
     * @return string
     */
    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    /**
     * Returns the Mongo field reference (`$field`).
     *
     * @return array<string, string>
     */
    public function getConditions(): array
    {
        return ['$field' => $this->identifier];
    }

    /**
     * Compiles to the `$expr` field path form.
     *
     * @return array<string, string>
     */
    public function compile(): array
    {
        return $this->getConditions();
    }
}

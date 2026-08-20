<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Type;

/**
 * Base type class
 *
 * Provides default implementations for TypeInterface
 *
 * @ported-from \Cake\Database\Type\BaseType
 */
abstract class BaseType implements TypeInterface
{
    /**
     * Identifier name for this type
     *
     * @var string|null
     */
    protected ?string $name = null;

    /**
     * Constructor
     *
     * @param string|null $name The name identifying this type
     */
    public function __construct(?string $name = null)
    {
        $this->name = $name;
    }

    /**
     * @inheritDoc
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * @inheritDoc
     */
    public function getBaseType(): ?string
    {
        return $this->name;
    }

    /**
     * @inheritDoc
     */
    public function marshal(mixed $value): mixed
    {
        return $value;
    }

    /**
     * @inheritDoc
     */
    public function newId(): mixed
    {
        return null;
    }
}

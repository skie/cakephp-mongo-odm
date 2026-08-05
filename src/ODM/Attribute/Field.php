<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Attribute;

use Attribute;

/**
 * Declares a field mapping on a Document or DTO.
 *
 * On a concrete `Document` class this attribute is repeatable at class level:
 * the document stores values through the `EntityInterface` field map, so field
 * metadata is declared on the class rather than on ordinary PHP properties.
 *
 * ```php
 * #[Field(name: '_id', type: 'objectId', primaryKey: true)]
 * #[Field(name: 'username', type: 'string', nullable: false)]
 * final class User extends Document
 * {
 * }
 * ```
 *
 * On a DTO the attribute can be placed on a promoted constructor parameter to
 * map the Mongo field name or override the inferred type:
 *
 * ```php
 * final readonly class UserDto
 * {
 *     public function __construct(
 *         #[Field(name: '_id', type: 'objectId')]
 *         public string $id,
 *         public string $username,
 *     ) {
 *     }
 * }
 * ```
 *
 * @see docs/reference/11-entity-type-sugar.md
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
final class Field
{
    /**
     * @param string|null        $name       BSON field name; defaults to the property/parameter name
     * @param string|null        $type       TypeFactory type name; defaults to inferred from the PHP type
     * @param bool               $nullable   Whether the field may be null
     * @param bool               $primaryKey Marks the `_id` / identifier field
     * @param bool               $notSaved   Never persisted, never read from the database
     * @param class-string|null  $enumType   Backed enum class used for enum casting
     */
    public function __construct(
        protected ?string $name = null,
        protected ?string $type = null,
        protected bool $nullable = false,
        protected bool $primaryKey = false,
        protected bool $notSaved = false,
        protected ?string $enumType = null,
    ) {
    }

    /**
     * BSON field name.
     *
     * @return string|null
     */
    public function name(): ?string
    {
        return $this->name;
    }

    /**
     * TypeFactory type name.
     *
     * @return string|null
     */
    public function type(): ?string
    {
        return $this->type;
    }

    /**
     * Whether the field may be null.
     *
     * @return bool
     */
    public function nullable(): bool
    {
        return $this->nullable;
    }

    /**
     * Whether this is the primary key field.
     *
     * @return bool
     */
    public function primaryKey(): bool
    {
        return $this->primaryKey;
    }

    /**
     * Whether the field is never persisted.
     *
     * @return bool
     */
    public function notSaved(): bool
    {
        return $this->notSaved;
    }

    /**
     * Backed enum class used for enum casting.
     *
     * @return class-string|null
     */
    public function enumType(): ?string
    {
        return $this->enumType;
    }
}

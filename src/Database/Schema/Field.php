<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Schema;

use Crustum\Mongo\Database\Type\TypeFactory;
use RuntimeException;

/**
 * Schema metadata for a single MongoDB field.
 *
 * Ported from `Cake\Database\Schema\Column` and adapted for MongoDB: the SQL
 * engine/collation/geometry concepts do not apply, while Mongo/ODM concepts
 * such as `enumType`, `primaryKey` and `notSaved` are added.
 *
 * Used by `CollectionSchema` when reflecting schema or composing an
 * application-side field definition.
 */
class Field
{
    /**
     * Constructor.
     *
     * @param string $name Name of the field
     * @param string|null $type Canonical `TypeFactory` type name (e.g. `objectid`, `string`), or null when undefined (naming-convention inference applies)
     * @param bool|null $null Whether the field allows null values
     * @param mixed $default Default value for the field
     * @param int|null $length Length hint for string-like fields
     * @param int|null $precision Precision for decimal fields
     * @param bool $identity Whether the field is the primary key (`_id`)
     * @param string|null $comment Comment for the field
     * @param string|null $baseType The base schema type if the field type is complex/custom (e.g. enum → its backing type)
     * @param class-string<\BackedEnum>|null $enumType Backed enum class used for enum casting
     * @param bool $notSaved Never persisted, never read from the database
     */
    public function __construct(
        protected string $name,
        protected ?string $type = null,
        protected ?bool $null = null,
        protected mixed $default = null,
        protected ?int $length = null,
        protected ?int $precision = null,
        protected bool $identity = false,
        protected ?string $comment = null,
        protected ?string $baseType = null,
        protected ?string $enumType = null,
        protected bool $notSaved = false,
    ) {
    }

    /**
     * Sets the field name.
     *
     * @param string $name Name
     * @return $this
     */
    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Gets the field name.
     *
     * @return string|null
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * Get the base type if defined. Will fallback to `type` if not set.
     *
     * Used to get the base type of a field when the field type is a complex/custom type
     * such as a backed enum.
     *
     * @return string|null
     */
    public function getBaseType(): ?string
    {
        if (isset($this->baseType)) {
            return $this->baseType;
        }

        $type = $this->type ?? 'string';
        $mapped = TypeFactory::getMapped($type);
        if ($mapped !== null) {
            $built = TypeFactory::build($type);
            $type = (string)$built->getBaseType();
        }

        return $this->baseType = $type;
    }

    /**
     * Sets the base type of the field.
     *
     * @param string|null $baseType Base type
     * @return $this
     */
    public function setBaseType(?string $baseType): static
    {
        $this->baseType = $baseType;

        return $this;
    }

    /**
     * Sets the field type.
     *
     * Type names are not validated, as drivers may implement platform specific
     * types that are not known by the plugin.
     *
     * @param string $type Field type
     * @return $this
     */
    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    /**
     * Gets the field type.
     *
     * @return string|null The type, or null when undefined (inference applies)
     */
    public function getType(): ?string
    {
        return $this->type;
    }

    /**
     * Sets the field length hint.
     *
     * @param int|null $length Length
     * @return $this
     */
    public function setLength(?int $length): static
    {
        $this->length = $length;

        return $this;
    }

    /**
     * Gets the field length hint.
     *
     * @return int|null
     */
    public function getLength(): ?int
    {
        return $this->length;
    }

    /**
     * Sets whether the field allows nulls.
     *
     * @param bool $null Null
     * @return $this
     */
    public function setNull(bool $null): static
    {
        $this->null = $null;

        return $this;
    }

    /**
     * Gets whether the field allows nulls.
     *
     * @return bool|null
     */
    public function getNull(): ?bool
    {
        return $this->null;
    }

    /**
     * Does the field allow nulls?
     *
     * @return bool
     */
    public function isNull(): bool
    {
        return $this->getNull() === true;
    }

    /**
     * Sets the default field value.
     *
     * @param mixed $default Default
     * @return $this
     */
    public function setDefault(mixed $default): static
    {
        $this->default = $default;

        return $this;
    }

    /**
     * Gets the default field value.
     *
     * @return mixed
     */
    public function getDefault(): mixed
    {
        return $this->default;
    }

    /**
     * Sets whether the field is the primary key (`_id`).
     *
     * @param bool $identity Identity
     * @return $this
     */
    public function setIdentity(bool $identity): static
    {
        $this->identity = $identity;

        return $this;
    }

    /**
     * Gets whether the field is the primary key (`_id`).
     *
     * @return bool
     */
    public function getIdentity(): bool
    {
        return $this->identity;
    }

    /**
     * Is the field the primary key (`_id`)?
     *
     * @return bool
     */
    public function isIdentity(): bool
    {
        return $this->getIdentity();
    }

    /**
     * Sets the number precision for decimal fields.
     *
     * @param int|null $precision Number precision
     * @return $this
     */
    public function setPrecision(?int $precision): static
    {
        $this->precision = $precision;

        return $this;
    }

    /**
     * Gets the number precision for decimal fields.
     *
     * @return int|null
     */
    public function getPrecision(): ?int
    {
        return $this->precision;
    }

    /**
     * Sets the field comment.
     *
     * @param string|null $comment Comment
     * @return $this
     */
    public function setComment(?string $comment): static
    {
        $this->comment = $comment;

        return $this;
    }

    /**
     * Gets the field comment.
     *
     * @return string|null
     */
    public function getComment(): ?string
    {
        return $this->comment;
    }

    /**
     * Sets the backed enum class used for enum casting.
     *
     * @param class-string<\BackedEnum>|null $enumType Enum class
     * @return $this
     */
    public function setEnumType(?string $enumType): static
    {
        $this->enumType = $enumType;

        return $this;
    }

    /**
     * Gets the backed enum class used for enum casting.
     *
     * @return class-string<\BackedEnum>|null
     */
    public function getEnumType(): ?string
    {
        return $this->enumType;
    }

    /**
     * Sets whether the field is never persisted.
     *
     * @param bool $notSaved Not saved
     * @return $this
     */
    public function setNotSaved(bool $notSaved): static
    {
        $this->notSaved = $notSaved;

        return $this;
    }

    /**
     * Gets whether the field is never persisted.
     *
     * @return bool
     */
    public function getNotSaved(): bool
    {
        return $this->notSaved;
    }

    /**
     * Gets all allowed options. Each option must have a corresponding `setFoo` method.
     *
     * @return array<int, string>
     */
    protected function getValidOptions(): array
    {
        return [
            'name',
            'type',
            'null',
            'default',
            'length',
            'precision',
            'identity',
            'comment',
            'baseType',
            'enumType',
            'notSaved',
        ];
    }

    /**
     * Utility method that maps an array of field attributes to this object's methods.
     *
     * @param array<string, mixed> $attributes Attributes
     * @throws \RuntimeException
     * @return $this
     */
    public function setAttributes(array $attributes): static
    {
        $validOptions = $this->getValidOptions();

        foreach ($attributes as $attribute => $value) {
            if (!in_array($attribute, $validOptions, true)) {
                throw new RuntimeException(sprintf('"%s" is not a valid field option.', $attribute));
            }

            $method = 'set' . ucfirst($attribute);
            $this->$method($value);
        }

        return $this;
    }

    /**
     * Convert this field into an array that is compatible with the Field constructor.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->getName(),
            'type' => $this->getType(),
            'baseType' => $this->getBaseType(),
            'null' => $this->getNull(),
            'default' => $this->getDefault(),
            'length' => $this->getLength(),
            'precision' => $this->getPrecision(),
            'identity' => $this->getIdentity(),
            'comment' => $this->getComment(),
            'enumType' => $this->getEnumType(),
            'notSaved' => $this->getNotSaved(),
        ];
    }

    /**
     * Builds a Field from an attribute array (e.g. from a schema definition map).
     *
     * @param string $name Field name
     * @param array<string, mixed>|string $attrs Field attributes or a type string
     * @return static
     */
    public static function fromAttributes(string $name, array|string $attrs): static
    {
        if (is_string($attrs)) {
            $attrs = ['type' => $attrs];
        }

        $attrs['name'] = $name;

        return new static(
            $attrs['name'],
            $attrs['type'] ?? null,
            $attrs['null'] ?? null,
            $attrs['default'] ?? null,
            isset($attrs['length']) ? (int)$attrs['length'] : null,
            isset($attrs['precision']) ? (int)$attrs['precision'] : null,
            (bool)($attrs['identity'] ?? $attrs['primaryKey'] ?? false),
            $attrs['comment'] ?? null,
            $attrs['baseType'] ?? null,
            $attrs['enumType'] ?? null,
            (bool)($attrs['notSaved'] ?? false),
        );
    }
}

<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Schema;

/**
 * MongoDB `$jsonSchema` validator value object.
 *
 * Builds a MongoDB JSON Schema validator from a fluent API:
 *
 * ```php
 * $validator = new Validator();
 * $validator->field('title', ['bsonType' => 'string'])
 *     ->field('author_id', ['bsonType' => 'objectId'])
 *     ->required(['title']);
 * ```
 *
 * `additionalProperties` defaults to `true` so legacy documents with extra
 * fields never invalidate under `moderate` validation.
 */
class Validator
{
    /**
     * The schema root type.
     *
     * @var string
     */
    protected string $bsonType = 'object';

    /**
     * Field definitions keyed by field name.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $properties = [];

    /**
     * Names of required fields.
     *
     * @var list<string>
     */
    protected array $required = [];

    /**
     * Whether extra fields not declared in `properties` are allowed.
     *
     * @var bool
     */
    protected bool $additionalProperties = true;

    /**
     * Whether the collection-level `required` applies. `false` when the
     * validator was built without required fields, so the emitted `$jsonSchema`
     * omits the key rather than emitting `required: []`.
     *
     * @var bool
     */
    protected bool $hasRequired = false;

    /**
     * Constructor.
     *
     * @param array<string, mixed>|null $validator An existing `$jsonSchema` array to adopt, or null for an empty validator.
     */
    public function __construct(?array $validator = null)
    {
        if ($validator === null) {
            return;
        }

        $jsonSchema = $validator['$jsonSchema'] ?? $validator;
        if (!is_array($jsonSchema)) {
            return;
        }

        $this->bsonType = (string)($jsonSchema['bsonType'] ?? 'object');
        $this->properties = (array)($jsonSchema['properties'] ?? []);
        $this->additionalProperties = (bool)($jsonSchema['additionalProperties'] ?? true);

        if (isset($jsonSchema['required'])) {
            $this->required = array_values(array_map(strval(...), (array)$jsonSchema['required']));
            $this->hasRequired = $this->required !== [];
        }
    }

    /**
     * Sets the schema root type.
     *
     * @param string $bsonType Root BSON type
     * @return $this
     */
    public function setBsonType(string $bsonType): static
    {
        $this->bsonType = $bsonType;

        return $this;
    }

    /**
     * Gets the schema root type.
     *
     * @return string
     */
    public function getBsonType(): string
    {
        return $this->bsonType;
    }

    /**
     * Adds or replaces a field definition.
     *
     * @param string $name Field name
     * @param array<string, mixed> $definition Field definition (bsonType, description, enum, …)
     * @return $this
     */
    public function field(string $name, array $definition): static
    {
        $this->properties[$name] = $definition;

        return $this;
    }

    /**
     * Returns the definition of a field.
     *
     * @param string $name Field name
     * @return array<string, mixed>|null Field definition or null
     */
    public function getField(string $name): ?array
    {
        return $this->properties[$name] ?? null;
    }

    /**
     * Returns whether a field is declared.
     *
     * @param string $name Field name
     * @return bool
     */
    public function hasField(string $name): bool
    {
        return isset($this->properties[$name]);
    }

    /**
     * Removes a field definition.
     *
     * @param string $name Field name
     * @return $this
     */
    public function removeField(string $name): static
    {
        unset($this->properties[$name]);

        return $this;
    }

    /**
     * Returns all field definitions.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getProperties(): array
    {
        return $this->properties;
    }

    /**
     * Sets the list of required fields.
     *
     * @param list<string> $required Field names
     * @return $this
     */
    public function setRequired(array $required): static
    {
        $this->required = $required;
        $this->hasRequired = $this->required !== [];

        return $this;
    }

    /**
     * Adds one or more required fields.
     *
     * @param string ...$fields Field names
     * @return $this
     */
    public function addRequired(string ...$fields): static
    {
        $this->required = array_values(array_unique(array_merge($this->required, $fields)));
        $this->hasRequired = true;

        return $this;
    }

    /**
     * Returns the list of required fields.
     *
     * @return list<string>
     */
    public function getRequired(): array
    {
        return $this->required;
    }

    /**
     * Sets whether extra undeclared fields are allowed.
     *
     * @param bool $additionalProperties Whether additional properties are allowed
     * @return $this
     */
    public function setAdditionalProperties(bool $additionalProperties): static
    {
        $this->additionalProperties = $additionalProperties;

        return $this;
    }

    /**
     * Gets whether extra undeclared fields are allowed.
     *
     * @return bool
     */
    public function getAdditionalProperties(): bool
    {
        return $this->additionalProperties;
    }

    /**
     * Exports this validator as a raw `$jsonSchema` document.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $jsonSchema = [
            'bsonType' => $this->bsonType,
            'properties' => $this->properties,
            'additionalProperties' => $this->additionalProperties,
        ];

        if ($this->hasRequired) {
            $jsonSchema['required'] = $this->required;
        }

        return ['$jsonSchema' => $jsonSchema];
    }

    /**
     * Returns the full validator document ready for `createCollection` /
     * `collMod`, including collection-level validation options when supplied.
     *
     * @param array<string, mixed> $options Additional validator options (validationLevel, validationAction)
     * @return array<string, mixed>
     */
    public function withOptions(array $options = []): array
    {
        return $this->toArray() + $options;
    }
}

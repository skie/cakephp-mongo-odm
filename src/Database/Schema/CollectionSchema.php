<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Schema;

use Cake\Database\Exception\DatabaseException;
use Cake\Datasource\SchemaInterface;
use Exception;
use MongoDB\Collection;
use MongoDB\Database;
use MongoDB\Model\IndexInfo;

/**
 * Object interface for MongoDB collection schema information.
 *
 * Composes the `Field` / `Index` / `Validator` value objects (ported from
 * `Cake\Database\Schema\Column` / `Index` / constraints) instead of keeping
 * flat arrays. Handles validation rules, indexes, and programmatic field
 * definition, and satisfies `Cake\Datasource\SchemaInterface`.
 *
 * @see docs/reference/24-schema-migrations-design.md
 */
class CollectionSchema implements SchemaInterface
{
    /**
     * The collection indexes, keyed by index name.
     *
     * @var array<string, \Crustum\Mongo\Database\Schema\Index>
     */
    protected array $indexes = [];

    /**
     * The name of the collection.
     *
     * @var string
     */
    protected string $name;

    /**
     * Programmatically defined fields, keyed by field name.
     *
     * @var array<string, \Crustum\Mongo\Database\Schema\Field>
     */
    protected array $fields = [];

    /**
     * The `$jsonSchema` validator.
     *
     * @var \Crustum\Mongo\Database\Schema\Validator
     */
    protected Validator $validator;

    /**
     * Type map for fields.
     *
     * @var array<string, string>
     */
    protected array $typeMap = [];

    /**
     * BSON type aliases mapped to canonical plugin type names.
     *
     * Validators report types using the BSON spelling (e.g. `objectId`), which
     * does not match the `TypeFactory` registry keys. Unknown or unsupported
     * BSON types are intentionally omitted so they stay nullable rather than
     * being guessed.
     *
     * @var array<string, string>
     */
    protected static array $bsonTypeMap = [
        'objectId' => 'objectid',
        'string' => 'string',
        'bool' => 'boolean',
        'int' => 'int',
        'long' => 'int64',
        'double' => 'float',
        'decimal' => 'decimal128',
        'date' => 'date',
        'timestamp' => 'timestamp',
        'array' => 'array',
        'object' => 'hash',
        'binData' => 'binary',
        'null' => 'raw',
    ];

    /**
     * Schema options.
     *
     * @var array<string, mixed>
     */
    protected array $options = [];

    /**
     * Constructor
     *
     * @param string                   $name       Collection name
     * @param \MongoDB\Collection|null $collection MongoDB collection instance
     * @param \MongoDB\Database|null   $database   MongoDB database instance
     */
    public function __construct(string $name, ?Collection $collection = null, ?Database $database = null)
    {
        $this->name = $name;
        $this->validator = new Validator();

        if ($collection instanceof Collection && $database instanceof Database) {
            try {
                $collections = $database->listCollections(['filter' => ['name' => $name]]);
                $collectionInfo = null;
                foreach ($collections as $info) {
                    $collectionInfo = $info;
                    break;
                }

                if ($collectionInfo !== null) {
                    $options = $collectionInfo->getOptions();
                    $this->validator = new Validator($options['validator'] ?? null);
                }
            } catch (Exception) {
                $this->validator = new Validator();
            }

            foreach ($collection->listIndexes() as $index) {
                $this->processIndex($index);
            }
        }

        $this->updateTypeMap();
    }

    /**
     * Build a CollectionSchema from a field definition map without touching the
     * database. Used by the DTO and attribute schema readers.
     *
     * @param string                                      $name   Collection name
     * @param array<string, array<string, mixed>|string> $fields Field definitions keyed by field name
     * @return static
     */
    public static function fromFields(string $name, array $fields): static
    {
        $schema = new static($name);
        foreach ($fields as $fieldName => $attrs) {
            $schema->addField($fieldName, $attrs);
        }

        return $schema;
    }

    /**
     * Process index information
     *
     * @param \MongoDB\Model\IndexInfo $index Index information
     * @return void
     */
    protected function processIndex(IndexInfo $index): void
    {
        $key = $index->getKey();
        $unique = $index->isUnique();
        $expireAfterSeconds = null;
        if (method_exists($index, 'getExpireAfterSeconds')) {
            $expireAfterSeconds = $index->getExpireAfterSeconds();
        }

        $type = Index::INDEX;
        foreach ($key as $direction) {
            if ($direction === 'text') {
                $type = Index::TEXT;
            } elseif ($direction === '2d') {
                $type = Index::GEO_2D;
            } elseif ($direction === '2dsphere') {
                $type = Index::GEO_2DSPHERE;
            } elseif ($direction === 'hashed') {
                $type = Index::HASHED;
            }
        }
        if ($type === Index::INDEX && $unique) {
            $type = Index::UNIQUE;
        }

        $this->indexes[$index->getName()] = new Index(
            $index->getName(),
            $key,
            $type,
            $unique,
            $index->isSparse(),
            $expireAfterSeconds,
        );
    }

    /**
     * Get the name of the collection
     *
     * @return string
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Get field information including validation rules and indexes
     *
     * Supports dotted paths such as `address.city`, traversing nested
     * `$jsonSchema` properties (and array `items` for arrays of objects).
     *
     * @param string $name The field name (may be a dotted path)
     * @return array<string, mixed>|null Field information or null
     */
    public function field(string $name): ?array
    {
        $info = [];

        if (isset($this->fields[$name])) {
            $info = $this->fields[$name]->toArray();
        }

        $property = $this->resolvePropertyPath($name);
        if ($property !== null) {
            $info['validation'] = $property;
        }

        foreach ($this->indexes as $indexName => $index) {
            if (isset($index->getKey()[$name])) {
                $info['index'] = [
                    'name' => $indexName,
                    'type' => $this->getIndexType($index->getKey()[$name]),
                    'unique' => $index->getUnique(),
                    'sparse' => $index->getSparse(),
                ];
            }
        }

        return empty($info) ? null : $info;
    }

    /**
     * Get field type from validation schema or programmatic fields
     *
     * Supports dotted paths such as `address.city`. BSON type spellings are
     * normalized to canonical plugin type names so the result can be fed
     * directly to `TypeFactory::build()`.
     *
     * @param string $name Field name (may be a dotted path)
     * @return string|null BSON type or null
     */
    public function fieldType(string $name): ?string
    {
        if (isset($this->fields[$name])) {
            $type = $this->fields[$name]->getType();
            if ($type !== null) {
                return $type;
            }
        }

        $property = $this->resolvePropertyPath($name);
        if ($property !== null && isset($property['bsonType'])) {
            return $this->resolveBsonType($property['bsonType']);
        }

        return $this->inferFieldType($name);
    }

    /**
     * Returns the collection primary key.
     *
     * MongoDB creates `_id` automatically, so it is always the primary key.
     *
     * @return string
     */
    public function primaryKey(): string
    {
        return '_id';
    }

    /**
     * Resolves a (possibly dotted) field path against the `$jsonSchema`
     * properties tree.
     *
     * An array field's `items` schema is traversed when the path continues
     * into array elements, so `comments.author_id` resolves when `comments`
     * is an array of objects.
     *
     * @param string $path The field path to resolve
     * @return array<string, mixed>|null The leaf schema definition, or null
     */
    protected function resolvePropertyPath(string $path): ?array
    {
        $properties = $this->validator->getProperties();
        if ($properties === []) {
            return null;
        }

        $segments = explode('.', $path);
        $node = $properties;
        foreach ($segments as $index => $segment) {
            if (!is_array($node) || !isset($node[$segment])) {
                return null;
            }

            $node = $node[$segment];

            if ($index < count($segments) - 1) {
                if (isset($node['properties']) && is_array($node['properties'])) {
                    $node = $node['properties'];
                } elseif (isset($node['items']) && is_array($node['items'])) {
                    $node = $node['items'];
                } else {
                    return null;
                }
            }
        }

        return is_array($node) ? $node : null;
    }

    /**
     * Normalizes a BSON type spelling to a canonical plugin type name.
     *
     * Returns null for unknown BSON types so they remain nullable instead of
     * being guessed.
     *
     * @param string $bsonType The BSON type spelling from a validator
     * @return string|null Canonical plugin type name, or null when unsupported
     */
    protected function normalizeBsonType(string $bsonType): ?string
    {
        return static::$bsonTypeMap[$bsonType] ?? null;
    }

    /**
     * Resolves a `bsonType` value that may be a single type string or a list.
     *
     * MongoDB validators allow an array of types such as `['string', 'null']`.
     * The first entry that normalizes to a canonical plugin type wins; `null`
     * entries are ignored so a nullable field keeps its concrete type.
     *
     * @param mixed $bsonType The validator `bsonType` value.
     * @return string|null Canonical plugin type name, or null when unsupported.
     */
    protected function resolveBsonType(mixed $bsonType): ?string
    {
        $types = is_array($bsonType) ? $bsonType : [$bsonType];

        foreach ($types as $type) {
            if (!is_string($type)) {
                continue;
            }

            if ($type === 'null') {
                continue;
            }

            $normalized = $this->normalizeBsonType($type);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * Get all defined fields from validation schema and programmatic fields
     *
     * @return array<int, string>
     */
    public function fields(): array
    {
        $fields = array_keys($this->fields);
        $fields = array_merge($fields, array_keys($this->validator->getProperties()));

        foreach ($this->indexes as $index) {
            $fields = array_merge($fields, array_keys($index->getKey()));
        }

        return array_values(array_map(strval(...), array_unique($fields)));
    }

    /**
     * Get all indexes.
     *
     * Returns the `Index` value objects keyed by index name.
     *
     * @return array<string, \Crustum\Mongo\Database\Schema\Index>
     */
    public function indexes(): array
    {
        return $this->indexes;
    }

    /**
     * Get an index value object by name.
     *
     * @param string $name Index name
     * @return \Crustum\Mongo\Database\Schema\Index|null The index or null
     */
    public function getIndex(string $name): ?Index
    {
        return $this->indexes[$name] ?? null;
    }

    /**
     * Get an index value object by name.
     *
     * Will raise an exception if no index can be found.
     *
     * @param string $name The index name
     * @throws \Cake\Database\Exception\DatabaseException
     * @return \Crustum\Mongo\Database\Schema\Index
     */
    public function index(string $name): Index
    {
        $index = $this->indexes[$name] ?? null;
        if ($index === null) {
            throw new DatabaseException(sprintf(
                'Collection `%s` does not contain an index named `%s`.',
                $this->name,
                $name,
            ));
        }

        return $index;
    }

    /**
     * Add an index to the schema.
     *
     * Accepts either the Mongo shape (`key` map, options) or the Cake shape
     * (`columns` list). `Index::fromAttributes()` normalizes and validates.
     *
     * @param string $name The index name
     * @param array<string, mixed> $attrs The index attributes
     * @return $this
     */
    public function addIndex(string $name, array $attrs): static
    {
        $this->indexes[$name] = Index::fromAttributes($name, $attrs);

        return $this;
    }

    /**
     * Remove an index from the schema.
     *
     * @param string $name Index name
     * @return $this
     */
    public function removeIndex(string $name): static
    {
        unset($this->indexes[$name]);

        return $this;
    }

    /**
     * Get validation rules
     *
     * @return array<string, mixed>
     */
    public function validationRules(): array
    {
        return $this->validator->toArray();
    }

    /**
     * Returns the validator value object.
     *
     * @return \Crustum\Mongo\Database\Schema\Validator
     */
    public function validator(): Validator
    {
        return $this->validator;
    }

    /**
     * Sets the validator for this collection.
     *
     * @param \Crustum\Mongo\Database\Schema\Validator $validator The validator
     * @return $this
     */
    public function setValidator(Validator $validator): static
    {
        $this->validator = $validator;
        $this->updateTypeMap();

        return $this;
    }

    /**
     * Add a field to the schema
     *
     * @param string                $name  Field name
     * @param array<string, mixed>|string $attrs Field attributes or type string
     * @return $this
     */
    public function addField(string $name, array|string $attrs): static
    {
        $this->fields[$name] = Field::fromAttributes($name, $attrs);
        $this->updateTypeMap();

        return $this;
    }

    /**
     * Get field definition as an attribute array.
     *
     * @param string $name Field name
     * @return array<string, mixed>|null Field definition or null
     */
    public function getField(string $name): ?array
    {
        $field = $this->fields[$name] ?? null;
        if ($field === null) {
            return null;
        }

        return $field->toArray();
    }

    /**
     * Get the Field value object for a field.
     *
     * Will raise an exception if the field does not exist.
     *
     * @param string $name Field name
     * @throws \Cake\Database\Exception\DatabaseException
     * @return \Crustum\Mongo\Database\Schema\Field
     */
    public function fieldObject(string $name): Field
    {
        $field = $this->fields[$name] ?? null;
        if ($field === null) {
            throw new DatabaseException(sprintf(
                'Collection `%s` does not contain a field named `%s`.',
                $this->name,
                $name,
            ));
        }

        return $field;
    }

    /**
     * Check if field exists
     *
     * @param string $name Field name (may be a dotted path)
     * @return bool
     */
    public function hasField(string $name): bool
    {
        if (isset($this->fields[$name])) {
            return true;
        }

        return $this->resolvePropertyPath($name) !== null;
    }

    /**
     * Remove field from schema
     *
     * @param string $name Field name
     * @return $this
     */
    public function removeField(string $name): static
    {
        unset($this->fields[$name]);
        $this->updateTypeMap();

        return $this;
    }

    /**
     * Get field type
     *
     * @param string $name Field name
     * @return string|null Field type or null
     */
    public function getFieldType(string $name): ?string
    {
        return $this->fieldType($name);
    }

    /**
     * Set field type
     *
     * Creates the field when it does not exist.
     *
     * @param string $name Field name
     * @param string $type Field type
     * @return $this
     */
    public function setFieldType(string $name, string $type): static
    {
        if (!isset($this->fields[$name])) {
            $this->fields[$name] = Field::fromAttributes($name, 'string');
        }

        $this->fields[$name]->setType($type);
        $this->updateTypeMap();

        return $this;
    }

    /**
     * Get type map for all fields
     *
     * @return array<string, string>
     */
    public function typeMap(): array
    {
        return $this->typeMap;
    }

    /**
     * Update type map from fields
     *
     * Combines programmatic field types, validator-derived `bsonType` values
     * (normalized to canonical type names), and naming-convention inference.
     * `_id` is exposed as `objectid` unless an explicit definition exists.
     *
     * @return void
     */
    protected function updateTypeMap(): void
    {
        $this->typeMap = [];

        foreach ($this->fields as $name => $field) {
            $type = $field->getType();
            if ($type !== null) {
                $this->typeMap[$name] = $type;
            }
        }

        foreach ($this->validator->getProperties() as $name => $property) {
            if (isset($this->typeMap[$name])) {
                continue;
            }

            if (!isset($property['bsonType'])) {
                continue;
            }

            $type = $this->resolveBsonType($property['bsonType']);
            if ($type !== null) {
                $this->typeMap[$name] = $type;
            }
        }

        $this->autoInferTypes();

        if (!isset($this->typeMap['_id'])) {
            $this->typeMap['_id'] = 'objectid';
        }
    }

    /**
     * Auto-infer types from naming conventions
     *
     * Explicit field definitions and validator `bsonType` values take
     * precedence; inference fills only the gaps they leave open.
     *
     * @return void
     */
    protected function autoInferTypes(): void
    {
        $allFields = $this->fields();

        foreach ($allFields as $fieldName) {
            if (isset($this->typeMap[$fieldName])) {
                continue;
            }

            $inferredType = $this->inferFieldType($fieldName);
            if ($inferredType) {
                $this->typeMap[$fieldName] = $inferredType;
            }
        }
    }

    /**
     * Infer field type from naming conventions
     *
     * Nested paths use the leaf field name for the convention check.
     *
     * @param string $fieldName Field name (may be a dotted path)
     * @return string|null Inferred type or null
     */
    protected function inferFieldType(string $fieldName): ?string
    {
        $leaf = $fieldName;
        $lastDot = strrpos($fieldName, '.');
        if ($lastDot !== false) {
            $leaf = substr($fieldName, $lastDot + 1);
        }

        if ($leaf === '_id' || $leaf === 'id' || preg_match('/^(.+)_id$/', $leaf)) {
            return 'objectid';
        }

        return null;
    }

    /**
     * Convert MongoDB index type to readable format
     *
     * @param string|int $type MongoDB index type
     * @return string
     */
    protected function getIndexType(int|string $type): string
    {
        return match ($type) {
            1 => 'ascending',
            -1 => 'descending',
            '2d' => 'geo2d',
            '2dsphere' => 'geo2dsphere',
            'text' => 'text',
            'hashed' => 'hashed',
            default => 'unknown'
        };
    }

    /**
     * @inheritDoc
     */
    public function addColumn(string $name, array|string $attrs): static
    {
        return $this->addField($name, $attrs);
    }

    /**
     * @inheritDoc
     */
    public function getColumn(string $name): ?array
    {
        return $this->getField($name);
    }

    /**
     * @inheritDoc
     */
    public function hasColumn(string $name): bool
    {
        return $this->hasField($name);
    }

    /**
     * @inheritDoc
     */
    public function removeColumn(string $name): static
    {
        return $this->removeField($name);
    }

    /**
     * @inheritDoc
     */
    public function columns(): array
    {
        return $this->fields();
    }

    /**
     * @inheritDoc
     */
    public function getColumnType(string $name): ?string
    {
        return $this->getFieldType($name);
    }

    /**
     * @inheritDoc
     */
    public function setColumnType(string $name, string $type): static
    {
        return $this->setFieldType($name, $type);
    }

    /**
     * @inheritDoc
     */
    public function baseColumnType(string $column): ?string
    {
        return $this->getColumnType($column);
    }

    /**
     * @inheritDoc
     */
    public function isNullable(string $name): bool
    {
        if (isset($this->fields[$name])) {
            $null = $this->fields[$name]->getNull();

            return $null === true;
        }

        $property = $this->resolvePropertyPath($name);
        if ($property !== null && isset($property['bsonType'])) {
            $types = is_array($property['bsonType']) ? $property['bsonType'] : [$property['bsonType']];

            return in_array('null', $types, true);
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function defaultValues(): array
    {
        $defaults = [];
        foreach ($this->fields as $name => $field) {
            if ($field->getDefault() !== null) {
                $defaults[$name] = $field->getDefault();
            }
        }

        return $defaults;
    }

    /**
     * @inheritDoc
     */
    public function setOptions(array $options): static
    {
        $this->options = $options;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Sets the raw MongoDB validation rules for this collection.
     *
     * Used when schema metadata is supplied explicitly (application field
     * metadata) rather than introspected from the database.
     *
     * @param array<string, mixed> $validationRules The validation rules.
     * @return $this
     */
    public function setValidationRules(array $validationRules): static
    {
        $this->validator = new Validator($validationRules);
        $this->updateTypeMap();

        return $this;
    }

    /**
     * Returns an array that can be used to describe the internal state of this
     * object.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'collection' => $this->name(),
            'fields' => array_map(
                static fn(Field $field): array => $field->toArray(),
                $this->fields,
            ),
            'indexes' => array_map(
                static fn(Index $index): array => $index->toArray(),
                $this->indexes,
            ),
            'validator' => $this->validator->toArray(),
            'typeMap' => $this->typeMap,
            'options' => $this->options,
        ];
    }
}

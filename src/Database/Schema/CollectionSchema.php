<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Schema;

use Cake\Datasource\SchemaInterface;
use Exception;
use MongoDB\Collection;
use MongoDB\Database;
use MongoDB\Model\IndexInfo;

/**
 * Object interface for MongoDB collection schema information.
 * Handles validation rules, indexes, and programmatic field definition.
 *
 * Implements `Cake\Datasource\SchemaInterface` so the Datasource
 * `SchemaCollectionInterface::describe()` contract is satisfied.
 */
class CollectionSchema implements SchemaInterface
{
    /**
     * The raw validation schema from MongoDB
     *
     * @var array<string, mixed>
     */
    protected array $validationRules = [];

    /**
     * Collection indexes
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $indexes = [];

    /**
     * The name of the collection
     *
     * @var string
     */
    protected string $name;

    /**
     * Programmatically defined fields
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $fields = [];

    /**
     * Type map for fields
     *
     * @var array<string, string>
     */
    protected array $typeMap = [];

    /**
     * Valid field keys
     *
     * @var array<string, mixed>
     */
    protected static array $fieldKeys = [
        'type' => null,
        'null' => null,
        'default' => null,
        'comment' => null,
    ];

    /**
     * Type-specific extras
     *
     * @var array<string, array<string, mixed>>
     */
    protected static array $fieldExtras = [
        'string' => ['length' => null],
        'decimal128' => ['precision' => null],
    ];

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
                    $this->validationRules = $options['validator'] ?? [];
                } else {
                    $this->validationRules = [];
                }
            } catch (Exception) {
                $this->validationRules = [];
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
        $expireAfterSeconds = null;
        if (method_exists($index, 'getExpireAfterSeconds')) {
            $expireAfterSeconds = $index->getExpireAfterSeconds();
        }

        $this->indexes[$index->getName()] = [
            'key' => $index->getKey(),
            'unique' => $index->isUnique(),
            'sparse' => $index->isSparse(),
            'background' => $index->getVersion() > 0,
            'expireAfterSeconds' => $expireAfterSeconds,
        ];
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
            $info = $this->fields[$name];
        }

        $property = $this->resolvePropertyPath($name);
        if ($property !== null) {
            $info['validation'] = $property;
        }

        foreach ($this->indexes as $indexName => $index) {
            if (isset($index['key'][$name])) {
                $info['index'] = [
                    'name' => $indexName,
                    'type' => $this->getIndexType($index['key'][$name]),
                    'unique' => $index['unique'] ?? false,
                    'sparse' => $index['sparse'] ?? false,
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
        if (isset($this->fields[$name]['type'])) {
            return $this->fields[$name]['type'];
        }

        $property = $this->resolvePropertyPath($name);
        if ($property !== null && isset($property['bsonType'])) {
            return $this->normalizeBsonType((string)$property['bsonType']);
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
        $properties = $this->validationRules['$jsonSchema']['properties'] ?? null;
        if (!is_array($properties)) {
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
            if (!is_string($type) || $type === 'null') {
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
        $fields = [];

        $fields = array_merge($fields, array_keys($this->fields));

        if (isset($this->validationRules['$jsonSchema']['properties'])) {
            $fields = array_merge($fields, array_keys($this->validationRules['$jsonSchema']['properties']));
        }

        foreach ($this->indexes as $index) {
            $fields = array_merge($fields, array_keys($index['key']));
        }

        return array_values(array_map(strval(...), array_unique($fields)));
    }

    /**
     * Get all indexes
     *
     * @return array<string, array<string, mixed>>
     */
    public function indexes(): array
    {
        return $this->indexes;
    }

    /**
     * Get validation rules
     *
     * @return array<string, mixed>
     */
    public function validationRules(): array
    {
        return $this->validationRules;
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
        if (is_string($attrs)) {
            $attrs = ['type' => $attrs];
        }

        $field = [];
        foreach (static::$fieldKeys as $key => $default) {
            if (isset($attrs[$key])) {
                $field[$key] = $attrs[$key];
            } elseif ($default !== null) {
                $field[$key] = $default;
            }
        }

        $type = $field['type'] ?? null;
        if ($type && isset(static::$fieldExtras[$type])) {
            foreach (static::$fieldExtras[$type] as $extraKey => $extraDefault) {
                if (isset($attrs[$extraKey])) {
                    $field[$extraKey] = $attrs[$extraKey];
                } elseif ($extraDefault !== null) {
                    $field[$extraKey] = $extraDefault;
                }
            }
        }

        $this->fields[$name] = $field;
        $this->updateTypeMap();

        return $this;
    }

    /**
     * Get field definition
     *
     * @param string $name Field name
     * @return array<string, mixed>|null Field definition or null
     */
    public function getField(string $name): ?array
    {
        return $this->fields[$name] ?? null;
    }

    /**
     * Check if field exists
     *
     * @param string $name Field name
     * @return bool
     */
    public function hasField(string $name): bool
    {
        return isset($this->fields[$name]);
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
     * @param string $name Field name
     * @param string $type Field type
     * @return $this
     */
    public function setFieldType(string $name, string $type): static
    {
        if (!isset($this->fields[$name])) {
            $this->fields[$name] = [];
        }

        $this->fields[$name]['type'] = $type;
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
            if (isset($field['type'])) {
                $this->typeMap[$name] = $field['type'];
            }
        }

        foreach ($this->validationRules['$jsonSchema']['properties'] ?? [] as $name => $property) {
            if (isset($this->typeMap[$name])) {
                continue;
            }

            if (!is_array($property)) {
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
        return (bool)($this->fields[$name]['null'] ?? false);
    }

    /**
     * @inheritDoc
     */
    public function defaultValues(): array
    {
        $defaults = [];
        foreach ($this->fields as $name => $field) {
            if (array_key_exists('default', $field)) {
                $defaults[$name] = $field['default'];
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
        $this->validationRules = $validationRules;
        $this->updateTypeMap();

        return $this;
    }
}

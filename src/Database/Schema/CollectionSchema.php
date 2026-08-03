<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Schema;

use ArrayAccess;
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
     * @var array
     */
    protected array $validationRules;

    /**
     * Collection indexes
     *
     * @var array
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
     * @var array<string, array>
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
     * Schema options.
     *
     * @var array<string, mixed>
     */
    protected array $options = [];

    /**
     * Constructor
     *
     * @param string              $name       Collection name
     * @param \MongoDB\Collection $collection MongoDB collection instance
     * @param \MongoDB\Database   $database   MongoDB database instance
     */
    public function __construct(string $name, Collection $collection, Database $database)
    {
        $this->name = $name;

        try {
            $collections = $database->listCollections(['filter' => ['name' => $name]]);
            $collectionInfo = null;
            foreach ($collections as $info) {
                $collectionInfo = $info;
                break;
            }
            if ($collectionInfo !== null) {
                if (method_exists($collectionInfo, 'toArray')) {
                    $infoArray = $collectionInfo->toArray();
                    $this->validationRules = $infoArray['options']['validator'] ?? [];
                } elseif (method_exists($collectionInfo, 'getOptions')) {
                    $options = $collectionInfo->getOptions();
                    $this->validationRules = $options['validator'] ?? [];
                } elseif ($collectionInfo instanceof ArrayAccess || is_array($collectionInfo)) {
                    $this->validationRules = $collectionInfo['options']['validator'] ?? [];
                } else {
                    $this->validationRules = [];
                }
            } else {
                $this->validationRules = [];
            }
        } catch (Exception) {
            $this->validationRules = [];
        }
        foreach ($collection->listIndexes() as $index) {
            $this->processIndex($index);
        }

        $this->updateTypeMap();
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
     * @param string $name The field name
     * @return array|null Field information or null
     */
    public function field(string $name): ?array
    {
        $info = [];

        if (isset($this->fields[$name])) {
            $info = $this->fields[$name];
        }

        if (isset($this->validationRules['$jsonSchema']['properties'][$name])) {
            $info['validation'] = $this->validationRules['$jsonSchema']['properties'][$name];
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
     * @param string $name Field name
     * @return string|null BSON type or null
     */
    public function fieldType(string $name): ?string
    {
        if (isset($this->fields[$name]['type'])) {
            return $this->fields[$name]['type'];
        }

        if (isset($this->validationRules['$jsonSchema']['properties'][$name]['bsonType'])) {
            return $this->validationRules['$jsonSchema']['properties'][$name]['bsonType'];
        }

        return null;
    }

    /**
     * Get all defined fields from validation schema and programmatic fields
     *
     * @return array
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

        return array_unique($fields);
    }

    /**
     * Get all indexes
     *
     * @return array
     */
    public function indexes(): array
    {
        return $this->indexes;
    }

    /**
     * Get validation rules
     *
     * @return array
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

        $this->autoInferTypes();
    }

    /**
     * Auto-infer types from naming conventions
     *
     * @return void
     */
    protected function autoInferTypes(): void
    {
        $allFields = $this->fields();

        foreach ($allFields as $fieldName) {
            $inferredType = $this->inferFieldType($fieldName);
            if ($inferredType) {
                $this->typeMap[$fieldName] = $inferredType;
            }
        }
    }

    /**
     * Infer field type from naming conventions
     *
     * @param string $fieldName Field name
     * @return string|null Inferred type or null
     */
    protected function inferFieldType(string $fieldName): ?string
    {
        if (preg_match('/^(.+)_id$/', $fieldName)) {
            return 'objectid';
        }

        if ($fieldName === '_id' || $fieldName === 'id') {
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
}

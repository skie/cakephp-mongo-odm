<?php
declare(strict_types=1);

namespace Crustum\Mongo\Bake;

use Crustum\Mongo\Database\Schema\CollectionSchema;
use Crustum\Mongo\ODM\BaseCollection;

/**
 * Builds the bake context for a Mongo Collection, mirroring
 * `Bake\Command\ModelCommand::getTableContext()`.
 *
 * Produces associations (Cake format), behaviors, validation rules,
 * rules-checker rules, primary key, display field, fields, and hidden fields
 * from a `BaseCollection` + its `CollectionSchema`.
 */
class MongoCollectionContext
{
    /**
     * Builds the context array.
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $collection The collection.
     * @return array<string, mixed>
     */
    public function build(BaseCollection $collection): array
    {
        $described = $collection->describeSchema();
        $schema = $described instanceof CollectionSchema ? $described : null;
        if (!$schema instanceof CollectionSchema) {
            $appSchema = $collection->getSchema();
            $schema = $appSchema instanceof CollectionSchema ? $appSchema : null;
        }

        $filter = new MongoAssociationFilter();
        $associations = $filter->filterAssociations($collection);
        $associationInfo = $this->associationInfo($collection);

        $primaryKey = (array)$collection->getPrimaryKey();
        $displayField = $this->displayField($collection, $schema);
        $fields = $this->fields($collection, $schema);
        $validation = $schema instanceof CollectionSchema ? $this->validation($schema, $associations) : [];
        $rulesChecker = $schema instanceof CollectionSchema ? $this->rules($schema, $associations) : [];
        $behaviors = $schema instanceof CollectionSchema ? $this->behaviors($schema) : [];
        $hidden = $this->hiddenFields($schema);
        $table = $collection->getCollection();

        return ['associations' => $associations, 'associationInfo' => $associationInfo, 'primaryKey' => $primaryKey, 'displayField' => $displayField, 'table' => $table, 'fields' => $fields, 'validation' => $validation, 'rulesChecker' => $rulesChecker, 'behaviors' => $behaviors, 'hidden' => $hidden];
    }

    /**
     * Resolves the display field for a collection.
     *
     * Prefers the collection's configured display field; falls back to the
     * first string-typed field (excluding `_id`). Returns null when none found.
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $collection The collection.
     * @param \Crustum\Mongo\Database\Schema\CollectionSchema|null $schema The schema.
     * @return array<string>|string|null
     */
    protected function displayField(BaseCollection $collection, ?CollectionSchema $schema): array|string|null
    {
        $displayField = $collection->getDisplayField();
        $displayValue = is_array($displayField) ? $displayField : $displayField;
        if (!in_array($displayValue, [[], '', '_id'], true)) {
            return $displayField;
        }

        if ($schema instanceof CollectionSchema) {
            foreach ($schema->columns() as $field) {
                if ($field === '_id') {
                    continue;
                }

                if ($schema->getColumnType($field) === 'string') {
                    return $field;
                }
            }
        }

        return null;
    }

    /**
     * Builds association info map (alias → targetFqn).
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $collection The collection.
     * @return array<string, array{targetFqn: string}>
     */
    protected function associationInfo(BaseCollection $collection): array
    {
        $info = [];
        foreach ($collection->associations() as $association) {
            $target = $association->getTarget();
            $info[$association->getName()] = [
                'targetFqn' => '\\' . $target::class,
            ];
        }

        return $info;
    }

    /**
     * Returns fields (columns + association properties, minus primary key).
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $collection The collection.
     * @param \Crustum\Mongo\Database\Schema\CollectionSchema|null $schema The schema.
     * @return array<int, string>
     */
    protected function fields(BaseCollection $collection, ?CollectionSchema $schema): array
    {
        $fields = $schema instanceof CollectionSchema ? $schema->columns() : [];
        foreach ($collection->associations() as $assoc) {
            $fields[] = $assoc->getProperty();
        }

        $primaryKey = (array)$collection->getPrimaryKey();

        return array_values(array_diff($fields, $primaryKey));
    }

    /**
     * Generates validation rules from the schema.
     *
     * @param \Crustum\Mongo\Database\Schema\CollectionSchema $schema The schema.
     * @param array<string, array<string, array<string, mixed>>> $associations Associations data.
     * @return array<string, array<string, array<string, mixed>>>
     */
    protected function validation(CollectionSchema $schema, array $associations): array
    {
        $fields = $schema->columns();
        if ($fields === []) {
            return [];
        }

        $validate = [];
        $primaryKey = $schema->primaryKey();
        $foreignKeys = [];
        foreach ($associations['BelongsTo'] ?? [] as $assoc) {
            $foreignKeys[] = $assoc['foreignKey'];
        }

        foreach ($fields as $fieldName) {
            if ($fieldName === $primaryKey) {
                continue;
            }

            $type = $schema->getColumnType($fieldName);
            $nullable = $schema->isNullable($fieldName);
            $field = $schema->getField($fieldName);
            $isForeignKey = in_array($fieldName, $foreignKeys, true);
            $rules = $this->fieldValidation($fieldName, $type, $nullable, $isForeignKey, $field, $schema);

            if ($rules !== []) {
                $validate[$fieldName] = $rules;
            }
        }

        return $validate;
    }

    /**
     * Builds validation rules for a single field.
     *
     * @param string $fieldName Field name.
     * @param string|null $type Canonical Mongo type.
     * @param bool $nullable Whether nullable.
     * @param bool $isForeignKey Whether a foreign key.
     * @param array<string, mixed>|null $field Field definition.
     * @param \Crustum\Mongo\Database\Schema\CollectionSchema $schema The schema.
     * @return array<string, array<string, mixed>>
     */
    protected function fieldValidation(
        string $fieldName,
        ?string $type,
        bool $nullable,
        bool $isForeignKey,
        ?array $field,
        CollectionSchema $schema,
    ): array {
        $ignoreFields = ['created', 'modified', 'updated'];
        if (in_array($fieldName, $ignoreFields, true)) {
            return [];
        }

        $rules = [];
        if ($fieldName === 'email') {
            $rules['email'] = ['rule' => 'email', 'args' => []];
        } elseif ($type === 'objectid' || $fieldName === '_id' || str_ends_with($fieldName, '_id')) {
            $rules['validId'] = ['rule' => 'validId', 'provider' => 'mongo', 'args' => []];
        } elseif ($type === 'integer' || $type === 'int64') {
            $rules['integer'] = ['rule' => 'integer', 'args' => []];
        } elseif ($type === 'float' || $type === 'decimal128') {
            $rules['decimal'] = ['rule' => 'decimal', 'args' => []];
        } elseif ($type === 'boolean') {
            $rules['boolean'] = ['rule' => 'boolean', 'args' => []];
        } elseif (in_array($type, ['date', 'datetime', 'timestamp'], true)) {
            $rules['dateTime'] = ['rule' => 'dateTime', 'args' => []];
        } elseif ($type === 'string') {
            $rules['scalar'] = ['rule' => 'scalar', 'args' => []];
            if (isset($field['length']) && $field['length'] > 0) {
                $rules['maxLength'] = ['rule' => 'maxLength', 'args' => [(int)$field['length']]];
            }
        }

        if ($nullable) {
            $rules['allowEmpty'] = ['rule' => $this->getEmptyMethod($fieldName, $type), 'args' => []];
        } else {
            if (($field['default'] ?? null) === null && !$isForeignKey) {
                $rules['requirePresence'] = ['rule' => 'requirePresence', 'args' => ['create']];
            }

            $rules['notEmpty'] = ['rule' => $this->getEmptyMethod($fieldName, $type, 'not'), 'args' => []];
        }

        foreach ($schema->indexes() as $index) {
            if (!$index->getUnique()) {
                continue;
            }

            $keyFields = array_keys($index->getKey());
            if ($keyFields === [$fieldName]) {
                $rules['unique'] = ['rule' => 'validateUnique', 'provider' => 'table'];
            }
        }

        return $rules;
    }

    /**
     * Get the specific allow-empty method name for a field based on its type.
     *
     * @param string $fieldName Field name.
     * @param string|null $type Canonical Mongo type.
     * @param string $prefix Method name prefix (`allow` or `not`).
     * @return string
     */
    protected function getEmptyMethod(string $fieldName, ?string $type, string $prefix = 'allow'): string
    {
        switch ($type) {
            case 'date':
                return $prefix . 'EmptyDate';

            case 'datetime':
            case 'timestamp':
                return $prefix . 'EmptyDateTime';

            case 'time':
                return $prefix . 'EmptyTime';
        }

        if (preg_match('/(^|\s|_|-)(attachment|file|image)$/i', $fieldName)) {
            return $prefix . 'EmptyFile';
        }

        return $prefix . 'EmptyString';
    }

    /**
     * Generates rules-checker rules.
     *
     * @param \Crustum\Mongo\Database\Schema\CollectionSchema $schema The schema.
     * @param array<string, array<string, array<string, mixed>>> $associations Associations data.
     * @return list<array<string, mixed>>
     */
    protected function rules(CollectionSchema $schema, array $associations): array
    {
        $fields = $schema->columns();
        if ($fields === []) {
            return [];
        }

        $rules = [];
        foreach ($schema->indexes() as $index) {
            if (!$index->getUnique()) {
                continue;
            }

            $rule = ['name' => 'isUnique', 'fields' => array_keys($index->getKey()), 'options' => []];
            if (count($rule['fields']) > 1) {
                $rule['message'] = sprintf(
                    'This combination of %s and %s already exists',
                    implode(', ', array_slice($rule['fields'], 0, -1)),
                    end($rule['fields']),
                );
            }

            $rules[] = $rule;
        }

        foreach ($associations['BelongsTo'] ?? [] as $assoc) {
            $rules[] = [
                'name' => 'existsIn',
                'fields' => (array)$assoc['foreignKey'],
                'extra' => $assoc['alias'],
                'options' => [],
            ];
        }

        return $rules;
    }

    /**
     * Detects behaviors (Timestamp when created/modified exist).
     *
     * @param \Crustum\Mongo\Database\Schema\CollectionSchema $schema The schema.
     * @return array<string, array<mixed>>
     */
    protected function behaviors(CollectionSchema $schema): array
    {
        $behaviors = [];
        $fields = $schema->columns();
        if ($fields === []) {
            return [];
        }

        if (in_array('created', $fields, true) || in_array('modified', $fields, true)) {
            $behaviors['Timestamp'] = [];
        }

        return $behaviors;
    }

    /**
     * Returns hidden fields (token/password/passwd present in schema).
     *
     * @param \Crustum\Mongo\Database\Schema\CollectionSchema $schema The schema.
     * @return array<int, string>
     */
    protected function hiddenFields(?CollectionSchema $schema): array
    {
        if (!$schema instanceof CollectionSchema) {
            return [];
        }

        $whitelist = ['token', 'password', 'passwd'];

        return array_values(array_intersect($schema->columns(), $whitelist));
    }
}

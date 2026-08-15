<?php
declare(strict_types=1);

/**
 * Ported and adapted from cakephp/bake ModelCommand (MIT License).
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc.
 * @license https://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace Crustum\Mongo\Command\Bake;

use Bake\CodeGen\FileBuilder;
use Bake\Command\BakeCommand;
use Bake\Utility\TemplateRenderer;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\Datasource\SchemaInterface;
use Cake\Utility\Inflector;
use Crustum\Mongo\Bake\MongoCollectionContext;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Database\Schema\CachedSchemaCollection;
use Crustum\Mongo\Database\Schema\CollectionSchema;
use Crustum\Mongo\Migration\Util\SchemaFields;
use Crustum\Mongo\ODM\Association\BelongsTo;
use Crustum\Mongo\ODM\Association\BelongsToMany;
use Crustum\Mongo\ODM\Association\HasMany;
use Crustum\Mongo\ODM\Association\HasOne;
use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\ODM\Document;
use Crustum\Mongo\View\Helper\MongoBakeHelper;
use Crustum\Mongo\View\Helper\MongoDocBlockHelper;
use Override;
use RuntimeException;
use function Cake\Core\pluginSplit;

/**
 * Command for generating Mongo model files (Collection + Document + enums +
 * fixture + test), mirroring `Bake\Command\ModelCommand` for the Mongo ODM.
 *
 * Usage:
 * ```
 * bin/cake bake mongo_model Articles
 * bin/cake bake mongo_model Articles --no-entity
 * bin/cake bake mongo_model --no-associations Articles
 * ```
 */
class MongoModelCommand extends BakeCommand
{
    /**
     * Path to Model directory.
     *
     * @var string
     */
    public string $pathFragment = 'Model/';

    /**
     * Holds collections found on connection.
     *
     * @var array<string>
     */
    protected array $_collections = [];

    /**
     * The Collections to skip.
     *
     * @var array<string>
     */
    public array $skipCollections = ['cake_migrations', '_seeds', 'system'];

    /**
     * @inheritDoc
     */
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $this->extractCommonProperties($args);
        $name = $this->_getName($args->getArgument('name') ?? '');

        if (empty($name)) {
            $io->out('Choose a model to bake from the following:');
            foreach ($this->listUnskipped() as $collection) {
                $io->out('- ' . $this->_camelize($collection));
            }

            return static::CODE_SUCCESS;
        }

        $this->bake($this->_camelize($name), $args, $io);

        return static::CODE_SUCCESS;
    }

    /**
     * Generate code for the given model name.
     *
     * @param string $name The model name to generate.
     * @param \Cake\Console\Arguments $args Console Arguments.
     * @param \Cake\Console\ConsoleIo $io Console Io.
     * @return void
     */
    public function bake(string $name, Arguments $args, ConsoleIo $io): void
    {
        $collection = $this->getCollectionName($name, $args);
        $this->clearSchemaCache();
        $collectionObject = $this->getCollectionObject($name, $collection);
        $data = $this->getCollectionContext($collectionObject, $collection, $name, $args, $io);

        $this->bakeDocument($collectionObject, $data, $args, $io);
        $this->bakeCollection($collectionObject, $data, $args, $io);
        $this->bakeFixture($collectionObject->getAlias(), $collectionObject->getCollection(), $args, $io);
        $this->bakeTest($collectionObject->getAlias(), $args, $io);
    }

    /**
     * Clears the cached schema metadata so baking reflects the live database.
     *
     * @return void
     */
    protected function clearSchemaCache(): void
    {
        $connection = ConnectionManager::get($this->connection);
        if (!$connection instanceof Connection) {
            return;
        }

        $collection = $connection->getSchemaCollection();
        if ($collection instanceof CachedSchemaCollection) {
            $collection->clearCache();
        }
    }

    /**
     * Builds the bake context for a Collection, mirroring `getTableContext()`.
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $collectionObject The collection.
     * @param string $collection The collection name.
     * @param string $name The model name to generate.
     * @param \Cake\Console\Arguments $args CLI Arguments.
     * @param \Cake\Console\ConsoleIo $io CLI io.
     * @return array<string, mixed>
     */
    public function getCollectionContext(
        BaseCollection $collectionObject,
        string $collection,
        string $name,
        Arguments $args,
        ConsoleIo $io,
    ): array {
        $associations = $this->getAssociations($collectionObject, $collection, $args, $io);
        $this->applyAssociations($collectionObject, $associations);

        $contextBuilder = new MongoCollectionContext();
        $contextBuilder->plugin = $this->plugin;

        $context = $contextBuilder->build($collectionObject);
        $associationInfo = $this->getAssociationInfo($collectionObject);

        $schema = $collectionObject->getSchema();
        $propertySchema = $this->getEntityPropertySchema($collectionObject);

        $primaryKey = $this->getPrimaryKey($collectionObject, $args);
        $displayField = $this->getDisplayField($collectionObject, $args);
        $fields = $this->getFields($collectionObject, $args);
        $hidden = $this->getHiddenFields($collectionObject, $args);

        $validation = $args->getOption('no-validation') ? [] : $context['validation'];
        $rulesChecker = $args->getOption('no-rules') ? [] : $context['rulesChecker'];
        $behaviors = $args->getOption('no-associations') ? [] : $context['behaviors'];

        return ['associationInfo' => $associationInfo, 'primaryKey' => $primaryKey, 'displayField' => $displayField, 'collection' => $collection, 'propertySchema' => $propertySchema, 'fields' => $fields, 'validation' => $validation, 'rulesChecker' => $rulesChecker, 'behaviors' => $behaviors, 'hidden' => $hidden, 'schema' => $schema, 'associations' => $associations, 'embedded' => $context['embedded'] ?? [], 'embeddedImports' => $context['embeddedImports'] ?? []];
    }

    /**
     * Get a model object for a class name.
     *
     * @param string $className Name of class you want model to be.
     * @param string $collection Collection name.
     * @return \Crustum\Mongo\ODM\BaseCollection Collection instance.
     */
    public function getCollectionObject(string $className, string $collection): BaseCollection
    {
        $connection = ConnectionManager::get($this->connection);
        if (!$connection instanceof Connection) {
            throw new RuntimeException(sprintf(
                'Connection `%s` is not a %s instance.',
                $this->connection,
                Connection::class,
            ));
        }

        $collectionObject = new BaseCollection([
            'alias' => $className,
            'collection' => $collection,
            'connectionName' => $this->connection,
        ]);
        $collectionObject->setConnection($connection);

        return $collectionObject;
    }

    /**
     * Get the array of associations to generate.
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $collection The collection.
     * @param string $collectionName The collection name.
     * @param \Cake\Console\Arguments $args CLI Arguments.
     * @param \Cake\Console\ConsoleIo $io CLI io.
     * @return array<string, array<int|string, mixed>>
     */
    public function getAssociations(BaseCollection $collection, string $collectionName, Arguments $args, ConsoleIo $io): array
    {
        if ($args->getOption('no-associations')) {
            return [];
        }

        $io->out('One moment while associations are detected.');

        $associations = [
            'belongsTo' => [],
            'hasOne' => [],
            'hasMany' => [],
            'belongsToMany' => [],
        ];

        $associations = $this->findBelongsTo($collection, $associations, $args);
        $associations = $this->findHasOne($collection, $associations);
        $associations = $this->findHasMany($collection, $associations);
        $associations = $this->findBelongsToMany($collection, $collectionName, $associations);

        return $this->ensureAliasUniqueness($associations);
    }

    /**
     * Sync the in memory collection object.
     *
     * Uses the ODM association registry with explicit association classes so
     * the baked model carries fully-typed relations (including HABTM joins).
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $model The collection.
     * @param array<string, array<int|string, mixed>> $associations The associations to append.
     * @return void
     */
    public function applyAssociations(BaseCollection $model, array $associations): void
    {
        $classMap = [
            'belongsTo' => BelongsTo::class,
            'hasOne' => HasOne::class,
            'hasMany' => HasMany::class,
            'belongsToMany' => BelongsToMany::class,
        ];

        foreach ($associations as $type => $assocs) {
            $class = $classMap[$type] ?? null;
            if ($class === null) {
                continue;
            }

            foreach ($assocs as $assoc) {
                $alias = $assoc['alias'];
                unset($assoc['alias']);
                if ($model->hasAssociation($alias)) {
                    continue;
                }

                $model->associations()->load($class, $alias, $model, $assoc);
            }
        }
    }

    /**
     * Collects meta information for associations.
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $collection The collection.
     * @return array<string, array{targetFqn: string}>
     */
    public function getAssociationInfo(BaseCollection $collection): array
    {
        $info = [];
        $appNamespace = Configure::read('App.namespace');

        foreach ($collection->associations() as $association) {
            $target = $association->getTarget();
            $collectionClass = $target::class;

            if ($collectionClass === BaseCollection::class) {
                $namespace = $appNamespace;
                $className = $association->getClassName();
                [$plugin, $className] = pluginSplit($className);
                if ($plugin !== null) {
                    $namespace = $plugin;
                }

                $namespace = str_replace('/', '\\', trim((string)$namespace, '\\'));
                $collectionClass = $namespace . '\Model\Collection\\' . $className . 'Collection';
            }

            $info[$association->getName()] = [
                'targetFqn' => '\\' . $collectionClass,
            ];
        }

        return $info;
    }

    /**
     * Find belongsTo relations and add them to the associations list.
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $model Collection instance.
     * @param array<string, array<int|string, mixed>> $associations Array of in progress associations.
     * @param \Cake\Console\Arguments|null $args CLI arguments.
     * @return array<string, array<int|string, mixed>>
     */
    public function findBelongsTo(BaseCollection $model, array $associations, ?Arguments $args = null): array
    {
        $schema = $model->describeSchema();
        if (!$schema instanceof CollectionSchema) {
            return $associations;
        }

        $collections = $this->listAll();
        foreach ($schema->columns() as $fieldName) {
            if ($schema->getFieldType($fieldName) !== 'objectid') {
                continue;
            }

            if ($fieldName === '_id') {
                continue;
            }

            $targetCollection = $this->targetCollectionFor($fieldName);
            if ($targetCollection === null) {
                continue;
            }

            if (!in_array($targetCollection, $collections, true)) {
                continue;
            }

            $alias = Inflector::classify(Inflector::singularize($this->_camelize($targetCollection)));
            if ($alias === $model->getAlias()) {
                continue;
            }

            $associations['belongsTo'][] = [
                'alias' => $alias,
                'className' => $this->_camelize($targetCollection),
                'foreignKey' => $fieldName,
            ];
        }

        return $associations;
    }

    /**
     * Resolves the target collection name for a `*_id` foreign key field.
     *
     * `author_id` → `authors`, `category_id` → `categories`. Returns null when
     * the field does not look like a foreign key.
     *
     * @param string $fieldName Field name.
     * @return string|null
     */
    protected function targetCollectionFor(string $fieldName): ?string
    {
        $name = preg_replace('/_id$/', '', $fieldName);
        if ($name === null || $name === $fieldName) {
            return null;
        }

        return Inflector::tableize(Inflector::pluralize($name));
    }

    /**
     * Find the hasOne relations and add them to associations list.
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $model Collection instance.
     * @param array<string, array<int|string, mixed>> $associations Array of in progress associations.
     * @return array<string, array<int|string, mixed>>
     */
    public function findHasOne(BaseCollection $model, array $associations): array
    {
        return $this->findReferenceRelations($model, $associations, 'hasOne', uniqueOnly: true);
    }

    /**
     * Find the hasMany relations and add them to associations list.
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $model Collection instance.
     * @param array<string, array<int|string, mixed>> $associations Array of in progress associations.
     * @return array<string, array<int|string, mixed>>
     */
    public function findHasMany(BaseCollection $model, array $associations): array
    {
        return $this->findReferenceRelations($model, $associations, 'hasMany', uniqueOnly: false);
    }

    /**
     * Finds hasOne / hasMany relations by scanning other collections for a
     * foreign key pointing back at this collection.
     *
     * When `uniqueOnly` is true only foreign keys backed by a unique index are
     * reported (hasOne); otherwise all matching keys are reported (hasMany).
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $model Collection instance.
     * @param array<string, array<int|string, mixed>> $associations Array of in progress associations.
     * @param string $type Association type key (`hasOne` / `hasMany`).
     * @param bool $uniqueOnly Whether to only report unique foreign keys.
     * @return array<string, array<int|string, mixed>>
     */
    protected function findReferenceRelations(
        BaseCollection $model,
        array $associations,
        string $type,
        bool $uniqueOnly,
    ): array {
        $schema = $model->getSchema();
        if (!$schema instanceof CollectionSchema) {
            return $associations;
        }

        $primaryKey = $schema->primaryKey();
        $collectionName = $model->getCollection();
        $foreignKey = $this->_modelKey($collectionName);

        foreach ($this->listAll() as $otherCollectionName) {
            if ($this->isPossibleBelongsToManyRelation($collectionName, $otherCollectionName)) {
                continue;
            }

            $otherSchema = $this->describeCollection($otherCollectionName);
            if (!$otherSchema instanceof CollectionSchema) {
                continue;
            }

            foreach ($otherSchema->columns() as $fieldName) {
                if ($fieldName === $primaryKey) {
                    continue;
                }

                if ($otherSchema->getFieldType($fieldName) !== 'objectid') {
                    continue;
                }

                if ($fieldName !== $foreignKey) {
                    continue;
                }

                if ($this->hasUniqueIndexFor($otherSchema, $fieldName) !== $uniqueOnly) {
                    continue;
                }

                $associations[$type][] = [
                    'alias' => $this->_camelize($otherCollectionName),
                    'foreignKey' => $fieldName,
                ];
            }
        }

        return $associations;
    }

    /**
     * Checks whether the given collection schema has a unique index covering
     * exactly the given field.
     *
     * @param \Crustum\Mongo\Database\Schema\CollectionSchema $schema The schema.
     * @param string $keyField The field.
     * @return bool
     */
    protected function hasUniqueIndexFor(CollectionSchema $schema, string $keyField): bool
    {
        foreach ($schema->indexes() as $index) {
            if (!$index->getUnique()) {
                continue;
            }

            if (array_keys($index->getKey()) === [$keyField]) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find the BelongsToMany (HABTM) relations and add them to associations list.
     *
     * Detects join collections matching `<source>_<target>` / `<target>_<source>`
     * with `_id` + `<source>_id` + `<target>_id` fields.
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $model Collection instance.
     * @param string $collectionName The source collection name.
     * @param array<string, array<int|string, mixed>> $associations Array of in progress associations.
     * @return array<string, array<int|string, mixed>>
     */
    public function findBelongsToMany(BaseCollection $model, string $collectionName, array $associations): array
    {
        $foreignKey = $this->_modelKey($collectionName);
        $collections = $this->listAll();

        foreach ($collections as $otherCollectionName) {
            $assocCollection = null;
            $offset = strpos($otherCollectionName, $collectionName . '_');
            $otherOffset = strpos($otherCollectionName, '_' . $collectionName);

            if ($offset !== false) {
                $assocCollection = substr($otherCollectionName, strlen($collectionName . '_'));
            } elseif ($otherOffset !== false) {
                $assocCollection = substr($otherCollectionName, 0, $otherOffset);
            }

            if (!$assocCollection) {
                continue;
            }

            if (!in_array($assocCollection, $collections, true)) {
                continue;
            }

            $habtmName = $this->_camelize($assocCollection);
            $associations['belongsToMany'][] = [
                'alias' => $habtmName,
                'foreignKey' => $foreignKey,
                'targetForeignKey' => $this->_modelKey($habtmName),
                'joinCollection' => $otherCollectionName,
            ];
        }

        return $associations;
    }

    /**
     * Checks whether the given source and target collection names are sides
     * of a possible many-to-many relation.
     *
     * @param string $sourceCollection The source collection name.
     * @param string $targetCollection The target collection name.
     * @return bool
     */
    public function isPossibleBelongsToManyRelation(string $sourceCollection, string $targetCollection): bool
    {
        $collections = $this->listAll();

        $pregCollectionName = preg_quote($sourceCollection, '/');
        $pregPattern = "/^{$pregCollectionName}_|_{$pregCollectionName}$/";

        if (preg_match($pregPattern, $targetCollection) === 1) {
            $possibleBTMTargetCollection = preg_replace($pregPattern, '', $targetCollection);
            if (in_array($possibleBTMTargetCollection, $collections, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the display field from the model or parameters.
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $model The collection.
     * @param \Cake\Console\Arguments $args CLI Arguments.
     * @return array<string>|string
     */
    public function getDisplayField(BaseCollection $model, Arguments $args): array|string
    {
        if ($args->getOption('display-field')) {
            return (string)$args->getOption('display-field');
        }

        $displayField = $model->getDisplayField();
        if (is_array($displayField) && $displayField !== []) {
            return $displayField;
        }

        if (is_string($displayField) && $displayField !== '' && $displayField !== '_id') {
            return $displayField;
        }

        $schema = $model->getSchema();
        if (!$schema instanceof CollectionSchema) {
            return [];
        }

        foreach ($schema->columns() as $field) {
            if ($field === '_id') {
                continue;
            }

            if ($schema->getFieldType($field) === 'string') {
                return $field;
            }
        }

        return [];
    }

    /**
     * Get the primary key field from the model or parameters.
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $model The collection.
     * @param \Cake\Console\Arguments $args CLI Arguments.
     * @return array<string> The columns in the primary key.
     */
    public function getPrimaryKey(BaseCollection $model, Arguments $args): array
    {
        if ($args->getOption('primary-key')) {
            $fields = explode(',', (string)$args->getOption('primary-key'));

            return array_values(array_filter(array_map(trim(...), $fields)));
        }

        $pk = $model->getPrimaryKey();

        return (array)$pk;
    }

    /**
     * Returns an entity property "schema" (columns + association FQNs).
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $model The collection.
     * @return array<string, array<string, mixed>>
     */
    public function getEntityPropertySchema(BaseCollection $model): array
    {
        $properties = [];

        $schema = $model->getSchema();
        if (!$schema instanceof CollectionSchema) {
            return $properties;
        }

        foreach ($schema->columns() as $column) {
            $properties[$column] = [
                'kind' => 'column',
                'type' => $schema->getFieldType($column) ?? 'string',
                'null' => $schema->isNullable($column),
            ];
        }

        foreach ($model->associations() as $association) {
            $target = $association->getTarget();
            $entityClass = $this->targetDocumentClass($target);

            $properties[$association->getProperty()] = [
                'kind' => 'association',
                'association' => $association,
                'type' => $entityClass,
            ];
        }

        return $properties;
    }

    /**
     * Resolves the target Document FQN for an associated collection.
     *
     * Falls back to the ODM base `Document` when the target is a generic
     * collection with no concrete Document class.
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $target The target collection.
     * @return string The fully qualified Document class name (with leading `\`).
     */
    protected function targetDocumentClass(BaseCollection $target): string
    {
        $documentClass = $target->getDocumentClass();
        if ($documentClass !== Document::class) {
            return '\\' . $documentClass;
        }

        $namespace = Configure::read('App.namespace');
        if ($this->plugin) {
            $namespace = $this->_pluginNamespace($this->plugin);
        }

        $alias = Inflector::singularize($target->getAlias());
        $candidate = sprintf('%s\Model\Document\%s', $namespace, $alias);
        if (class_exists($candidate)) {
            return '\\' . $candidate;
        }

        return '\\' . Document::class;
    }

    /**
     * Evaluates the fields and no-fields options, and returns if, and which
     * fields should be made accessible.
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $collection The collection.
     * @param \Cake\Console\Arguments $args CLI Arguments.
     * @return array<string>|false|null
     */
    public function getFields(BaseCollection $collection, Arguments $args): array|false|null
    {
        if ($args->getOption('no-fields')) {
            return false;
        }

        if ($args->getOption('fields')) {
            $fields = explode(',', (string)$args->getOption('fields'));

            return array_values(array_filter(array_map(trim(...), $fields)));
        }

        $schema = $collection->getSchema();
        $fields = $schema->columns();
        foreach ($collection->associations() as $assoc) {
            $fields[] = $assoc->getProperty();
        }

        $primaryKey = (array)$collection->getPrimaryKey();

        return array_values(array_diff($fields, $primaryKey));
    }

    /**
     * Get the hidden fields from a model.
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $model The collection.
     * @param \Cake\Console\Arguments $args CLI Arguments.
     * @return array<string> The columns to make accessible.
     */
    public function getHiddenFields(BaseCollection $model, Arguments $args): array
    {
        if ($args->getOption('no-hidden')) {
            return [];
        }

        if ($args->getOption('hidden')) {
            $fields = explode(',', (string)$args->getOption('hidden'));

            return array_values(array_filter(array_map(trim(...), $fields)));
        }

        $columns = $model->getSchema()->columns();
        $whitelist = ['token', 'password', 'passwd'];

        return array_values(array_intersect($columns, $whitelist));
    }

    /**
     * Generate default validation rules.
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $model The collection.
     * @param array<string, array<int|string, mixed>> $associations The associations list.
     * @param \Cake\Console\Arguments $args CLI Arguments.
     * @return array<string, array<string, mixed>>|false The validation rules.
     */
    public function getValidation(BaseCollection $model, array $associations, Arguments $args): array|false
    {
        if ($args->getOption('no-validation')) {
            return [];
        }

        $schema = $model->getSchema();
        if (!$schema instanceof CollectionSchema) {
            return false;
        }

        $fields = $schema->columns();
        if ($fields === []) {
            return false;
        }

        $validate = [];
        $primaryKey = $schema->primaryKey();
        $foreignKeys = [];
        foreach ($associations['belongsTo'] ?? [] as $assoc) {
            $foreignKeys[] = $assoc['foreignKey'];
        }

        foreach ($fields as $fieldName) {
            if ($fieldName === $primaryKey) {
                continue;
            }

            $field = $schema->getField($fieldName);
            $field['isForeignKey'] = in_array($fieldName, $foreignKeys, true);
            $validation = $this->fieldValidation($schema, $fieldName, $field, (array)$primaryKey);
            if ($validation !== []) {
                $validate[$fieldName] = $validation;
            }
        }

        return $validate;
    }

    /**
     * Does individual field validation handling.
     *
     * @param \Cake\Datasource\SchemaInterface $schema The collection schema.
     * @param string $fieldName Name of field to be validated.
     * @param array<string, mixed> $metaData Metadata for field.
     * @param array<string> $primaryKey The primary key field.
     * @return array<string, array<string, mixed>>
     */
    public function fieldValidation(SchemaInterface $schema, string $fieldName, array $metaData, array $primaryKey): array
    {
        $ignoreFields = ['_id', 'created', 'modified', 'updated'];
        if (in_array($fieldName, $ignoreFields, true)) {
            return [];
        }

        $type = $this->canonicalType((string)($metaData['type'] ?? 'string'));
        $rules = [];
        if ($fieldName === 'email') {
            $rules['email'] = [];
        } elseif ($type === 'objectid') {
            $rules['validId'] = ['rule' => 'validId', 'provider' => 'mongo'];
        } elseif ($type === 'integer' || $type === 'int64') {
            $rules['integer'] = [];
        } elseif ($type === 'float' || $type === 'decimal128') {
            $rules['decimal'] = [];
        } elseif ($type === 'boolean') {
            $rules['boolean'] = [];
        } elseif (in_array($type, ['date', 'datetime', 'timestamp'], true)) {
            $rules['dateTime'] = [];
        } elseif ($type === 'string') {
            $rules['scalar'] = [];
            if (isset($metaData['length']) && $metaData['length'] > 0) {
                $rules['maxLength'] = [(int)$metaData['length']];
            }
        }

        $validation = [];
        foreach ($rules as $rule => $ruleArgs) {
            $validation[$rule] = [
                'rule' => $rule,
                'args' => $ruleArgs,
            ];
        }

        $null = (bool)($metaData['null'] ?? false);
        if ($null) {
            $validation['allowEmpty'] = [
                'rule' => $this->getEmptyMethod($fieldName, $metaData),
                'args' => [],
            ];
        } else {
            if (($metaData['default'] ?? null) === null && empty($metaData['isForeignKey'])) {
                $validation['requirePresence'] = [
                    'rule' => 'requirePresence',
                    'args' => ['create'],
                ];
            }

            $validation['notEmpty'] = [
                'rule' => $this->getEmptyMethod($fieldName, $metaData, 'not'),
                'args' => [],
            ];
        }

        if (isset($metaData['unique']) && $metaData['unique'] === true) {
            $validation['unique'] = ['rule' => 'validateUnique', 'provider' => 'collection'];
        }

        return $validation;
    }

    /**
     * Get the specific allow empty method for field based on metadata.
     *
     * @param string $fieldName Field name.
     * @param array<string, mixed> $metaData Field meta data.
     * @param string $prefix Method name prefix.
     * @return string
     */
    protected function getEmptyMethod(string $fieldName, array $metaData, string $prefix = 'allow'): string
    {
        $type = (string)($metaData['type'] ?? 'string');
        switch ($type) {
            case 'date':
                return $prefix . 'EmptyDate';

            case 'datetime':
            case 'timestamp':
                return $prefix . 'EmptyDateTime';
        }

        if (preg_match('/(^|\s|_|-)(attachment|file|image)$/i', $fieldName)) {
            return $prefix . 'EmptyFile';
        }

        return $prefix . 'EmptyString';
    }

    /**
     * Generate default rules checker.
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $model The collection.
     * @param array<string, array<int|string, mixed>> $associations The associations for the model.
     * @param \Cake\Console\Arguments $args CLI Arguments.
     * @return list<array<string, mixed>>
     */
    public function getRules(BaseCollection $model, array $associations, Arguments $args): array
    {
        if ($args->getOption('no-rules')) {
            return [];
        }

        $schema = $model->getSchema();
        if (!$schema instanceof CollectionSchema) {
            return [];
        }

        $schemaFields = $schema->columns();
        if ($schemaFields === []) {
            return [];
        }

        $uniqueRules = [];
        $uniqueColumns = [];
        foreach ($schema->indexes() as $index) {
            if (!$index->getUnique()) {
                continue;
            }

            $keyFields = array_keys($index->getKey());
            $uniqueColumns = [...$uniqueColumns, ...$keyFields];

            $rule = ['name' => 'isUnique', 'fields' => $keyFields, 'options' => []];
            if (count($keyFields) > 1) {
                $rule['message'] = sprintf(
                    'This combination of %s and %s already exists',
                    implode(', ', array_slice($keyFields, 0, -1)),
                    end($keyFields),
                );
            }

            $uniqueRules[] = $rule;
        }

        $possiblyUniqueColumns = ['username', 'login'];
        if (in_array($model->getAlias(), ['Users', 'Accounts'], true)) {
            $possiblyUniqueColumns[] = 'email';
        }

        $possiblyUniqueRules = [];
        foreach ($schemaFields as $field) {
            if (
                !in_array($field, $uniqueColumns, true) &&
                in_array($field, $possiblyUniqueColumns, true)
            ) {
                $possiblyUniqueRules[] = ['name' => 'isUnique', 'fields' => [$field], 'options' => []];
            }
        }

        $rules = [...$possiblyUniqueRules, ...$uniqueRules];

        if (empty($associations['belongsTo'])) {
            return $rules;
        }

        foreach ($associations['belongsTo'] as $assoc) {
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
     * Get behaviors (Timestamp, Tree).
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $model The collection.
     * @return array<string, array<mixed>> Behaviors.
     */
    public function getBehaviors(BaseCollection $model): array
    {
        $behaviors = [];
        $schema = $model->getSchema();
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
     * Bake a collection class.
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $model Collection instance.
     * @param array<string, mixed> $data Context data.
     * @param \Cake\Console\Arguments $args CLI Arguments.
     * @param \Cake\Console\ConsoleIo $io CLI io.
     * @return void
     */
    public function bakeCollection(BaseCollection $model, array $data, Arguments $args, ConsoleIo $io): void
    {
        if ($args->getOption('no-collection')) {
            return;
        }

        $name = $model->getAlias();
        $io->out("\n" . sprintf('Baking collection class for %s...', $name));

        $namespace = Configure::read('App.namespace');
        if ($this->plugin) {
            $namespace = $this->_pluginNamespace($this->plugin);
        }

        $path = $this->getPath($args);
        $filename = $path . 'Collection' . DS . $name . 'Collection.php';

        $documentClass = sprintf('%s\Model\Document\%s', $namespace, Inflector::singularize($name));

        $data += [
            'name' => $name,
            'namespace' => $namespace,
            'plugin' => $this->plugin,
            'connection' => $this->connection,
            'document' => Inflector::singularize($name),
            'documentClass' => class_exists($documentClass) ? $documentClass : null,
            'fileBuilder' => new FileBuilder($io, "{$namespace}\Model\Collection"),
        ];

        $contents = $this->createTemplateRenderer()
            ->set($data)
            ->generate('Crustum/Mongo.Collection/collection');
        $contents = str_replace("\r\n", "\n", $contents);

        $this->writeFile($io, $filename, $contents, $this->force);

        $emptyFile = $path . 'Collection' . DS . '.gitkeep';
        $this->deleteEmptyFile($emptyFile, $io);
    }

    /**
     * Bake a document class.
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $model Collection instance.
     * @param array<string, mixed> $data Context data.
     * @param \Cake\Console\Arguments $args CLI Arguments.
     * @param \Cake\Console\ConsoleIo $io CLI io.
     * @return void
     */
    public function bakeDocument(BaseCollection $model, array $data, Arguments $args, ConsoleIo $io): void
    {
        if ($args->getOption('no-document')) {
            return;
        }

        $name = Inflector::singularize($model->getAlias());
        $io->out("\n" . sprintf('Baking document class for %s...', $name));

        $namespace = Configure::read('App.namespace');
        if ($this->plugin) {
            $namespace = $this->_pluginNamespace($this->plugin);
        }

        $path = $this->getPath($args);
        $filename = $path . 'Document' . DS . $name . '.php';

        $collectionName = $model->getCollection();
        $schema = $model->describeSchema();
        if (!$schema instanceof CollectionSchema) {
            $schema = $model->getSchema();
        }

        if (!$schema instanceof CollectionSchema) {
            $io->error(sprintf('Unable to introspect schema for `%s`.', $model->getAlias()));

            return;
        }

        $fields = [];
        $embedded = $this->embeddedFields($schema);
        foreach ($schema->columns() as $fieldName) {
            $type = $this->canonicalType((string)$schema->getFieldType($fieldName));
            $fields[] = [
                'name' => $fieldName,
                'type' => $type,
                'constant' => $this->typeConstant($type),
                'nullable' => $schema->isNullable($fieldName),
                'primaryKey' => $fieldName === '_id',
                'embedded' => $embedded[$fieldName] ?? null,
            ];
        }

        $fields = $this->resolveEmbedded($name, $fields);

        $fieldNames = array_values(array_diff(array_column($fields, 'name'), ['_id']));
        $useConstants = array_any($fields, fn(array $field): bool => $field['constant'] !== null);

        $data += [
            'name' => $name,
            'namespace' => $namespace,
            'plugin' => $this->plugin,
            'hidden' => $data['hidden'] ?? ['password', 'token'],
            'collection' => $collectionName,
            'useConstants' => $useConstants,
            'fileBuilder' => new FileBuilder($io, "{$namespace}\Model\Document"),
        ];
        $data['fields'] = $fields;
        $data['fieldNames'] = $fieldNames;
        $data['primaryKey'] = ['_id'];

        $contents = $this->createTemplateRenderer()
            ->set($data)
            ->generate('Crustum/Mongo.Document/document');
        $contents = str_replace("\r\n", "\n", $contents);

        $this->writeFile($io, $filename, $contents, $this->force);

        $this->bakeEmbeddedDocuments($name, $fields, $args, $io);

        $emptyFile = $path . 'Document' . DS . '.gitkeep';
        $this->deleteEmptyFile($emptyFile, $io);
    }

    /**
     * Detects embedded field definitions from a live CollectionSchema.
     *
     * Reuses `SchemaFields::fromSchema()` on the validator so `bake mongo_model`
     * and `bake document` agree on the embedded detection rule. Only fields
     * carrying an embedded definition are returned, keyed by field name.
     *
     * @param \Crustum\Mongo\Database\Schema\CollectionSchema $schema The schema.
     * @return array<string, array{many: bool, key: string, fields: list<array{name: string, type: string, nullable: bool, primaryKey: bool}>}> Embedded definitions keyed by field name.
     */
    protected function embeddedFields(CollectionSchema $schema): array
    {
        $dump = [
            'collection' => [
                'validator' => $schema->validator()->toArray(),
            ],
        ];
        $fields = SchemaFields::fromSchema($dump, 'collection');

        $embedded = [];
        foreach ($fields as $fieldName => $definition) {
            if (isset($definition['embedded'])) {
                $embedded[(string)$fieldName] = $definition['embedded'];
            }
        }

        return $embedded;
    }

    /**
     * Resolves embedded fields to their generated document class names.
     *
     * @param string $parentName The parent document class name.
     * @param list<array<string, mixed>> $fields Schema fields.
     * @return list<array<string, mixed>>
     */
    protected function resolveEmbedded(string $parentName, array $fields): array
    {
        foreach ($fields as &$field) {
            if (empty($field['embedded'])) {
                continue;
            }

            $embedded = $field['embedded'];
            $field['embeddedClass'] = $parentName . Inflector::camelize(
                Inflector::singularize((string)$embedded['key']),
            );
            $field['embeddedMany'] = $embedded['many'];
            $field['embeddedKey'] = $embedded['key'];
        }

        unset($field);

        return $fields;
    }

    /**
     * Generates an embedded Document class for every embedded field.
     *
     * @param string $parentName The parent document class name.
     * @param list<array<string, mixed>> $fields Schema fields.
     * @param \Cake\Console\Arguments $args CLI arguments.
     * @param \Cake\Console\ConsoleIo $io Console io.
     * @return void
     */
    protected function bakeEmbeddedDocuments(string $parentName, array $fields, Arguments $args, ConsoleIo $io): void
    {
        foreach ($fields as $field) {
            if (empty($field['embedded'])) {
                continue;
            }

            $embedded = $field['embedded'];
            $embeddedName = (string)$field['embeddedClass'];
            $io->out("\n" . sprintf('Baking embedded document class for %s...', $embeddedName));

            $path = $this->getPath($args);
            $filename = $path . 'Document' . DS . $embeddedName . '.php';

            $namespace = Configure::read('App.namespace');
            if ($this->plugin) {
                $namespace = $this->_pluginNamespace($this->plugin);
            }

            $fields2 = [];
            foreach ($embedded['fields'] as $nested) {
                $fields2[] = [
                    'name' => $nested['name'],
                    'type' => $nested['type'],
                    'constant' => $this->typeConstant($nested['type']),
                    'nullable' => $nested['nullable'],
                    'primaryKey' => $nested['primaryKey'],
                    'enum' => null,
                ];
            }

            $data = [
                'name' => $embeddedName,
                'namespace' => $namespace,
                'plugin' => $this->plugin,
                'fields' => $fields2,
                'fieldNames' => array_values(array_diff(array_column($fields2, 'name'), ['_id'])),
                'propertySchema' => $this->embeddedPropertySchema($fields2),
                'primaryKey' => ['_id'],
                'hidden' => [],
                'collection' => null,
                'useConstants' => array_any($fields2, fn(array $f): bool => $f['constant'] !== null),
                'enumTypes' => [],
                'embedded' => [
                    'many' => $embedded['many'],
                    'key' => $embedded['key'],
                ],
                'fileBuilder' => new FileBuilder($io, "{$namespace}\Model\Document"),
            ];

            $contents = $this->createTemplateRenderer()
                ->set($data)
                ->generate('Crustum/Mongo.Document/embedded');
            $contents = str_replace("\r\n", "\n", $contents);

            $this->writeFile($io, $filename, $contents, $this->force);
        }
    }

    /**
     * Builds the property schema map for an embedded document.
     *
     * @param list<array<string, mixed>> $fields Embedded fields.
     * @return array<string, array{kind: string, type: string, null: bool}>
     */
    protected function embeddedPropertySchema(array $fields): array
    {
        $schema = [];
        foreach ($fields as $field) {
            $schema[$field['name']] = [
                'kind' => 'column',
                'type' => $field['type'],
                'null' => $field['nullable'],
            ];
        }

        return $schema;
    }

    /**
     * Bake a fixture class.
     *
     * @param string $className Name of class to bake fixture for.
     * @param string $useCollection Collection name.
     * @param \Cake\Console\Arguments $args Arguments instance.
     * @param \Cake\Console\ConsoleIo $io ConsoleIo instance.
     * @return void
     */
    public function bakeFixture(string $className, string $useCollection, Arguments $args, ConsoleIo $io): void
    {
        if ($args->getOption('no-fixture')) {
            return;
        }

        $command = new MongoFixtureCommand();
        $fixtureArgs = new Arguments(
            [$className],
            ['collection' => $useCollection] + $args->getOptions(),
            ['name'],
        );
        $command->execute($fixtureArgs, $io);
    }

    /**
     * Bake a unit test class.
     *
     * @param string $className Model class name.
     * @param \Cake\Console\Arguments $args Arguments instance.
     * @param \Cake\Console\ConsoleIo $io ConsoleIo instance.
     * @return void
     */
    public function bakeTest(string $className, Arguments $args, ConsoleIo $io): void
    {
        if ($args->getOption('no-test')) {
            return;
        }

        $command = new MongoTestCommand();
        $testArgs = new Arguments(
            ['collection', $className],
            $args->getOptions(),
            ['type', 'name'],
        );
        $command->execute($testArgs, $io);
    }

    /**
     * Get the collection name for the model being baked.
     *
     * @param string $name Model name.
     * @param \Cake\Console\Arguments $args The CLI arguments.
     * @return string
     */
    public function getCollectionName(string $name, Arguments $args): string
    {
        if ($args->getOption('collection')) {
            return (string)$args->getOption('collection');
        }

        return Inflector::tableize($name);
    }

    /**
     * Outputs the list of possible collections from the database.
     *
     * @return array<string>
     */
    public function listAll(): array
    {
        if ($this->_collections !== []) {
            return $this->_collections;
        }

        $connection = $this->mongoConnection();
        $this->_collections = array_values(array_filter(
            $connection->getSchemaCollection()->listCollections(),
            fn(string $name): bool => !$this->isSkippedCollection($name),
        ));

        return $this->_collections;
    }

    /**
     * Outputs the list of unskipped collections from the database.
     *
     * @return array<string>
     */
    public function listUnskipped(): array
    {
        return $this->listAll();
    }

    /**
     * Describes a collection schema by name.
     *
     * @param string $collectionName Collection name.
     * @return \Crustum\Mongo\Database\Schema\CollectionSchema|null
     */
    protected function describeCollection(string $collectionName): ?CollectionSchema
    {
        $described = $this->mongoConnection()->getSchemaCollection()->describe($collectionName);
        if (!$described instanceof CollectionSchema) {
            return null;
        }

        return $described;
    }

    /**
     * Returns the Mongo connection.
     *
     * @return \Crustum\Mongo\Database\Connection
     */
    protected function mongoConnection(): Connection
    {
        $connection = ConnectionManager::get($this->connection);
        if (!$connection instanceof Connection) {
            throw new RuntimeException(sprintf(
                'Connection `%s` is not a %s instance.',
                $this->connection,
                Connection::class,
            ));
        }

        return $connection;
    }

    /**
     * Whether a collection name should be skipped.
     *
     * @param string $name Collection name.
     * @return bool
     */
    protected function isSkippedCollection(string $name): bool
    {
        foreach ($this->skipCollections as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Maps a raw Mongo schema type to the canonical TypeFactory name.
     *
     * `CollectionSchema::getFieldType()` may return raw bson spellings
     * (`int`, `bool`, `objectId`) which the `typeConstant()`/`fieldValidation()`
     * maps only know in their canonical form (`integer`, `boolean`, `objectid`).
     *
     * @param string $type The schema type name.
     * @return string The canonical type name.
     */
    protected function canonicalType(string $type): string
    {
        return match ($type) {
            'int' => 'integer',
            'bool' => 'boolean',
            'objectId' => 'objectid',
            'long' => 'int64',
            'double' => 'float',
            'decimal' => 'decimal128',
            'binData' => 'binary',
            'object' => 'hash',
            'array' => 'collection',
            default => $type,
        };
    }

    /**
     * Maps a canonical Mongo type to a `CollectionSchemaInterface::TYPE_*`
     * constant name, or null when no constant exists.
     *
     * @param string $type Canonical type name.
     * @return string|null
     */
    protected function typeConstant(string $type): ?string
    {
        $map = [
            'objectid' => 'TYPE_OBJECTID',
            'string' => 'TYPE_STRING',
            'uuid' => 'TYPE_UUID',
            'integer' => 'TYPE_INTEGER',
            'int64' => 'TYPE_INT64',
            'float' => 'TYPE_FLOAT',
            'decimal128' => 'TYPE_DECIMAL',
            'boolean' => 'TYPE_BOOLEAN',
            'date' => 'TYPE_DATE',
            'datetime' => 'TYPE_DATETIME',
            'timestamp' => 'TYPE_TIMESTAMP',
            'array' => 'TYPE_ARRAY',
            'hash' => 'TYPE_HASH',
            'collection' => 'TYPE_COLLECTION',
            'binary' => 'TYPE_BINARY',
            'raw' => 'TYPE_RAW',
        ];

        return $map[$type] ?? null;
    }

    /**
     * Ensures association aliases are unique.
     *
     * @param array<string, array<int|string, mixed>> $associations The associations.
     * @return array<string, array<int|string, mixed>>
     */
    protected function ensureAliasUniqueness(array $associations): array
    {
        $existing = [];
        foreach ($associations as $type => $associationsPerType) {
            foreach ($associationsPerType as $k => $association) {
                $alias = $association['alias'];
                if (in_array($alias, $existing, true)) {
                    $alias = $this->_modelNameFromKey((string)$association['foreignKey']);
                }

                $existing[] = $alias;
                $association['alias'] = $alias;
                $associations[$type][$k] = $association;
            }
        }

        return $associations;
    }

    /**
     * Creates the template renderer with Mongo bake helpers loaded.
     *
     * @return \Bake\Utility\TemplateRenderer
     */
    public function createTemplateRenderer(): TemplateRenderer
    {
        $renderer = parent::createTemplateRenderer();
        $renderer->viewBuilder()->addHelpers([
            'Crustum/Mongo.MongoBake' => ['className' => MongoBakeHelper::class],
            'Crustum/Mongo.MongoDocBlock' => ['className' => MongoDocBlockHelper::class],
        ]);

        return $renderer;
    }

    /**
     * @inheritDoc
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser = $this->_setCommonOptions($parser);
        $parser->addOption('connection', [
            'default' => 'mongo',
            'help' => 'The datasource connection to get data from.',
        ]);

        $parser->setDescription(
            'Bake collection and document classes.',
        )->addArgument('name', [
            'help' => 'Name of the model to bake (without the Collection suffix). ' .
                'You can use Plugin.name to bake plugin models.',
        ])->addOption('update', [
            'boolean' => true,
            'help' => "Update generated methods in existing files. If the file doesn't exist it will be created.",
        ])->addOption('collection', [
            'help' => 'The collection name to use if you have non-conventional collection names.',
        ])->addOption('no-document', [
            'boolean' => true,
            'help' => 'Disable generating a document class.',
        ])->addOption('no-collection', [
            'boolean' => true,
            'help' => 'Disable generating a collection class.',
        ])->addOption('no-validation', [
            'boolean' => true,
            'help' => 'Disable generating validation rules.',
        ])->addOption('no-rules', [
            'boolean' => true,
            'help' => 'Disable generating a rules checker.',
        ])->addOption('no-associations', [
            'boolean' => true,
            'help' => 'Disable generating associations.',
        ])->addOption('no-fields', [
            'boolean' => true,
            'help' => 'Disable generating accessible fields in the document.',
        ])->addOption('fields', [
            'help' => 'A comma separated list of fields to make accessible.',
        ])->addOption('no-hidden', [
            'boolean' => true,
            'help' => 'Disable generating hidden fields in the document.',
        ])->addOption('hidden', [
            'help' => 'A comma separated list of fields to hide.',
        ])->addOption('primary-key', [
            'help' => 'The primary key if you would like to manually set one.' .
                ' Can be a comma separated list if you are using a composite primary key.',
        ])->addOption('display-field', [
            'help' => 'The displayField if you would like to choose one.',
        ])->addOption('no-test', [
            'boolean' => true,
            'help' => 'Do not generate a test case skeleton.',
        ])->addOption('no-fixture', [
            'boolean' => true,
            'help' => 'Do not generate a test fixture skeleton.',
        ])->setEpilog(
            'Omitting all arguments and options will list the collection names you can generate models for.',
        );

        return $parser;
    }

    /**
     * @inheritDoc
     */
    public static function defaultName(): string
    {
        return 'bake mongo_model';
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public static function getDescription(): string
    {
        return 'Bake a Mongo Collection and Document';
    }

    /**
     * @inheritDoc
     */
    public function name(): string
    {
        return 'mongo_model';
    }
}

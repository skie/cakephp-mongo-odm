<?php
declare(strict_types=1);

namespace Crustum\Mongo\Command\Bake;

use BackedEnum;
use Bake\CodeGen\FileBuilder;
use Bake\Command\BakeCommand;
use Bake\Utility\TemplateRenderer;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\Utility\Inflector;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Migration\Util\SchemaFields;
use Crustum\Mongo\View\Helper\MongoBakeHelper;
use Crustum\Mongo\View\Helper\MongoDocBlockHelper;
use Override;
use RuntimeException;

/**
 * Command for generating Mongo Document classes.
 *
 * Usage:
 * ```
 * bin/cake bake document Article
 * bin/cake bake document Article --collection articles
 * bin/cake bake document Article --collection articles --schema-file config/MongoMigrations/schema-dump-mongo.lock
 * ```
 */
class DocumentCommand extends BakeCommand
{
    /**
     * Path to Document directory.
     *
     * @var string
     */
    public string $pathFragment = 'Model/Document/';

    /**
     * @inheritDoc
     */
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $this->extractCommonProperties($args);
        $name = $args->getArgumentAt(0);
        if (empty($name)) {
            $io->error('You must provide a name to bake a Document.');
            $this->abort();
        }

        $name = $this->_getName($name);
        $name = Inflector::camelize(Inflector::singularize($name));
        $this->bake($name, $args, $io);

        return static::CODE_SUCCESS;
    }

    /**
     * Generates the document class file.
     *
     * @param string $name Class name.
     * @param \Cake\Console\Arguments $args CLI arguments.
     * @param \Cake\Console\ConsoleIo $io Console io.
     * @return void
     */
    public function bake(string $name, Arguments $args, ConsoleIo $io): void
    {
        $path = $this->getPath($args);
        $filename = $path . $name . '.php';
        $io->out("\n" . sprintf('Baking document class for %s...', $name));

        $namespace = Configure::read('App.namespace');
        if ($this->plugin) {
            $namespace = $this->_pluginNamespace($this->plugin);
        }

        $collection = $args->getOption('collection');
        if (!is_string($collection) || $collection === '') {
            $collection = Inflector::tableize($name);
        }

        $fields = $this->schemaFields($name, $args, $io);
        $propertySchema = $this->propertySchema($fields);
        $primaryKey = ['_id'];
        $hidden = $this->hiddenFields($fields);
        $fieldNames = array_values(array_diff(array_column($fields, 'name'), ['_id']));
        $useConstants = array_any(
            $fields,
            fn(array $field): bool => $field['constant'] !== null,
        );
        $enumTypes = $this->enumTypes($name, $fields);

        $fields = $this->resolveEmbedded($name, $fields);

        $parsedFile = null;
        if ($args->getOption('update')) {
            $parsedFile = $this->parseFile($filename);
        }

        $data = [
            'name' => $name,
            'namespace' => $namespace,
            'plugin' => $this->plugin,
            'fields' => $fields,
            'fieldNames' => $fieldNames,
            'propertySchema' => $propertySchema,
            'primaryKey' => $primaryKey,
            'hidden' => $hidden,
            'collection' => $collection,
            'useConstants' => $useConstants,
            'enumTypes' => $enumTypes,
            'fileBuilder' => new FileBuilder($io, "{$namespace}\Model\Document", $parsedFile),
        ];

        $contents = $this->createTemplateRenderer()
            ->set($data)
            ->generate('Crustum/Mongo.Document/document');
        $contents = str_replace("\r\n", "\n", $contents);

        $io->createFile($filename, $contents, $this->force);

        $this->bakeEmbeddedDocuments($name, $fields, $args, $io);

        $emptyFile = $path . '.gitkeep';
        $this->deleteEmptyFile($emptyFile, $io);
    }

    /**
     * Resolves embedded fields to their generated document class names.
     *
     * Each embedded field gains an `embeddedClass` short name (e.g. `Address`
     * for `addresses`) used by the template to emit `#[Embedded]` and by
     * `bakeEmbeddedDocuments()` to generate the class file.
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
            $filename = $path . $embeddedName . '.php';

            $namespace = Configure::read('App.namespace');
            if ($this->plugin) {
                $namespace = $this->_pluginNamespace($this->plugin);
            }

            $fields2 = [];
            foreach ($embedded['fields'] as $nested) {
                $fields2[] = [
                    'name' => $nested['name'],
                    'type' => $nested['type'],
                    'constant' => SchemaFields::typeConstant($nested['type']),
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
                'propertySchema' => $this->propertySchema($fields2),
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

            $io->createFile($filename, $contents, $this->force);
        }
    }

    /**
     * Resolves the `#[Field]` attributes for the document.
     *
     * Prefers the schema dump lock file, then the live connection.
     *
     * @param string $name Document class name
     * @param \Cake\Console\Arguments $args CLI arguments
     * @param \Cake\Console\ConsoleIo $io Console io
     * @return list<array{name: string, type: string, constant: string|null, nullable: bool, primaryKey: bool, enum: list<mixed>|null}>
     */
    protected function schemaFields(string $name, Arguments $args, ConsoleIo $io): array
    {
        $collection = $args->getOption('collection');
        if (!is_string($collection) || $collection === '') {
            $collection = Inflector::tableize($name);
        }

        $schemaFile = $args->getOption('schema-file');
        if (is_string($schemaFile) && $schemaFile !== '') {
            $fields = SchemaFields::fromLockFile($schemaFile, $collection);
            if ($fields === []) {
                $io->verbose(sprintf('No fields found for `%s` in `%s`.', $collection, $schemaFile));
            }
        } else {
            $connectionName = (string)($args->getOption('connection') ?: 'mongo');
            $connection = ConnectionManager::get($connectionName);
            if (!$connection instanceof Connection) {
                throw new RuntimeException(sprintf(
                    'Connection `%s` is not a %s instance.',
                    $connectionName,
                    Connection::class,
                ));
            }

            $fields = SchemaFields::fromConnection($connection, $collection);
        }

        $result = [];
        foreach ($fields as $fieldName => $definition) {
            $type = SchemaFields::typeName($definition['bsonType']);
            $enumValues = $definition['enum'] ?? null;
            $result[] = [
                'name' => $fieldName,
                'type' => $type,
                'constant' => SchemaFields::typeConstant($type),
                'nullable' => $definition['nullable'],
                'primaryKey' => $fieldName === '_id',
                'enum' => is_array($enumValues) && $enumValues !== [] ? $enumValues : null,
                'embedded' => $definition['embedded'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * Builds the property schema map for the document template.
     *
     * @param list<array{name: string, type: string, constant: string|null, nullable: bool, primaryKey: bool, enum: list<mixed>|null}> $fields Schema fields.
     * @return array<string, array{kind: string, type: string, null: bool}>
     */
    protected function propertySchema(array $fields): array
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
     * Resolves the default hidden fields for the document.
     *
     * @param list<array{name: string, type: string, constant: string|null, nullable: bool, primaryKey: bool}> $fields Schema fields.
     * @return list<string>
     */
    protected function hiddenFields(array $fields): array
    {
        return ['password', 'token'];
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
     * Resolves the `App\Model\Enum\{Entity}{Field}` class for enum-typed fields.
     *
     * Only fields that have enum values in the schema AND a matching baked enum
     * class are reported.
     *
     * @param string $name Document class name.
     * @param list<array{name: string, type: string, constant: string|null, nullable: bool, primaryKey: bool, enum: list<mixed>|null}> $fields Schema fields.
     * @return array<string, class-string<\BackedEnum>>
     */
    protected function enumTypes(string $name, array $fields): array
    {
        $enumTypes = [];
        foreach ($fields as $field) {
            if (empty($field['enum'])) {
                continue;
            }

            $className = sprintf('%s\Model\Enum\%s%s', $this->namespace(), $name, Inflector::camelize($field['name']));
            if (enum_exists($className) && is_subclass_of($className, BackedEnum::class)) {
                $enumTypes[$field['name']] = $className;
            }
        }

        return $enumTypes;
    }

    /**
     * Resolves the app/plugin namespace.
     *
     * @return string
     */
    protected function namespace(): string
    {
        $namespace = Configure::read('App.namespace');
        if ($this->plugin) {
            return $this->_pluginNamespace($this->plugin);
        }

        return $namespace;
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

        $parser->setDescription(static::getDescription())
            ->addArgument('name', [
                'help' => 'Name of the document class to bake (e.g., Article).',
                'required' => true,
            ])->addOption('collection', [
                'help' => 'The Mongo collection name (defaults to the tableized class name).',
            ])->addOption('schema-file', [
                'help' => 'The schema dump lock file to read fields from (defaults to live connection).',
            ])->addOption('update', [
                'boolean' => true,
                'help' => "Update generated methods in existing files. If the file doesn't exist it will be created.",
            ]);

        return $parser;
    }

    /**
     * @inheritDoc
     */
    public static function defaultName(): string
    {
        return 'bake document';
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public static function getDescription(): string
    {
        return 'Bake a Mongo Document class';
    }

    /**
     * @inheritDoc
     */
    public function name(): string
    {
        return 'document';
    }
}

<?php
declare(strict_types=1);

namespace Crustum\Mongo\Command\Bake;

use Bake\Command\BakeCommand;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\Utility\Inflector;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Migration\Util\SchemaFields;
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
        $name = Inflector::camelize($name);
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

        $fields = $this->schemaFields($name, $args, $io);
        $collection = $args->getOption('collection');
        if (!is_string($collection) || $collection === '') {
            $collection = Inflector::tableize($name);
        }

        $useConstants = array_any(
            $fields,
            fn(array $field): bool => $field['constant'] !== null,
        );

        $contents = $this->createTemplateRenderer()
            ->set('name', $name)
            ->set('namespace', $namespace)
            ->set('plugin', $this->plugin)
            ->set('fields', $fields)
            ->set('collection', $collection)
            ->set('useConstants', $useConstants)
            ->generate('Crustum/Mongo.Document/document');

        $io->createFile($filename, $contents, $this->force);

        $emptyFile = $path . '.gitkeep';
        $this->deleteEmptyFile($emptyFile, $io);
    }

    /**
     * Resolves the `#[Field]` attributes for the document.
     *
     * Prefers the schema dump lock file, then the live connection.
     *
     * @param string $name Document class name
     * @param \Cake\Console\Arguments $args CLI arguments
     * @param \Cake\Console\ConsoleIo $io Console io
     * @return list<array{name: string, type: string, constant: string|null, nullable: bool, primaryKey: bool}>
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
            $result[] = [
                'name' => $fieldName,
                'type' => $type,
                'constant' => SchemaFields::typeConstant($type),
                'nullable' => false,
                'primaryKey' => $fieldName === '_id',
            ];
        }

        return $result;
    }

    /**
     * @inheritDoc
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser = $this->_setCommonOptions($parser);
        $parser->setDescription(static::getDescription())
            ->addArgument('name', [
                'help' => 'Name of the document class to bake (e.g., Article).',
                'required' => true,
            ])->addOption('collection', [
                'help' => 'The Mongo collection name (defaults to the tableized class name).',
            ])->addOption('schema-file', [
                'help' => 'The schema dump lock file to read fields from (defaults to live connection).',
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

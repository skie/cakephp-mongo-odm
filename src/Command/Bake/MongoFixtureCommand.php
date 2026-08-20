<?php
declare(strict_types=1);

namespace Crustum\Mongo\Command\Bake;

use Bake\Command\BakeCommand;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Core\Configure;
use Cake\Datasource\FactoryLocator;
use Cake\Utility\Inflector;
use Crustum\Mongo\Database\Schema\CollectionSchema;
use Crustum\Mongo\ODM\BaseCollection;
use MongoDB\BSON\Binary;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Override;
use Throwable;

/**
 * Command for generating Mongo test fixtures.
 *
 * Usage:
 * ```
 * bin/cake bake mongofixture Articles
 * ```
 *
 * @rewritten-from \Bake\Command\FixtureCommand
 */
class MongoFixtureCommand extends BakeCommand
{
    /**
     * Path to Fixture directory.
     *
     * @var string
     */
    public string $pathFragment = 'tests/Fixture/';

    /**
     * @inheritDoc
     */
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $this->extractCommonProperties($args);
        $name = $args->getArgument('name') ?? '';
        $name = $this->_getName($name);

        if (empty($name)) {
            $io->error('You must provide a name to bake a Fixture.');
            $this->abort();
        }

        $name = $this->_camelize($name);
        $this->bake($name, $args, $io);

        return static::CODE_SUCCESS;
    }

    /**
     * Generates the fixture file.
     *
     * @param string $name Model name.
     * @param \Cake\Console\Arguments $args CLI arguments.
     * @param \Cake\Console\ConsoleIo $io Console io.
     * @return void
     */
    public function bake(string $name, Arguments $args, ConsoleIo $io): void
    {
        $path = $this->getPath($args);
        $filename = $path . $name . 'Fixture.php';
        $io->out("\n" . sprintf('Baking fixture class for %s...', $name));

        $namespace = Configure::read('App.namespace') . '\Test\Fixture';
        if ($this->plugin) {
            $namespace = $this->_pluginNamespace($this->plugin) . '\Test\Fixture';
        }

        $collectionName = (string)$args->getOption('collection');
        if ($collectionName === '') {
            $collectionName = Inflector::underscore(Inflector::pluralize($name));
        }

        $fields = [];
        $records = [];
        try {
            $locator = FactoryLocator::get('Collection');
            $collection = $locator->get($name);
            if ($collection instanceof BaseCollection) {
                $schema = $collection->describeSchema();
                if ($schema instanceof CollectionSchema) {
                    $fields = $schema->columns();
                    $records = $this->sampleRecords($schema);
                }
            }
        } catch (Throwable) {
            // no collection configured; fixture stays schema-less
        }

        $contents = $this->createTemplateRenderer()
            ->set('name', $name)
            ->set('namespace', $namespace)
            ->set('plugin', $this->plugin)
            ->set('collection', $collectionName)
            ->set('fields', $fields)
            ->set('records', $records)
            ->set('hasRecords', $records !== [])
            ->generate('Crustum/Mongo.Fixture/fixture');
        $contents = str_replace("\r\n", "\n", $contents);

        $io->createFile($filename, $contents, $this->force);

        $emptyFile = $path . '.gitkeep';
        $this->deleteEmptyFile($emptyFile, $io);
    }

    /**
     * Builds sample documents from the collection schema.
     *
     * Produces one sample document per field type so tests have realistic
     * fixture data without touching the database.
     *
     * @param \Crustum\Mongo\Database\Schema\CollectionSchema $schema The schema.
     * @return list<array<string, mixed>>
     */
    protected function sampleRecords(CollectionSchema $schema): array
    {
        $record = [];
        foreach ($schema->columns() as $field) {
            if ($field === '_id') {
                continue;
            }

            $record[$field] = $this->sampleValue($this->canonicalType($schema->getFieldType($field) ?? 'string'));
        }

        return $record === [] ? [] : [$record];
    }

    /**
     * Maps a raw Mongo schema type to the canonical TypeFactory name.
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
     * Produces a representative sample value for a field type.
     *
     * @param string $type Canonical Mongo type name.
     * @return mixed
     */
    protected function sampleValue(string $type): mixed
    {
        return match ($type) {
            'objectid' => new ObjectId(),
            'integer', 'int64' => 1,
            'float', 'decimal128' => 1.5,
            'boolean' => true,
            'date', 'datetime', 'timestamp' => new UTCDateTime(),
            'array', 'collection' => [],
            'hash' => [],
            'binary' => new Binary('data'),
            default => 'Sample data',
        };
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function getPath(Arguments $args): string
    {
        $path = ROOT . DS . 'tests' . DS . 'Fixture' . DS;
        if ($this->plugin) {
            $path = $this->_pluginPath($this->plugin) . 'tests' . DS . 'Fixture' . DS;
        }

        return str_replace('/', DIRECTORY_SEPARATOR, $path);
    }

    /**
     * @inheritDoc
     */
    #[Override]
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser = $this->_setCommonOptions($parser);
        $parser->addOption('connection', [
            'default' => 'mongo',
            'help' => 'The datasource connection to get data from.',
        ]);

        $parser->setDescription(static::getDescription())
            ->addArgument('name', [
                'help' => 'Name of the fixture to bake (e.g., Articles). "Fixture" suffix is added automatically.',
                'required' => true,
            ])
            ->addOption('collection', [
                'help' => 'The collection name if it does not follow conventions.',
                'default' => '',
            ]);

        return $parser;
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public static function defaultName(): string
    {
        return 'bake mongofixture';
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public static function getDescription(): string
    {
        return 'Bake a Mongo test Fixture';
    }

    /**
     * @inheritDoc
     */
    public function name(): string
    {
        return 'mongofixture';
    }
}

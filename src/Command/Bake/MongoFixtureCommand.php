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
use Crustum\Mongo\ODM\BaseCollection;
use Override;

/**
 * Command for generating Mongo test fixtures.
 *
 * Usage:
 * ```
 * bin/cake bake mongofixture Articles
 * ```
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

        $table = (string)$args->getOption('table');
        if ($table === '') {
            $table = Inflector::underscore(Inflector::pluralize($name));
        }

        // Try to read schema fields from the matching collection.
        $fields = [];
        try {
            $locator = FactoryLocator::get('Collection');
            $collection = $locator->get($name);
            if ($collection instanceof BaseCollection) {
                $fields = $collection->getSchema()->columns();
            }
        } catch (\Throwable) {
            // no collection configured; fixture stays schema-less
        }

        $contents = $this->createTemplateRenderer()
            ->set('name', $name)
            ->set('namespace', $namespace)
            ->set('plugin', $this->plugin)
            ->set('table', $table)
            ->set('fields', $fields)
            ->generate('Crustum/Mongo.Fixture/fixture');

        $io->createFile($filename, $contents, $this->force);

        $emptyFile = $path . '.gitkeep';
        $this->deleteEmptyFile($emptyFile, $io);
    }

    /**
     * @inheritDoc
     */
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
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser = $this->_setCommonOptions($parser);
        $parser->setDescription(static::getDescription())
            ->addArgument('name', [
                'help' => 'Name of the fixture to bake (e.g., Articles). "Fixture" suffix is added automatically.',
                'required' => true,
            ])
            ->addOption('table', [
                'help' => 'The collection name if it does not follow conventions.',
                'default' => '',
            ]);

        return $parser;
    }

    /**
     * @inheritDoc
     */
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

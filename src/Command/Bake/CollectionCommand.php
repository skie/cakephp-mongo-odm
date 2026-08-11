<?php
declare(strict_types=1);

namespace Crustum\Mongo\Command\Bake;

use Bake\Command\BakeCommand;
use Bake\Utility\TemplateRenderer;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Core\Configure;
use Cake\Utility\Inflector;
use Override;

/**
 * Command for generating Mongo Collection classes.
 *
 * Usage:
 * ```
 * bin/cake bake collection Articles
 * bin/cake bake collection --connection mongo Articles
 * ```
 */
class CollectionCommand extends BakeCommand
{
    /**
     * Path to Collection directory.
     *
     * @var string
     */
    public string $pathFragment = 'Model/Collection/';

    /**
     * @inheritDoc
     */
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $this->extractCommonProperties($args);
        $name = $args->getArgumentAt(0);
        if (empty($name)) {
            $io->error('You must provide a name to bake a Collection.');
            $this->abort();
        }

        $name = $this->_getName($name);
        $name = Inflector::camelize($name);
        $this->bake($name, $args, $io);

        return static::CODE_SUCCESS;
    }

    /**
     * Generates the collection class file.
     *
     * @param string $name Class name.
     * @param \Cake\Console\Arguments $args CLI arguments.
     * @param \Cake\Console\ConsoleIo $io Console io.
     * @return void
     */
    public function bake(string $name, Arguments $args, ConsoleIo $io): void
    {
        $path = $this->getPath($args);
        $filename = $path . $name . 'Collection.php';
        $io->out("\n" . sprintf('Baking collection class for %s...', $name));

        $namespace = Configure::read('App.namespace');
        if ($this->plugin) {
            $namespace = $this->_pluginNamespace($this->plugin);
        }

        $contents = $this->createTemplateRenderer()
            ->set('name', $name)
            ->set('namespace', $namespace)
            ->set('plugin', $this->plugin)
            ->set('documentClass', $this->documentClassFor($name, $namespace))
            ->generate('Crustum/Mongo.Collection/collection');

        $io->createFile($filename, $contents, $this->force);

        $emptyFile = $path . '.gitkeep';
        $this->deleteEmptyFile($emptyFile, $io);
    }

    /**
     * @inheritDoc
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser = $this->_setCommonOptions($parser);
        $parser->setDescription(static::getDescription())
            ->addArgument('name', [
                'help' => 'Name of the collection class to bake (e.g., Articles). "Collection" suffix is added automatically.',
                'required' => true,
            ]);

        return $parser;
    }

    /**
     * Resolves the singular Document class for a collection, if it exists.
     *
     * @param string $name Collection class name (e.g. `Authors`).
     * @param string $namespace App namespace.
     * @return string|null The document FQCN or null when no Document class exists.
     */
    protected function documentClassFor(string $name, string $namespace): ?string
    {
        $singular = Inflector::singularize($name);
        $class = sprintf('%s\Model\Document\%s', $namespace, $singular);
        if (class_exists($class)) {
            return $class;
        }

        // fall back to the singularized class name without suffix
        return null;
    }

    /**
     * @inheritDoc
     */
    public static function defaultName(): string
    {
        return 'bake collection';
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public static function getDescription(): string
    {
        return 'Bake a Mongo Collection class';
    }

    /**
     * @inheritDoc
     */
    public function name(): string
    {
        return 'collection';
    }
}

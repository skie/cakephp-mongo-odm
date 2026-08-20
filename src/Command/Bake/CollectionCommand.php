<?php
declare(strict_types=1);

namespace Crustum\Mongo\Command\Bake;

use Bake\CodeGen\FileBuilder;
use Bake\Command\BakeCommand;
use Bake\Utility\TemplateRenderer;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Core\Configure;
use Cake\Core\Exception\CakeException;
use Cake\Datasource\FactoryLocator;
use Cake\Utility\Inflector;
use Crustum\Mongo\Bake\MongoCollectionContext;
use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\View\Helper\MongoBakeHelper;
use Crustum\Mongo\View\Helper\MongoDocBlockHelper;
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

        $locator = FactoryLocator::get('Collection');
        $modelObject = $locator->get($name);
        if (!$modelObject instanceof BaseCollection) {
            throw new CakeException(sprintf(
                '`%s` resolved to a non-ODM repository. Mongo bake requires a `%s`.',
                $name,
                BaseCollection::class,
            ));
        }

        $context = new MongoCollectionContext();
        $context->plugin = $this->plugin;

        $data = $context->build($modelObject);

        $documentClass = $this->documentClassFor($name, $namespace);

        $data += [
            'name' => $name,
            'namespace' => $namespace,
            'plugin' => $this->plugin,
            'connection' => $this->connection,
            'document' => $this->documentName($name),
            'documentClass' => $documentClass,
            'fileBuilder' => new FileBuilder($io, "{$namespace}\Model\Collection"),
        ];

        $contents = $this->createTemplateRenderer()
            ->set($data)
            ->generate('Crustum/Mongo.Collection/collection');
        $contents = str_replace("\r\n", "\n", $contents);

        $io->createFile($filename, $contents, $this->force);

        $emptyFile = $path . '.gitkeep';
        $this->deleteEmptyFile($emptyFile, $io);
    }

    /**
     * Creates the template renderer with Mongo bake helpers loaded.
     *
     * @return \Bake\Utility\TemplateRenderer
     */
    #[Override]
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
                'help' => 'Name of the collection class to bake (e.g., Articles). "Collection" suffix is added automatically.',
                'required' => true,
            ]);

        return $parser;
    }

    /**
     * Resolves the singular Document class name for a collection.
     *
     * @param string $name Collection class name (e.g. `Authors`).
     * @return string
     */
    protected function documentName(string $name): string
    {
        return Inflector::singularize($name);
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

        return null;
    }

    /**
     * @inheritDoc
     */
    #[Override]
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

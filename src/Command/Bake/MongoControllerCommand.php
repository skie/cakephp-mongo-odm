<?php
declare(strict_types=1);

/**
 * Ported and adapted from cakephp/bake ControllerCommand (MIT License).
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc.
 * @license https://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace Crustum\Mongo\Command\Bake;

use Bake\Command\BakeCommand;
use Bake\Utility\TemplateRenderer;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Core\Configure;
use Cake\Core\Plugin;
use Cake\Datasource\ConnectionManager;
use Cake\Datasource\FactoryLocator;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\View\Helper\MongoBakeHelper;
use Override;

/**
 * Command for generating Mongo CRUD controllers.
 *
 * Uses the CollectionLocator and generates controllers built on
 * newDocument()/patchDocument().
 *
 * Usage:
 * ```
 * bin/cake bake mongocontroller Articles
 * bin/cake bake mongocontroller Articles --no-test
 * ```
 */
class MongoControllerCommand extends BakeCommand
{
    /**
     * Path to Controller directory.
     *
     * @var string
     */
    public string $pathFragment = 'Controller/';

    /**
     * Collections to skip when listing.
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
        $name = $args->getArgument('name') ?? '';
        $name = $this->_getName($name);

        if (empty($name)) {
            $io->out('Possible controllers based on your current database:');
            foreach ($this->listCollections() as $collection) {
                $io->out('- ' . $this->_camelize($collection));
            }

            return static::CODE_SUCCESS;
        }

        $controller = $this->_camelize($name);
        $this->bake($controller, $args, $io);

        return static::CODE_SUCCESS;
    }

    /**
     * Assembles and writes a Controller file.
     *
     * @param string $controllerName Controller name already pluralized and correctly cased.
     * @param \Cake\Console\Arguments $args The console arguments.
     * @param \Cake\Console\ConsoleIo $io The console io.
     * @return void
     */
    public function bake(string $controllerName, Arguments $args, ConsoleIo $io): void
    {
        $io->quiet(sprintf('Baking controller class for %s...', $controllerName));

        $actions = [];
        if (!$args->getOption('no-actions') && !$args->getOption('actions')) {
            $actions = ['index', 'view', 'add', 'edit', 'delete'];
        }

        if ($args->getOption('actions')) {
            $actions = array_map(trim(...), explode(',', (string)$args->getOption('actions')));
            $actions = array_filter($actions);
        }

        if (!$args->getOption('actions') && Plugin::isLoaded('Authentication') && $controllerName === 'Users') {
            $actions[] = 'login';
        }

        $helpers = $this->getHelpers($args);
        $components = $this->getComponents($args);

        $prefix = $this->getPrefix($args);
        if ($prefix !== '' && $prefix !== '0') {
            $prefix = '\\' . str_replace('/', '\\', $prefix);
        }

        $baseNamespace = Configure::read('App.namespace');
        $namespace = $baseNamespace;
        if ($this->plugin) {
            $namespace = $this->_pluginNamespace($this->plugin);
        }

        if ($this->plugin && class_exists("{$namespace}\Controller\AppController")) {
            $baseNamespace = $namespace;
        }

        $currentModelName = $controllerName;
        $plugin = $this->plugin;
        $pluginPath = $plugin;
        if ($pluginPath) {
            $pluginPath .= '.';
        }

        $locator = FactoryLocator::get('Collection');
        $modelObj = $locator->get($pluginPath . $currentModelName);

        $pluralName = $this->_variableName($currentModelName);
        $singularName = $this->_singularName($currentModelName);
        $singularHumanName = $this->_singularHumanName($controllerName);
        $pluralHumanName = $this->_variableName($controllerName);

        if ($singularName === $pluralName) {
            $singularName .= 'Document';
        }

        $defaultModel = sprintf('%s\Model\Collection\%sCollection', $namespace, $controllerName);
        if (!class_exists($defaultModel)) {
            $defaultModel = null;
        }

        $entityClassName = $this->_entityName($modelObj->getAlias());

        $data = ['actions' => $actions, 'components' => $components, 'currentModelName' => $currentModelName, 'defaultModel' => $defaultModel, 'entityClassName' => $entityClassName, 'helpers' => $helpers, 'modelObj' => $modelObj, 'namespace' => $namespace, 'baseNamespace' => $baseNamespace, 'plugin' => $plugin, 'pluralHumanName' => $pluralHumanName, 'pluralName' => $pluralName, 'prefix' => $prefix, 'singularHumanName' => $singularHumanName, 'singularName' => $singularName];
        $data['name'] = $controllerName;

        $this->bakeController($controllerName, $data, $args, $io);
        $this->bakeTest($controllerName, $args, $io);
    }

    /**
     * Generate the controller code.
     *
     * @param string $controllerName The name of the controller.
     * @param array<string, mixed> $data The data to turn into code.
     * @param \Cake\Console\Arguments $args The console args.
     * @param \Cake\Console\ConsoleIo $io The console io.
     * @return void
     */
    public function bakeController(string $controllerName, array $data, Arguments $args, ConsoleIo $io): void
    {
        $data += [
            'name' => null,
            'namespace' => null,
            'prefix' => null,
            'actions' => null,
            'helpers' => null,
            'components' => null,
            'plugin' => null,
            'pluginPath' => null,
        ];

        $contents = $this->createTemplateRenderer()
            ->set($data)
            ->generate('Crustum/Mongo.Controller/controller');
        $contents = str_replace("\r\n", "\n", $contents);

        $path = $this->getPath($args);
        $filename = $path . $controllerName . 'Controller.php';
        $io->createFile($filename, $contents, $this->force);

        $emptyFile = $path . '.gitkeep';
        $this->deleteEmptyFile($emptyFile, $io);
    }

    /**
     * Assembles and writes a unit test file.
     *
     * @param string $className Controller class name.
     * @param \Cake\Console\Arguments $args The console arguments.
     * @param \Cake\Console\ConsoleIo $io The console io.
     * @return void
     */
    public function bakeTest(string $className, Arguments $args, ConsoleIo $io): void
    {
        if ($args->getOption('no-test')) {
            return;
        }

        $test = new MongoTestCommand();
        $testArgs = new Arguments(
            ['controller', $className],
            $args->getOptions(),
            ['type', 'name'],
        );
        $test->execute($testArgs, $io);
    }

    /**
     * Get the list of components for the controller.
     *
     * @param \Cake\Console\Arguments $args The console arguments.
     * @return array<string>
     */
    public function getComponents(Arguments $args): array
    {
        $components = [];
        if ($args->getOption('components')) {
            $components = explode(',', (string)$args->getOption('components'));
            $components = array_values(array_filter(array_map(trim(...), $components)));
        } elseif (Plugin::isLoaded('Authorization')) {
            $components[] = 'Authorization.Authorization';
        }

        return $components;
    }

    /**
     * Get the list of helpers for the controller.
     *
     * @param \Cake\Console\Arguments $args The console arguments.
     * @return array<string>
     */
    public function getHelpers(Arguments $args): array
    {
        $helpers = [];
        if ($args->getOption('helpers')) {
            $helpers = explode(',', (string)$args->getOption('helpers'));
            $helpers = array_values(array_filter(array_map(trim(...), $helpers)));
        }

        return $helpers;
    }

    /**
     * Returns the list of collections to bake controllers for.
     *
     * @return array<string>
     */
    protected function listCollections(): array
    {
        $connection = ConnectionManager::get($this->connection);
        if (!$connection instanceof Connection) {
            return [];
        }

        return array_values(array_filter(
            $connection->getSchemaCollection()->listCollections(),
            fn(string $name): bool => !$this->isSkippedCollection($name),
        ));
    }

    /**
     * Whether a collection name should be skipped when listing.
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
     * Creates the template renderer with the MongoBake helper loaded.
     *
     * @return \Bake\Utility\TemplateRenderer
     */
    public function createTemplateRenderer(): TemplateRenderer
    {
        $renderer = parent::createTemplateRenderer();
        $renderer->viewBuilder()->addHelpers([
            'Crustum/Mongo.MongoBake' => ['className' => MongoBakeHelper::class],
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
            'Bake a Mongo controller skeleton.',
        )->addArgument('name', [
            'help' => 'Name of the controller to bake (without the `Controller` suffix). ' .
                'You can use Plugin.name to bake controllers into plugins.',
        ])->addOption('components', [
            'help' => 'The comma separated list of components to use.',
        ])->addOption('helpers', [
            'help' => 'The comma separated list of helpers to use.',
        ])->addOption('prefix', [
            'help' => 'The namespace/routing prefix to use.',
        ])->addOption('actions', [
            'help' => 'The comma separated list of actions to generate. ' .
                'You can include custom methods provided by your template set here.',
        ])->addOption('no-test', [
            'boolean' => true,
            'help' => 'Do not generate a test skeleton.',
        ])->addOption('no-actions', [
            'boolean' => true,
            'help' => 'Do not generate basic CRUD action methods.',
        ]);

        return $parser;
    }

    /**
     * @inheritDoc
     */
    public static function defaultName(): string
    {
        return 'bake mongocontroller';
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public static function getDescription(): string
    {
        return 'Bake a Mongo CRUD Controller';
    }

    /**
     * @inheritDoc
     */
    public function name(): string
    {
        return 'mongocontroller';
    }
}

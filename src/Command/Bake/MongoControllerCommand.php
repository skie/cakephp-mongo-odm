<?php
declare(strict_types=1);

namespace Crustum\Mongo\Command\Bake;

use Bake\Command\BakeCommand;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Core\Configure;
use Cake\Datasource\FactoryLocator;
use Cake\Event\Event;
use Cake\Event\EventManager;
use Cake\Utility\Inflector;
use Crustum\Mongo\View\Helper\MongoBakeHelper;
use Override;

/**
 * Command for generating Mongo CRUD controllers.
 *
 * Uses the CollectionLocator and generates controllers built on
 * newDocument()/patchDocument()/getId().
 *
 * Usage:
 * ```
 * bin/cake bake mongocontroller Articles
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
     * @inheritDoc
     */
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $this->extractCommonProperties($args);
        $controllerName = $args->getArgumentAt(0);
        if (empty($controllerName)) {
            $io->error('You must provide a name to bake a Controller.');
            $this->abort();
        }

        $controllerName = $this->_getName($controllerName);
        $controllerName = Inflector::camelize($controllerName);
        $this->bake($controllerName, $args, $io);

        return static::CODE_SUCCESS;
    }

    /**
     * Assembles controller data and writes the file.
     *
     * @param string $controllerName Controller class name.
     * @param \Cake\Console\Arguments $args CLI arguments.
     * @param \Cake\Console\ConsoleIo $io Console io.
     * @return void
     */
    public function bake(string $controllerName, Arguments $args, ConsoleIo $io): void
    {
        $io->quiet(sprintf('Baking controller class for %s...', $controllerName));

        $this->registerMongoBakeHelper();

        $actions = [];
        if (!$args->getOption('no-actions') && !$args->getOption('actions')) {
            $actions = ['index', 'view', 'add', 'edit', 'delete'];
        }
        if ($args->getOption('actions')) {
            $actions = array_map('trim', explode(',', (string)$args->getOption('actions')));
            $actions = array_filter($actions);
        }

        $helpers = [];
        $components = [];

        $prefix = $this->getPrefix($args);
        if ($prefix) {
            $prefix = '\\' . str_replace('/', '\\', $prefix);
        }

        // Controllers default to importing AppController from `App`.
        $baseNamespace = $namespace = Configure::read('App.namespace');
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

        $data = compact(
            'actions',
            'components',
            'currentModelName',
            'defaultModel',
            'entityClassName',
            'helpers',
            'modelObj',
            'namespace',
            'baseNamespace',
            'plugin',
            'pluralHumanName',
            'pluralName',
            'prefix',
            'singularHumanName',
            'singularName',
        );
        $data['name'] = $controllerName;

        $contents = $this->createTemplateRenderer()
            ->set($data)
            ->generate('Crustum/Mongo.Controller/controller');

        $path = $this->getPath($args);
        $filename = $path . $controllerName . 'Controller.php';
        $io->createFile($filename, $contents, $this->force);

        $emptyFile = $path . '.gitkeep';
        $this->deleteEmptyFile($emptyFile, $io);
    }

    /**
     * Registers the MongoBake helper on the bake view.
     *
     * @return void
     */
    protected function registerMongoBakeHelper(): void
    {
        EventManager::instance()->on('Bake.initialize', function (Event $event): void {
            $view = $event->getSubject();
            if (method_exists($view, 'loadHelper')) {
                $view->loadHelper('Crustum/Mongo.MongoBake', ['className' => MongoBakeHelper::class]);
            }
        });
    }

    /**
     * @inheritDoc
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser = $this->_setCommonOptions($parser);
        $parser->setDescription(static::getDescription())
            ->addArgument('name', [
                'help' => 'Name of the controller class to bake (e.g., Articles).',
                'required' => true,
            ])
            ->addOption('actions', [
                'short' => 'a',
                'help' => 'Comma separated list of actions to generate. (e.g. index,view)',
                'default' => '',
            ])
            ->addOption('no-actions', [
                'boolean' => true,
                'help' => 'Do not generate actions.',
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

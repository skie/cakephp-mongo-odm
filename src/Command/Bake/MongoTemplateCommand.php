<?php
declare(strict_types=1);

namespace Crustum\Mongo\Command\Bake;

use Bake\Command\BakeCommand;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Core\App;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\Datasource\FactoryLocator;
use Cake\Event\Event;
use Cake\Event\EventManager;
use Cake\Utility\Inflector;
use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\View\Helper\MongoBakeHelper;
use InvalidArgumentException;
use Override;

/**
 * Command for generating Mongo view templates.
 *
 * Usage:
 * ```
 * bin/cake bake mongotemplate Articles
 * ```
 */
class MongoTemplateCommand extends BakeCommand
{
    /**
     * The model name being baked.
     *
     * @var string
     */
    protected string $modelName = '';

    /**
     * The controller name.
     *
     * @var string
     */
    protected string $controllerName = '';

    /**
     * @inheritDoc
     */
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $this->extractCommonProperties($args);
        $name = $args->getArgument('name') ?? '';
        $name = $this->_getName($name);

        if (empty($name)) {
            $io->error('You must provide a name to bake templates for.');
            $this->abort();
        }

        $this->registerMongoBakeHelper();
        $this->controller($args, $name, (string)$args->getOption('controller'));
        $this->model($name);

        $vars = $this->loadModel($io);
        $methods = $this->methodsToBake();

        foreach ($methods as $method) {
            $content = $this->getContent($args, $io, $method, $vars);
            $this->bake($args, $io, $method, $content);
        }

        return static::CODE_SUCCESS;
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
     * Sets the model class.
     *
     * @param string $name Model name.
     * @return void
     */
    public function model(string $name): void
    {
        $tableName = $this->_camelize($name);
        $plugin = $this->plugin;
        if ($plugin) {
            $plugin .= '.';
        }
        $this->modelName = $plugin . $tableName;
    }

    /**
     * Sets the controller related properties.
     *
     * @param \Cake\Console\Arguments $args CLI arguments.
     * @param string $name Model name.
     * @param string|null $controller Controller name.
     * @return void
     */
    public function controller(Arguments $args, string $name, ?string $controller = null): void
    {
        $tableName = $this->_camelize($name);
        if (empty($controller)) {
            $controller = $tableName;
        }
        $this->controllerName = $controller;

        $plugin = $this->plugin;
        if ($plugin) {
            $plugin .= '.';
        }
        $prefix = $this->getPrefix($args);
        if ($prefix) {
            $prefix .= '/';
        }
        $this->controllerClass = (string)App::className($plugin . $prefix . $controller, 'Controller', 'Controller');
    }

    /**
     * Loads the model and builds template variables.
     *
     * @param \Cake\Console\ConsoleIo $io Console io.
     * @return array<string, mixed>
     */
    protected function loadModel(ConsoleIo $io): array
    {
        $locator = FactoryLocator::get('Collection');
        $modelObject = $locator->get($this->modelName);

        if (!$modelObject instanceof BaseCollection) {
            $io->error(sprintf('`%s` is not a Mongo Collection.', $this->modelName));
            $this->abort();
        }

        $namespace = Configure::read('App.namespace');

        $primaryKey = (array)$modelObject->getPrimaryKey();
        $displayField = $modelObject->getDisplayField();
        $singularVar = $this->_singularName($this->controllerName);
        $singularHumanName = $this->_singularHumanName($this->controllerName);
        $pluralVar = Inflector::variable($this->controllerName);
        $pluralHumanName = $this->_pluralHumanName($this->controllerName);
        $schema = $modelObject->getSchema();
        $fields = $schema->columns();
        $hidden = $modelObject->newEmptyDocument()->getHidden() ?: ['token', 'password', 'passwd'];
        $modelClass = $this->modelName;

        if ($singularVar === $pluralVar) {
            $singularVar .= 'Document';
        }

        $documentClass = sprintf('%s\Model\Document\%s', $namespace, $singularHumanName);
        if (!class_exists($documentClass)) {
            $documentClass = EntityInterface::class;
        }

        $filter = new MongoAssociationFilter();
        $associations = $filter->filterAssociations($modelObject);
        $keyFields = [];
        foreach (['BelongsToMany', 'BelongsTo'] as $type) {
            foreach ($associations[$type] ?? [] as $assoc) {
                $keyFields[$assoc['foreignKey']] = $assoc['variable'];
            }
        }

        return compact(
            'modelObject',
            'modelClass',
            'documentClass',
            'schema',
            'primaryKey',
            'displayField',
            'singularVar',
            'pluralVar',
            'singularHumanName',
            'pluralHumanName',
            'fields',
            'hidden',
            'associations',
            'keyFields',
            'namespace',
        );
    }

    /**
     * Returns the methods to bake.
     *
     * @return list<string>
     */
    protected function methodsToBake(): array
    {
        return ['index', 'view', 'add', 'edit'];
    }

    /**
     * Renders a template for one view action.
     *
     * @param \Cake\Console\Arguments $args CLI arguments.
     * @param \Cake\Console\ConsoleIo $io Console io.
     * @param string $method Action name.
     * @param array<string, mixed> $vars Template variables.
     * @return string Generated content.
     */
    public function getContent(Arguments $args, ConsoleIo $io, string $method, array $vars): string
    {
        $entityClass = $vars['documentClass'] ?? EntityInterface::class;
        $useDomain = (bool)$this->plugin;
        $indexColumns = 0;

        $renderer = $this->createTemplateRenderer();
        $renderer->set($vars);
        $renderer->set('entityClass', $entityClass);
        $renderer->set('useDomain', $useDomain);
        $renderer->set('indexColumns', $indexColumns);
        $renderer->set('action', $method);

        return $renderer->generate(sprintf('Crustum/Mongo.Template/%s', $method));
    }

    /**
     * Writes a view template file.
     *
     * @param \Cake\Console\Arguments $args CLI arguments.
     * @param \Cake\Console\ConsoleIo $io Console io.
     * @param string $method Action name.
     * @param string $content Generated content.
     * @return void
     */
    public function bake(Arguments $args, ConsoleIo $io, string $method, string $content): void
    {
        $path = $this->getTemplatePath($args);
        $filename = $path . $method . '.php';
        $io->out("\n" . sprintf('Baking %s view template...', $method));

        $io->createFile($filename, $content, $this->force);
    }

    /**
     * @inheritDoc
     */
    public function getTemplatePath(Arguments $args, ?string $container = null): string
    {
        $paths = (array)Configure::read('App.paths.templates');
        if (empty($paths)) {
            throw new InvalidArgumentException('Could not read template paths.');
        }
        $path = $paths[0];
        if ($this->plugin) {
            $path = $this->_pluginPath($this->plugin) . 'templates' . DIRECTORY_SEPARATOR;
        }
        if ($container) {
            $path .= $container . DIRECTORY_SEPARATOR;
        }
        $prefix = $this->getPrefix($args);
        if ($prefix) {
            $path .= $prefix . DIRECTORY_SEPARATOR;
        }
        $path .= Inflector::camelize($this->controllerName) . DIRECTORY_SEPARATOR;

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
                'help' => 'Name of the collection to bake templates for (e.g., Articles).',
                'required' => true,
            ])
            ->addOption('controller', [
                'help' => 'Controller name if not equal to model name.',
                'default' => '',
            ]);

        return $parser;
    }

    /**
     * @inheritDoc
     */
    public static function defaultName(): string
    {
        return 'bake mongotemplate';
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public static function getDescription(): string
    {
        return 'Bake Mongo view templates';
    }

    /**
     * @inheritDoc
     */
    public function name(): string
    {
        return 'mongotemplate';
    }
}

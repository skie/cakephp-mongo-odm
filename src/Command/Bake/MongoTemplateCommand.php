<?php
declare(strict_types=1);

/**
 * Ported and adapted from cakephp/bake TemplateCommand (MIT License).
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
use Cake\Core\App;
use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\Datasource\EntityInterface;
use Cake\Datasource\FactoryLocator;
use Cake\Utility\Inflector;
use Cake\View\Exception\MissingTemplateException;
use Crustum\Mongo\Bake\MongoAssociationFilter;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\View\Helper\MongoBakeHelper;
use Exception;
use InvalidArgumentException;
use Override;
use RuntimeException;

/**
 * Command for generating Mongo view templates.
 *
 * Usage:
 * ```
 * bin/cake bake mongotemplate Articles
 * bin/cake bake mongotemplate Articles --index-columns 5
 * ```
 */
class MongoTemplateCommand extends BakeCommand
{
    /**
     * Name of the controller being used.
     *
     * @var string
     */
    public string $controllerName;

    /**
     * Class name of the controller being used.
     *
     * @var string
     */
    public string $controllerClass;

    /**
     * Name with plugin of the model being used.
     *
     * @var string
     */
    public string $modelName;

    /**
     * Actions to use for scaffolding.
     *
     * @var array<string>
     */
    public array $scaffoldActions = ['index', 'view', 'add', 'edit'];

    /**
     * Actions that exclude hidden fields.
     *
     * @var array<string>
     */
    public array $excludeHiddenActions = ['index', 'view'];

    /**
     * Template path.
     *
     * @var string
     */
    public string $path;

    /**
     * Output extension.
     *
     * @var string
     */
    public string $ext = 'php';

    /**
     * @inheritDoc
     */
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $this->extractCommonProperties($args);
        $name = $args->getArgument('name') ?? '';
        $name = $this->_getName($name);

        if (empty($name)) {
            $io->out('Possible collections to bake view templates for based on your current database:');
            $connection = ConnectionManager::get($this->connection);
            if ($connection instanceof Connection) {
                foreach ($connection->getSchemaCollection()->listCollections() as $collection) {
                    $io->out('- ' . $this->_camelize($collection));
                }
            }

            return static::CODE_SUCCESS;
        }

        $template = $args->getArgument('template');
        $action = $args->getArgument('action');

        $this->controller($args, $name, (string)$args->getOption('controller'));
        $this->model($name);

        if ($template && $action === null) {
            $action = $template;
        }

        if ($template) {
            $this->bake($args, $io, $template, true, $action);

            return static::CODE_SUCCESS;
        }

        $vars = $this->loadModel($io);
        $methods = $this->methodsToBake();

        foreach ($methods as $method) {
            try {
                $content = $this->getContent($args, $io, $method, $vars);
                $this->bake($args, $io, $method, $content);
            } catch (MissingTemplateException $e) {
                $io->verbose($e->getMessage());
            } catch (RuntimeException $e) {
                $io->error($e->getMessage());
            }
        }

        return static::CODE_SUCCESS;
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
        if ($locator->exists($this->modelName)) {
            $modelObject = $locator->get($this->modelName);
        } else {
            $modelObject = $locator->get($this->modelName, [
                'connectionName' => $this->connection,
            ]);
        }

        if (!$modelObject instanceof BaseCollection) {
            $io->error(sprintf('`%s` is not a Mongo Collection.', $this->modelName));
            $this->abort();
        }

        $namespace = Configure::read('App.namespace');
        $primaryKey = null;
        $displayField = null;
        $singularVar = null;
        $singularHumanName = null;
        $schema = null;
        $fields = null;
        $hidden = null;
        $modelClass = null;
        try {
            $primaryKey = (array)$modelObject->getPrimaryKey();
            $displayField = $modelObject->getDisplayField();
            $singularVar = $this->_singularName($this->controllerName);
            $singularHumanName = $this->_singularHumanName($this->controllerName);
            $schema = $modelObject->getSchema();
            $fields = $schema->columns();
            $hidden = $modelObject->newEmptyDocument()->getHidden() ?: ['token', 'password', 'passwd'];
            $modelClass = $this->modelName;
        } catch (Exception $exception) {
            $io->error($exception->getMessage());
            $this->abort();
        }

        $entityName = Inflector::singularize(Inflector::camelize($this->controllerName));
        $documentClass = sprintf('%s\Model\Document\%s', $namespace, $entityName);
        if (!class_exists($documentClass)) {
            $documentClass = EntityInterface::class;
        }

        $filter = new MongoAssociationFilter();
        $associations = $filter->filterAssociations($modelObject);
        $keyFields = [];

        if (isset($associations['BelongsToMany'])) {
            foreach ($associations['BelongsToMany'] as $assoc) {
                $keyFields[$assoc['foreignKey']] = $assoc['variable'];
            }
        }

        if (isset($associations['BelongsTo'])) {
            foreach ($associations['BelongsTo'] as $assoc) {
                $keyFields[$assoc['foreignKey']] = $assoc['variable'];
            }
        }

        $pluralVar = Inflector::variable($this->controllerName);
        $pluralHumanName = $this->_pluralHumanName($this->controllerName);

        if ($singularVar === $pluralVar) {
            $singularVar .= 'Document';
        }

        return ['modelObject' => $modelObject, 'modelClass' => $modelClass, 'documentClass' => $documentClass, 'schema' => $schema, 'primaryKey' => $primaryKey, 'displayField' => $displayField, 'singularVar' => $singularVar, 'pluralVar' => $pluralVar, 'singularHumanName' => $singularHumanName, 'pluralHumanName' => $pluralHumanName, 'fields' => $fields, 'hidden' => $hidden, 'associations' => $associations, 'keyFields' => $keyFields, 'namespace' => $namespace];
    }

    /**
     * Returns the methods to bake (from the controller, falling back to CRUD).
     *
     * @return list<string>
     */
    protected function methodsToBake(): array
    {
        $base = Configure::read('App.namespace');

        $methods = [];
        if (class_exists($this->controllerClass)) {
            $methods = array_diff(
                array_map(
                    Inflector::underscore(...),
                    get_class_methods($this->controllerClass),
                ),
                array_map(
                    Inflector::underscore(...),
                    get_class_methods($base . '\Controller\AppController'),
                ),
            );
        }

        if ($methods === []) {
            $methods = $this->scaffoldActions;
        }

        foreach ($methods as $i => $method) {
            if (isset($method[0]) && $method[0] === '_') {
                unset($methods[$i]);
            }
        }

        return array_values($methods);
    }

    /**
     * Renders a template for one view action.
     *
     * @param \Cake\Console\Arguments $args CLI arguments.
     * @param \Cake\Console\ConsoleIo $io Console io.
     * @param string $method Action name.
     * @param array<string, mixed>|null $vars Template variables.
     * @return string Generated content.
     */
    public function getContent(Arguments $args, ConsoleIo $io, string $method, ?array $vars = null): string
    {
        if ($vars === null) {
            $vars = $this->loadModel($io);
        }

        if (empty($vars['primaryKey'])) {
            $io->error('Cannot generate views for models with no primary key');
            $this->abort();
        }

        if (in_array($method, $this->excludeHiddenActions, true)) {
            $vars['fields'] = array_diff($vars['fields'], $vars['hidden']);
        }

        $renderer = $this->createTemplateRenderer();
        $renderer->set('action', $method);
        $renderer->set('plugin', $this->plugin);
        $renderer->set($vars);
        $renderer->set('entityClass', $vars['documentClass'] ?? EntityInterface::class);

        $indexColumns = 0;
        if ($method === 'index' && $args->getOption('index-columns') !== null) {
            $indexColumns = $args->getOption('index-columns');
        }

        $renderer->set('indexColumns', $indexColumns);

        $useDomain = (bool)$this->plugin;
        $renderer->set('useDomain', $useDomain);

        return str_replace("\r\n", "\n", $renderer->generate(sprintf('Crustum/Mongo.Template/%s', $method)));
    }

    /**
     * Writes a view template file.
     *
     * @param \Cake\Console\Arguments $args CLI arguments.
     * @param \Cake\Console\ConsoleIo $io Console io.
     * @param string $template Template file to use.
     * @param string|bool $content Content to write.
     * @param string|null $outputFile The output file to create.
     * @return void
     */
    public function bake(
        Arguments $args,
        ConsoleIo $io,
        string $template,
        string|bool $content = '',
        ?string $outputFile = null,
    ): void {
        if ($outputFile === null) {
            $outputFile = $template;
        }

        if ($content === true) {
            $content = $this->getContent($args, $io, $template);
        }

        if (empty($content)) {
            $io->warning("No generated content for '{$template}.{$this->ext}', not generating template.");

            return;
        }

        $path = $this->getTemplatePath($args);
        $filename = $path . Inflector::underscore($outputFile) . '.' . $this->ext;

        $io->out("\n" . sprintf('Baking `%s` view template file...', $outputFile));
        $io->createFile($filename, $content, $this->force);
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
    public function getTemplatePath(Arguments $args, ?string $container = null): string
    {
        $paths = (array)Configure::read('App.paths.templates');
        if ($paths === []) {
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
        $parser->addOption('connection', [
            'default' => 'mongo',
            'help' => 'The datasource connection to get data from.',
        ]);

        $parser->setDescription(
            'Bake views for a Mongo controller, using built-in or custom templates.',
        )->addArgument('name', [
            'help' => 'Name of the controller views to bake. You can use Plugin.name as a shortcut for plugin baking.',
        ])->addArgument('template', [
            'help' => "Will bake a single action's file. core templates are (index, add, edit, view)",
        ])->addArgument('action', [
            'help' => 'Will bake the template in <template> but create the filename named <action>.',
        ])->addOption('controller', [
            'help' => 'The controller name if you have a controller that does not follow conventions.',
        ])->addOption('prefix', [
            'help' => 'The routing prefix to generate views for.',
        ])->addOption('index-columns', [
            'help' => 'Limit for the number of index columns.',
            'default' => '0',
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

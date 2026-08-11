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

        $actions = [];
        if (!$args->getOption('no-actions') && !$args->getOption('actions')) {
            $actions = ['index', 'view', 'add', 'edit', 'delete'];
        }
        if ($args->getOption('actions')) {
            $actions = array_map('trim', explode(',', (string)$args->getOption('actions')));
            $actions = array_filter($actions);
        }

        $prefix = $this->getPrefix($args);
        if ($prefix) {
            $prefix = '\\' . str_replace('/', '\\', $prefix);
        }

        $namespace = Configure::read('App.namespace');
        if ($this->plugin) {
            $namespace = $this->_pluginNamespace($this->plugin);
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

        $data = compact(
            'actions',
            'currentModelName',
            'defaultModel',
            'modelObj',
            'namespace',
            'plugin',
            'pluralHumanName',
            'pluralName',
            'prefix',
            'singularHumanName',
            'singularName',
        );
        $data['name'] = $controllerName;
        $data['associations'] = $this->extractAssociations($modelObj);

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
     * Groups association aliases by relation type.
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $model The collection.
     * @return array<string, list<string>>
     */
    protected function extractAssociations(BaseCollection $model): array
    {
        $result = [
            'belongsTo' => [],
            'hasOne' => [],
            'hasMany' => [],
            'belongsToMany' => [],
        ];

        foreach ($model->associations() as $association) {
            $alias = $association->getName();
            $map = match ($association->type()) {
                'manyToOne' => 'belongsTo',
                'oneToOne' => 'hasOne',
                'oneToMany' => 'hasMany',
                'manyToMany' => 'belongsToMany',
                default => null,
            };
            if ($map !== null) {
                $result[$map][] = $alias;
            }
        }

        return $result;
    }

    /**
     * @inheritDoc
     */
    public static function defaultName(): string
    {
        return 'bake mongocontroller';
    }    /**
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

<?php
declare(strict_types=1);

/**
 * Ported and adapted from cakephp/bake TestCommand (MIT License).
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc.
 * @license https://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace Crustum\Mongo\Command\Bake;

use Bake\Command\BakeCommand;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Controller\ComponentRegistry;
use Cake\Controller\Controller;
use Cake\Core\Configure;
use Cake\Core\Exception\CakeException;
use Cake\Datasource\FactoryLocator;
use Cake\Http\ServerRequest as Request;
use Cake\Utility\Inflector;
use Cake\View\View;
use Crustum\Mongo\ODM\Association;
use Crustum\Mongo\ODM\BaseCollection;
use ReflectionClass;
use UnexpectedValueException;
use function Cake\Core\namespaceSplit;

/**
 * Command class for generating Mongo test files (Collection, Document,
 * Controller), mirroring `Bake\Command\TestCommand`.
 */
class MongoTestCommand extends BakeCommand
{
    /**
     * Class types that methods can be generated for.
     *
     * @var array<string>
     */
    public array $classTypes = [
        'Document' => 'Model\Document',
        'Collection' => 'Model\Collection',
        'Controller' => 'Controller',
        'Component' => 'Controller\Component',
        'Behavior' => 'Model\Behavior',
        'Helper' => 'View\Helper',
        'Cell' => 'View\Cell',
        'Form' => 'Form',
        'Mailer' => 'Mailer',
        'Command' => 'Command',
        'CommandHelper' => 'Command\Helper',
        'Middleware' => 'Middleware',
        'Class' => '',
    ];

    /**
     * Class types that methods can be generated for.
     *
     * @var array<string>
     */
    public array $classSuffixes = [
        'Document' => '',
        'Collection' => 'Collection',
        'Controller' => 'Controller',
        'Component' => 'Component',
        'Behavior' => 'Behavior',
        'Helper' => 'Helper',
        'Cell' => 'Cell',
        'Form' => 'Form',
        'Mailer' => 'Mailer',
        'Command' => 'Command',
        'CommandHelper' => 'Helper',
        'Middleware' => 'Middleware',
        'Class' => '',
    ];

    /**
     * Blacklisted methods for controller test cases.
     *
     * @var array<string>
     */
    protected array $blacklistedMethods = [
        'initialize',
    ];

    /**
     * Internal list of fixtures that have been added so far.
     *
     * @var array<string>
     */
    protected array $_fixtures = [];

    /**
     * @inheritDoc
     */
    public static function getDescription(): string
    {
        return 'Create a test case skeleton for a Mongo class.';
    }

    /**
     * Execute test generation.
     *
     * @param \Cake\Console\Arguments $args The command arguments.
     * @param \Cake\Console\ConsoleIo $io The console io.
     * @return int|null The exit code or null for success.
     */
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $this->extractCommonProperties($args);
        if (!$args->hasArgument('type') && !$args->hasArgument('name')) {
            $this->outputTypeChoices($io);

            return null;
        }

        $type = $this->normalize((string)$args->getArgument('type'));

        if ($args->getOption('all')) {
            $this->bakeAll($type, $args, $io);

            return null;
        }

        if (!$args->hasArgument('name')) {
            $this->outputClassChoices($type, $io);

            return null;
        }

        $name = (string)$args->getArgument('name');
        $name = $this->_getName($name);

        $result = $this->bake($type, $name, $args, $io);
        if ($result === static::CODE_ERROR) {
            return static::CODE_ERROR;
        }

        if ($result) {
            $io->success('Done');
        }

        return static::CODE_SUCCESS;
    }

    /**
     * Output a list of class types you can bake a test for.
     *
     * @param \Cake\Console\ConsoleIo $io The console io.
     * @return void
     */
    protected function outputTypeChoices(ConsoleIo $io): void
    {
        $io->out(
            'You must provide a class type to bake a test for. The valid types are:',
            2,
        );
        $i = 0;
        foreach (array_keys($this->classTypes) as $option) {
            $io->out(++$i . '. ' . $option);
        }

        $io->out('');
        $io->out('Re-run your command as `cake bake <type> <classname>`');
    }

    /**
     * Output a list of possible classnames for a type.
     *
     * @param string $typeName The typename.
     * @param \Cake\Console\ConsoleIo $io The console io.
     * @return void
     */
    protected function outputClassChoices(string $typeName, ConsoleIo $io): void
    {
        $type = $this->mapType($typeName);
        $io->out(
            'You must provide a class to bake a test for. Some possible options are:',
            2,
        );
        $options = $this->getClassOptions($type);
        $i = 0;
        foreach ($options as $option) {
            $io->out(++$i . '. ' . $option);
        }

        $io->out('');
        $io->out('Re-run your command as `cake bake ' . $typeName . ' <classname>`');
    }

    /**
     * Bake all tests for one class type.
     *
     * @param string $type The typename.
     * @param \Cake\Console\Arguments $args Arguments.
     * @param \Cake\Console\ConsoleIo $io ConsoleIo instance.
     * @return void
     */
    protected function bakeAll(string $type, Arguments $args, ConsoleIo $io): void
    {
        $mappedType = $this->mapType($type);
        $classes = $this->getClassOptions($mappedType);

        foreach ($classes as $class) {
            if ($this->bake($type, $class, $args, $io)) {
                $io->success('Done - ' . $class);
            } else {
                $io->error('Failed - ' . $class);
            }
        }

        $io->info('Bake finished');
    }

    /**
     * Get the possible classes for a given type.
     *
     * @param string $namespace The namespace fragment.
     * @return array<string>
     */
    protected function getClassOptions(string $namespace): array
    {
        $classes = [];
        $base = APP;
        if ($this->plugin) {
            $base = $this->_pluginPath($this->plugin) . 'src' . DS;
        }

        $path = $base . str_replace('\\', DS, $namespace);

        $files = glob($path . DS . '*.php');
        if ($files === false) {
            return [];
        }

        foreach ($files as $file) {
            $fileName = basename($file);
            if ($fileName === 'AppController.php') {
                continue;
            }

            $classes[] = substr($fileName, 0, -4) ?: '';
        }

        sort($classes);

        return $classes;
    }

    /**
     * Completes final steps for generating data to create test case.
     *
     * @param string $type Type of object to bake test case for.
     * @param string $className The 'cake name' for the class.
     * @param \Cake\Console\Arguments $args Arguments.
     * @param \Cake\Console\ConsoleIo $io ConsoleIo instance.
     * @return string|int|bool
     */
    public function bake(string $type, string $className, Arguments $args, ConsoleIo $io): string|bool|int
    {
        $type = $this->normalize($type);
        if (!isset($this->classSuffixes[$type]) || !isset($this->classTypes[$type])) {
            return false;
        }

        $prefix = $this->getPrefix($args);
        $fullClassName = $this->getRealClassName($type, $className, $prefix);

        if (!$args->getOption('no-fixture')) {
            if ($args->getOption('fixtures')) {
                $fixtures = array_map(trim(...), explode(',', (string)$args->getOption('fixtures')));
                $this->_fixtures = array_filter($fixtures);
            } elseif ($this->typeCanDetectFixtures($type) && class_exists($fullClassName)) {
                $io->out('Bake is detecting possible fixtures...');
                $testSubject = $this->buildTestSubject($type, $fullClassName);
                if ($testSubject instanceof BaseCollection || $testSubject instanceof Controller) {
                    $this->generateFixtureList($testSubject);
                }
            }
        }

        $methods = [];
        if (class_exists($fullClassName)) {
            $methods = $this->getTestableMethods($fullClassName);
        }

        $mock = $this->hasMockClass($type);
        [$preConstruct, $construction, $postConstruct] = $this->generateConstructor($type, $fullClassName);
        $uses = $this->generateUses($type, $fullClassName);

        if ($type === 'Class') {
            [$namespace, $className] = namespaceSplit($fullClassName);
            $subject = $className;
        } else {
            $subject = $className;
            [$namespace, $className] = namespaceSplit($fullClassName);
        }

        $baseNamespace = Configure::read('App.namespace');
        if ($this->plugin) {
            $baseNamespace = $this->_pluginNamespace($this->plugin);
        }

        $subNamespace = substr($namespace, strlen((string)$baseNamespace) + 1);

        $properties = $this->generateProperties($type, $subject, $fullClassName);

        $io->out("\n" . sprintf('Baking test case for %s ...', $fullClassName), 1, ConsoleIo::QUIET);

        $contents = $this->createTemplateRenderer()
            ->set('fixtures', $this->_fixtures)
            ->set('plugin', $this->plugin)
            ->set('hasFixtureFactories', false)
            ->set(['subject' => $subject, 'className' => $className, 'properties' => $properties, 'methods' => $methods, 'type' => $type, 'fullClassName' => $fullClassName, 'mock' => $mock, 'preConstruct' => $preConstruct, 'postConstruct' => $postConstruct, 'construction' => $construction, 'uses' => $uses, 'baseNamespace' => $baseNamespace, 'subNamespace' => $subNamespace, 'namespace' => $namespace])
            ->generate('Crustum/Mongo.tests/test_case');

        $filename = $this->testCaseFileName($type, $fullClassName);
        $emptyFile = dirname($filename) . DS . '.gitkeep';
        $this->deleteEmptyFile($emptyFile, $io);
        if ($io->createFile($filename, $contents, $this->force)) {
            return $contents;
        }

        return false;
    }

    /**
     * Checks whether the chosen type can find its own fixtures.
     *
     * @param string $type The type.
     * @return bool
     */
    public function typeCanDetectFixtures(string $type): bool
    {
        return in_array($type, ['Controller', 'Collection'], true);
    }

    /**
     * Construct an instance of the class to be tested.
     *
     * @param string $type The type.
     * @param string $class The classname.
     * @return object
     */
    public function buildTestSubject(string $type, string $class): object
    {
        if ($type === 'Collection') {
            [, $name] = namespaceSplit($class);
            $name = str_replace('Collection', '', $name);
            if ($this->plugin) {
                $name = $this->plugin . '.' . $name;
            }

            $locator = FactoryLocator::get('Collection');
            if ($locator->exists($name)) {
                $instance = $locator->get($name);
            } else {
                $instance = $locator->get($name, [
                    'connectionName' => $this->connection,
                ]);
            }
        } elseif ($type === 'Controller') {
            $instance = new $class(new Request());
        } else {
            $instance = new $class();
        }

        return $instance;
    }

    /**
     * Gets the real class name from the cake short form.
     *
     * @param string $type The type.
     * @param string $class The class name.
     * @param string|null $prefix The namespace prefix if any.
     * @return string Real class name.
     */
    public function getRealClassName(string $type, string $class, ?string $prefix = null): string
    {
        $namespace = Configure::read('App.namespace');
        if ($this->plugin) {
            $namespace = str_replace('/', '\\', $this->plugin);
        }

        if ($type === 'Class') {
            if (str_starts_with($class, $namespace . '\\')) {
                $class = substr($class, strlen((string)$namespace) + 1);
            }

            return $namespace . '\\' . $class;
        }

        $suffix = $this->classSuffixes[$type];
        $subSpace = $this->mapType($type);
        if ($suffix && !str_contains($class, $suffix)) {
            $class .= $suffix;
        }

        if (in_array($type, ['Controller', 'Cell'], true) && $prefix) {
            $subSpace .= '\\' . str_replace('/', '\\', $prefix);
        }

        return $namespace . '\\' . $subSpace . '\\' . $class;
    }

    /**
     * Map the types that this command uses to concrete types.
     *
     * @param string $type The type.
     * @return string
     * @throws \Cake\Core\Exception\CakeException
     */
    public function mapType(string $type): string
    {
        if (!isset($this->classTypes[$type])) {
            throw new CakeException('Invalid object type: ' . $type);
        }

        return $this->classTypes[$type];
    }

    /**
     * Get methods declared in the class given.
     *
     * @param class-string $className Name of class to look at.
     * @return array<string>
     * @throws \ReflectionException
     */
    public function getTestableMethods(string $className): array
    {
        $class = new ReflectionClass($className);
        $out = [];
        foreach ($class->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $className) {
                continue;
            }

            if (!$method->isPublic()) {
                continue;
            }

            if (in_array($method->getName(), $this->blacklistedMethods, true)) {
                continue;
            }

            $out[] = $method->getName();
        }

        return $out;
    }

    /**
     * Generate the list of fixtures for a model/controller.
     *
     * @param \Crustum\Mongo\ODM\BaseCollection|\Cake\Controller\Controller $subject The subject.
     * @return array<string>
     */
    public function generateFixtureList(BaseCollection|Controller $subject): array
    {
        $this->_fixtures = [];
        if ($subject instanceof BaseCollection) {
            $this->processModel($subject);
        } else {
            $this->processController($subject);
        }

        return array_values($this->_fixtures);
    }

    /**
     * Process a model, pull out model name + associations.
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $subject A collection to scan.
     * @return void
     */
    protected function processModel(BaseCollection $subject): void
    {
        $this->addFixture($subject->getAlias(), $subject->getCollection());
        foreach ($subject->associations()->keys() as $alias) {
            $assoc = $subject->getAssociation($alias);
            if (!$assoc instanceof Association) {
                continue;
            }

            $target = $assoc->getTarget();
            $name = $target->getAlias();
            if (!isset($this->_fixtures[$name])) {
                $this->addFixture($target->getAlias(), $target->getCollection());
            }
        }
    }

    /**
     * Process all the models attached to a controller.
     *
     * @param \Cake\Controller\Controller $subject A controller.
     * @return void
     */
    protected function processController(Controller $subject): void
    {
        $models = [];
        try {
            $repository = $subject->fetchTable();
            $models[] = $repository->getAlias();
        } catch (UnexpectedValueException) {
            return;
        }

        foreach ($models as $model) {
            [, $model] = $this->splitName($model);
            $locator = FactoryLocator::get('Collection');
            $collection = $locator->get($model);
            if ($collection instanceof BaseCollection) {
                $this->processModel($collection);
            }
        }
    }

    /**
     * Add class name to the fixture list.
     *
     * The fixture name is derived from the collection name (plural), matching
     * `bake mongofixture` output, and WITHOUT the `Fixture` suffix — Cake's
     * fixture loader appends it (`app.Articles` → `App\Test\Fixture\ArticlesFixture`).
     *
     * @param string $name Alias used as the dedup key.
     * @param string $collectionName The collection name.
     * @return void
     */
    protected function addFixture(string $name, string $collectionName): void
    {
        $prefix = $this->plugin ? 'plugin.' . $this->plugin . '.' : 'app.';
        $fixture = $prefix . Inflector::camelize(Inflector::underscore($collectionName));
        $this->_fixtures[$name] = $fixture;
    }

    /**
     * Splits a plugin-prefixed name.
     *
     * @param string $name The name.
     * @return array{string|null, string}
     */
    protected function splitName(string $name): array
    {
        if (str_contains($name, '.')) {
            [$plugin, $name] = explode('.', $name, 2);

            return [$plugin, $name];
        }

        return [null, $name];
    }

    /**
     * Is a mock class required for this type of test?
     *
     * @param string $type The type.
     * @return bool
     */
    public function hasMockClass(string $type): bool
    {
        return $type === 'Controller';
    }

    /**
     * Generate a constructor code snippet for the type and class name.
     *
     * @param string $type The type.
     * @param string $fullClassName The full classname.
     * @return array<string>
     */
    public function generateConstructor(string $type, string $fullClassName): array
    {
        [, $className] = namespaceSplit($fullClassName);
        $pre = '';
        $construct = '';
        $post = '';
        if ($type === 'Collection') {
            $collectionName = str_replace('Collection', '', $className);
            $pre = "\$config = FactoryLocator::get('Collection')->exists('{$collectionName}') " .
                "? [] : ['className' => {$className}::class];";
            $construct = "FactoryLocator::get('Collection')->get('{$collectionName}', \$config);";
        }

        if ($type === 'Behavior') {
            $pre = '$collection = new BaseCollection();';
            $construct = "new {$className}(\$collection);";
        }

        if ($type === 'Document' || $type === 'Form') {
            $construct = "new {$className}();";
        }

        if ($type === 'Helper') {
            $pre = '$view = new View();';
            $construct = "new {$className}(\$view);";
        }

        if ($type === 'Component') {
            $pre = '$registry = new ComponentRegistry();';
            $construct = "new {$className}(\$registry);";
        }

        if ($type === 'Class') {
            if (class_exists($fullClassName)) {
                $reflection = new ReflectionClass($fullClassName);
                $constructor = $reflection->getConstructor();
                if (!$constructor || $constructor->getNumberOfRequiredParameters() === 0) {
                    $construct = "new {$className}();";
                }
            } else {
                $construct = "new {$className}();";
            }
        }

        return [$pre, $construct, $post];
    }

    /**
     * Generate property info for the type and class name.
     *
     * @param string $type The type.
     * @param string $subject The name of the test subject.
     * @param string $fullClassName The full classname.
     * @return list<array{description: string, type: string, name: string}>
     */
    public function generateProperties(string $type, string $subject, string $fullClassName): array
    {
        $properties = [];

        $skipProperty = in_array($type, ['Controller', 'Command'], true);
        if (!$skipProperty) {
            $properties[] = [
                'description' => 'Test subject',
                'type' => '\\' . $fullClassName,
                'name' => $subject,
            ];
        }

        return $properties;
    }

    /**
     * Generate the uses() calls for a type & class name.
     *
     * @param string $type The type.
     * @param string $fullClassName The full classname.
     * @return array<string>
     */
    public function generateUses(string $type, string $fullClassName): array
    {
        $uses = [];
        if ($type === 'Component') {
            $uses[] = ComponentRegistry::class;
        }

        if ($type === 'Helper') {
            $uses[] = View::class;
        }

        if ($type === 'Behavior') {
            $uses[] = BaseCollection::class;
        }

        if ($type === 'Collection') {
            $uses[] = FactoryLocator::class;
        }

        $uses[] = $fullClassName;

        return $uses;
    }

    /**
     * Get the base path to the app tests.
     *
     * @return string
     */
    public function getBasePath(): string
    {
        $dir = 'TestCase' . DS;
        if ($this->plugin) {
            return $this->_pluginPath($this->plugin) . 'tests' . DS . $dir;
        }

        return defined('TESTS') ? TESTS . $dir : ROOT . DS . 'tests' . DS . $dir;
    }

    /**
     * Make the filename for the test case.
     *
     * @param string $type The type.
     * @param string $className The fully qualified classname.
     * @return string
     */
    public function testCaseFileName(string $type, string $className): string
    {
        $path = $this->getBasePath();
        $namespace = Configure::read('App.namespace');
        if ($this->plugin) {
            $namespace = $this->plugin;
        }

        $classTail = substr($className, strlen((string)$namespace) + 1);
        $path = $path . $classTail . 'Test.php';

        return str_replace(['/', '\\'], DS, $path);
    }

    /**
     * Build the option parser.
     *
     * @param \Cake\Console\ConsoleOptionParser $parser Option parser to update.
     * @return \Cake\Console\ConsoleOptionParser
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser = $this->_setCommonOptions($parser);

        $types = array_keys($this->classTypes);
        $types = array_merge($types, array_map($this->underscore(...), $types));

        $parser->setDescription(
            'Bake test case skeletons for Mongo classes.',
        )->addArgument('type', [
            'help' => 'Type of class to bake: controller, collection, document, helper, component or behavior.',
            'choices' => $types,
        ])->addArgument('name', [
            'help' => 'An existing class to bake tests for.',
        ])->addOption('fixtures', [
            'help' => 'A comma separated list of fixture names you want to include.',
        ])->addOption('no-fixture', [
            'boolean' => true,
            'default' => false,
            'help' => 'Select if you want to bake without fixture.',
        ])->addOption('prefix', [
            'default' => false,
            'help' => 'Use when baking tests for prefixed controllers.',
        ])->addOption('all', [
            'boolean' => true,
            'help' => 'Bake all classes of the given type.',
        ]);

        return $parser;
    }

    /**
     * Normalizes string into CamelCase format.
     *
     * @param string $string String to inflect.
     * @return string
     */
    protected function normalize(string $string): string
    {
        return Inflector::camelize(Inflector::underscore($string));
    }

    /**
     * Helper to allow under_score format for CLI env usage.
     *
     * @param string $string String to inflect.
     * @return string
     */
    protected function underscore(string $string): string
    {
        return Inflector::underscore($string);
    }

    /**
     * @inheritDoc
     */
    public static function defaultName(): string
    {
        return 'bake mongotest';
    }

    /**
     * @inheritDoc
     */
    public function name(): string
    {
        return 'mongotest';
    }
}

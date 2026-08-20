<?php
declare(strict_types=1);

/**
 * Ported and adapted from cakephp/migrations (MIT License).
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc.
 * @license https://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace Crustum\Mongo\Migration\Command;

use Bake\Command\SimpleBakeCommand;
use Bake\Utility\TemplateRenderer;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Core\Plugin;
use Cake\Event\Event;
use Cake\Event\EventManager;
use Cake\Utility\Inflector;
use Crustum\Mongo\Migration\Config\ConfigInterface;
use Crustum\Mongo\Migration\Migration\ManagerFactory;
use Crustum\Mongo\Migration\Util\Util;
use Crustum\Mongo\View\Helper\MongoMigrationHelper;
use Override;
use RuntimeException;

/**
 * Base command for baking Mongo migration files via Twig templates.
 *
 * Mirrors the reference `BakeSimpleMigrationCommand` for the Mongo migration
 * shape (a `change()` method).
 *
 * @ported-from \Migrations\Command\BakeSimpleMigrationCommand
 */
class BakeSimpleMigrationCommand extends SimpleBakeCommand
{
    /**
     * Default migration folder name.
     */
    public const DEFAULT_MIGRATION_FOLDER = ConfigInterface::DEFAULT_MIGRATION_FOLDER;

    /**
     * Reserved PHP keywords that cannot be used as bare class names.
     *
     * @var array<int, string>
     */
    protected const RESERVED_KEYWORDS = [
        'abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch', 'class', 'clone', 'const',
        'continue', 'declare', 'default', 'die', 'do', 'echo', 'else', 'elseif', 'empty', 'enddeclare', 'endfor',
        'endforeach', 'endif', 'endswitch', 'endwhile', 'eval', 'exit', 'extends', 'final', 'finally', 'for', 'foreach',
        'function', 'global', 'goto', 'if', 'implements', 'include', 'include_once', 'instanceof', 'insteadof', 'interface',
        'isset', 'list', 'namespace', 'new', 'or', 'parent', 'private', 'protected', 'public', 'return', 'static',
    ];

    /**
     * Console IO instance for the current bake run.
     */
    protected ConsoleIo $io;

    /**
     * Arguments for the current bake run.
     */
    protected Arguments $args;

    /**
     * @inheritDoc
     */
    #[Override]
    public static function getDescription(): string
    {
        return 'Bake a plain Mongo migration file.';
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public static function defaultName(): string
    {
        return 'bake mongo_migration_simple';
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function name(): string
    {
        return 'mongo_migration_simple';
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function fileName(string $name): string
    {
        $timestamp = Util::getCurrentTimestamp();
        $suffix = '_' . Inflector::camelize($name) . '.php';

        $path = $this->getPath($this->args);
        $offset = 0;
        while (glob($path . $timestamp . '_*.php')) {
            $timestamp = Util::getCurrentTimestamp(++$offset);
        }

        return $timestamp . $suffix;
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function template(): string
    {
        return 'Crustum/Mongo.Migration/skeleton';
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function getPath(Arguments $args): string
    {
        return $this->migrationPath($args) . DIRECTORY_SEPARATOR;
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        if (!Plugin::isLoaded('Bake')) {
            $io->err('Bake plugin is not loaded. Please load it first to generate a migration.');
            $this->abort();
        }

        $this->extractCommonProperties($args);
        $name = $args->getArgumentAt(0);
        if (!is_string($name) || $name === '') {
            $io->err('You must provide a name to bake a ' . $this->name());
            $this->abort();
        }

        $name = $this->_getName($name);
        $name = Inflector::camelize($name);
        $this->bake($name, $args, $io);

        return static::CODE_SUCCESS;
    }

    /**
     * @inheritDoc
     */
    #[Override]
    protected function bake(string $name, Arguments $args, ConsoleIo $io): void
    {
        $this->io = $io;
        $this->args = $args;

        if ($this->isReservedKeyword($name)) {
            $prefix = $io->ask(
                'Reserved keywords cannot be used for class names. What prefix would you like to use? Defaults to `Migration`.',
                'Migration',
            );
            $name = $prefix . ucfirst($name);
        }

        $migrationWithSameName = glob($this->getPath($args) . '*_' . $name . '.php') ?: [];
        if ($migrationWithSameName && !$args->getOption('force')) {
            $io->abort(sprintf(
                'A migration with the name `%s` already exists. Use --force to overwrite.',
                $name,
            ));
        }

        foreach ($migrationWithSameName as $migration) {
            if (file_exists($migration)) {
                unlink($migration);
            }
        }

        EventManager::instance()->on('Bake.initialize', function (Event $event): void {
            /** @var \Bake\View\BakeView $view */
            $view = $event->getSubject();
            $view->loadHelper('Crustum/Mongo.MongoMigration', [
                'className' => MongoMigrationHelper::class,
            ]);
        });

        $contents = $this->createMigrationTemplateRenderer()
            ->set('name', $name)
            ->set($this->templateData($args))
            ->generate($this->template());
        $contents = str_replace("\r\n", "\n", $contents);

        $filename = $this->getPath($args) . $this->fileName($name);
        $io->createFile($filename, $contents, (bool)$args->getOption('force'));

        $emptyFile = $this->getPath($args) . '.gitkeep';
        $this->deleteEmptyFile($emptyFile, $io);

        $io->success(sprintf('Baked `%s` to `%s`.', $name, $filename));
    }

    /**
     * Creates the template renderer with the Mongo migration helper loaded.
     *
     * @return \Bake\Utility\TemplateRenderer
     */
    public function createMigrationTemplateRenderer(): TemplateRenderer
    {
        $renderer = $this->createTemplateRenderer();
        $renderer->viewBuilder()->addHelpers([
            'Crustum/Mongo.MongoMigration' => ['className' => MongoMigrationHelper::class],
        ]);

        return $renderer;
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function templateData(Arguments $arguments): array
    {
        $data = parent::templateData($arguments);

        return $data + [
            'action' => null,
            'collections' => [],
            'columns' => [
                'fields' => [],
                'indexes' => [],
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    #[Override]
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser = $this->_setCommonOptions($parser);

        $parser->setDescription(
            'Bake a Mongo migration class.',
        )->addOption('source', [
            'short' => 's',
            'default' => self::DEFAULT_MIGRATION_FOLDER,
            'help' => 'The folder where your migrations are',
        ]);

        return $parser;
    }

    /**
     * Resolves the migrations folder path.
     *
     * @param \Cake\Console\Arguments $args The command arguments
     * @return string The migrations folder
     */
    protected function migrationPath(Arguments $args): string
    {
        $connection = (string)$args->getOption('connection');
        if ($connection === 'default') {
            $connection = 'mongo';
        }

        $factory = new ManagerFactory([
            'plugin' => $args->getOption('plugin'),
            'source' => $args->getOption('source'),
            'connection' => $connection,
        ]);
        $config = $factory->createConfig();
        $path = $config->getMigrationPath();

        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException(sprintf('Could not create migrations folder `%s`.', $path));
        }

        return $path;
    }

    /**
     * Whether the migration class name is a reserved PHP keyword.
     *
     * @param string $name Migration class name
     * @return bool
     */
    protected function isReservedKeyword(string $name): bool
    {
        return in_array(strtolower($name), static::RESERVED_KEYWORDS, true);
    }
}

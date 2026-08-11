<?php
declare(strict_types=1);

/**
 * Ported and adapted from cakephp/migrations (MIT License).
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc.
 * @license https://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace Crustum\Mongo\Migration\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Utility\Inflector;
use Crustum\Mongo\Migration\Config\ConfigInterface;
use Crustum\Mongo\Migration\ManagerFactory;
use Crustum\Mongo\Migration\Util;
use RuntimeException;

/**
 * Bakes an empty Mongo migration class into the migrations folder.
 *
 * Usage:
 * ```
 * bin/cake bake mongo_migration CreateArticles
 * bin/cake bake mongo_migration AddTagsIndex
 * ```
 */
class BakeMigrationCommand extends Command
{
    /**
     * @inheritDoc
     */
    public static function getDescription(): string
    {
        return 'Bake a Mongo migration class.';
    }

    /**
     * The default name added to the application command list.
     *
     * @return string
     */
    public static function defaultName(): string
    {
        return 'bake mongo_migration';
    }

    /**
     * Configure the option parser.
     *
     * @param \Cake\Console\ConsoleOptionParser $parser The option parser to configure
     * @return \Cake\Console\ConsoleOptionParser
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser->setDescription([
            'Bake a Mongo migration class',
            '',
            '<info>bin/cake bake mongo_migration CreateArticles</info>',
        ])->addArgument('name', [
            'help' => 'The migration class name in CamelCase',
            'required' => true,
        ])->addOption('plugin', [
            'short' => 'p',
            'help' => 'The plugin to run migrations for',
        ])->addOption('connection', [
            'short' => 'c',
            'help' => 'The datasource connection to use',
            'default' => 'mongo',
        ])->addOption('source', [
            'short' => 's',
            'default' => ConfigInterface::DEFAULT_MIGRATION_FOLDER,
            'help' => 'The folder where your migrations are',
        ])->addOption('force', [
            'short' => 'f',
            'boolean' => true,
            'help' => 'Force overwriting an existing migration with the same name',
        ]);

        return $parser;
    }

    /**
     * Execute the command.
     *
     * @param \Cake\Console\Arguments $args The command arguments
     * @param \Cake\Console\ConsoleIo $io The console io
     * @return int|null The exit code or null for success
     */
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $name = $args->getArgument('name');
        if (!is_string($name) || $name === '') {
            $io->err('You must provide a migration name in CamelCase.');
            $this->abort();
        }

        $className = Inflector::camelize($name);
        if (!preg_match('/^[A-Z]{1}[a-zA-Z0-9]+$/', $className)) {
            $io->err('The class name can only contain "A-Z" and "0-9" and has to start with a letter.');
            $this->abort();
        }

        $path = $this->migrationPath($args);
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException(sprintf('Could not create migrations folder `%s`.', $path));
        }

        $version = Util::getCurrentTimestamp();
        $file = $path . DIRECTORY_SEPARATOR . $version . '_' . Inflector::underscore($className) . '.php';

        $existing = glob($path . DIRECTORY_SEPARATOR . '*_' . Inflector::underscore($className) . '.php');
        $existing = is_array($existing) ? $existing : [];
        if ($existing && !$args->getOption('force')) {
            $io->abort(sprintf(
                'A migration with the name `%s` already exists. Use --force to overwrite.',
                $className,
            ));
        }
        foreach ($existing as $oldFile) {
            if (file_exists($oldFile)) {
                unlink($oldFile);
            }
        }

        $content = $this->buildFile($className);

        if (file_put_contents($file, $content) === false) {
            throw new RuntimeException(sprintf('Could not write migration file `%s`.', $file));
        }

        $io->success(sprintf('Baked `%s` to `%s`.', $className, $file));

        return self::CODE_SUCCESS;
    }

    /**
     * Builds the migration file content.
     *
     * @param string $className Migration class name
     * @return string PHP file content
     */
    protected function buildFile(string $className): string
    {
        return <<<PHP
<?php
declare(strict_types=1);

namespace App\Migration;

use Crustum\Mongo\Migration\BaseMigration;

class {$className} extends BaseMigration
{
    public function up(): void
    {
    }

    public function down(): void
    {
    }
}

PHP;
    }

    /**
     * Resolves the migrations folder path.
     *
     * @param \Cake\Console\Arguments $args The command arguments
     * @return string The migrations folder
     */
    protected function migrationPath(Arguments $args): string
    {
        $factory = new ManagerFactory([
            'plugin' => $args->getOption('plugin'),
            'source' => $args->getOption('source'),
            'connection' => (string)$args->getOption('connection'),
        ]);
        $config = $factory->createConfig();

        return $config->getMigrationPath();
    }
}

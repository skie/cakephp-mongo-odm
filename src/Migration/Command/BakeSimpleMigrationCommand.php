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
use Crustum\Mongo\Migration\Migration\ManagerFactory;
use Crustum\Mongo\Migration\Util\Util;
use RuntimeException;

/**
 * Bakes a plain, empty migration file.
 *
 * Mirrors the reference `BakeSimpleMigrationCommand` for the Mongo migration
 * shape (a `change()` with no operations).
 */
class BakeSimpleMigrationCommand extends Command
{
    /**
     * @inheritDoc
     */
    public static function getDescription(): string
    {
        return 'Bake a plain Mongo migration file.';
    }

    /**
     * The default name added to the application command list.
     *
     * @return string
     */
    public static function defaultName(): string
    {
        return 'bake mongo_migration_simple';
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
            'Bake a plain Mongo migration file',
            '',
            '<info>bin/cake bake mongo_migration_simple CreateReports</info>',
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

        $factory = new ManagerFactory([
            'plugin' => $args->getOption('plugin'),
            'source' => $args->getOption('source'),
            'connection' => $args->getOption('connection'),
        ]);
        $config = $factory->createConfig();
        $path = $config->getMigrationPath();

        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException(sprintf('Could not create migrations folder `%s`.', $path));
        }

        $className = Inflector::camelize($name);
        $version = Util::getCurrentTimestamp();
        $file = $path . DIRECTORY_SEPARATOR . $version . '_' . Inflector::underscore($className) . '.php';

        $content = $this->buildMigration($className);

        if (file_put_contents($file, $content) === false) {
            throw new RuntimeException(sprintf('Could not write migration file `%s`.', $file));
        }

        $io->success(sprintf('Baked migration `%s` to `%s`.', $className, $file));

        return self::CODE_SUCCESS;
    }

    /**
     * Builds the plain migration content.
     *
     * @param string $className Migration class name
     * @return string PHP file content
     */
    protected function buildMigration(string $className): string
    {
        return <<<PHP
<?php
declare(strict_types=1);

use Crustum\Mongo\Migration\BaseMigration;

class {$className} extends BaseMigration
{
    public function change(): void
    {
        // Write your migration here.
    }
}

PHP;
    }
}

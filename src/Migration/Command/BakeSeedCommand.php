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
use RuntimeException;

/**
 * Bakes an empty Mongo seeder class into the seeds folder.
 *
 * Usage:
 * ```
 * bin/cake bake mongo_seed Users
 * ```
 */
class BakeSeedCommand extends Command
{
    /**
     * @inheritDoc
     */
    public static function getDescription(): string
    {
        return 'Bake a Mongo seeder class.';
    }

    /**
     * The default name added to the application command list.
     *
     * @return string
     */
    public static function defaultName(): string
    {
        return 'bake mongo_seed';
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
            'Bake a Mongo seeder class',
            '',
            '<info>bin/cake bake mongo_seed Users</info>',
        ])->addArgument('name', [
            'help' => 'The seed class name in CamelCase',
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
            'default' => ConfigInterface::DEFAULT_SEED_FOLDER,
            'help' => 'The folder where your seeds are',
        ])->addOption('force', [
            'short' => 'f',
            'boolean' => true,
            'help' => 'Force overwriting an existing seed with the same name',
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
            $io->err('You must provide a seed name in CamelCase.');
            $this->abort();
        }

        $className = Inflector::camelize($name) . 'Seed';
        if (!preg_match('/^[A-Z]{1}[a-zA-Z0-9]+Seed$/', $className)) {
            $io->err('The class name can only contain "A-Z" and "0-9" and has to start with a letter.');
            $this->abort();
        }

        $path = $this->seedPath($args);
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException(sprintf('Could not create seeds folder `%s`.', $path));
        }

        $file = $path . DIRECTORY_SEPARATOR . $className . '.php';

        if (file_exists($file) && !$args->getOption('force')) {
            $io->abort(sprintf(
                'A seed with the name `%s` already exists. Use --force to overwrite.',
                $className,
            ));
        }

        $content = $this->buildFile($className);

        if (file_put_contents($file, $content) === false) {
            throw new RuntimeException(sprintf('Could not write seed file `%s`.', $file));
        }

        $io->success(sprintf('Baked `%s` to `%s`.', $className, $file));

        return self::CODE_SUCCESS;
    }

    /**
     * Builds the seed file content.
     *
     * @param string $className Seed class name
     * @return string PHP file content
     */
    protected function buildFile(string $className): string
    {
        $collection = Inflector::underscore(substr($className, 0, -4));

        return <<<PHP
<?php
declare(strict_types=1);

use Crustum\Mongo\Migration\BaseSeed;

class {$className} extends BaseSeed
{
    public function run(): void
    {
        // \$this->insert('{$collection}', [
        //     'name' => 'Example',
        // ]);
    }
}

PHP;
    }

    /**
     * Resolves the seeds folder path.
     *
     * @param \Cake\Console\Arguments $args The command arguments
     * @return string The seeds folder
     */
    protected function seedPath(Arguments $args): string
    {
        $factory = new ManagerFactory([
            'plugin' => $args->getOption('plugin'),
            'source' => $args->getOption('source'),
            'connection' => (string)$args->getOption('connection'),
        ]);
        $config = $factory->createConfig();

        return $config->getSeedPath();
    }
}

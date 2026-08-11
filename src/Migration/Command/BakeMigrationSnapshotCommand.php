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
use Cake\Datasource\ConnectionManager;
use Cake\Utility\Inflector;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Migration\Config\ConfigInterface;
use Crustum\Mongo\Migration\ManagerFactory;
use Crustum\Mongo\Migration\SchemaDumper;
use Crustum\Mongo\Migration\Util;
use Crustum\Mongo\Migration\Util\PhpArrayPrinter;
use RuntimeException;

/**
 * Bakes a migration snapshot that recreates the current Mongo schema.
 *
 * The generated migration captures every collection (validator + indexes) so
 * it can be applied to an empty database to reproduce the current state.
 */
class BakeMigrationSnapshotCommand extends Command
{
    /**
     * @inheritDoc
     */
    public static function getDescription(): string
    {
        return 'Bake a Mongo migration snapshot of the current schema.';
    }

    /**
     * The default name added to the application command list.
     *
     * @return string
     */
    public static function defaultName(): string
    {
        return 'bake mongo_migration_snapshot';
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
            'Bake a Mongo migration snapshot of the current schema',
            '',
            'The generated migration recreates every collection with its validator and indexes',
            '',
            '<info>bin/cake bake mongo_migration_snapshot InitialSchema</info>',
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

        $connectionName = (string)$args->getOption('connection');
        $connection = ConnectionManager::get($connectionName);
        if (!$connection instanceof Connection) {
            throw new RuntimeException(sprintf(
                'Connection `%s` is not a %s instance.',
                $connectionName,
                Connection::class,
            ));
        }

        $dumper = new SchemaDumper($connection);
        $schema = $dumper->dumpAll();

        $factory = new ManagerFactory([
            'plugin' => $args->getOption('plugin'),
            'source' => $args->getOption('source'),
            'connection' => $connectionName,
        ]);
        $config = $factory->createConfig();
        $path = $config->getMigrationPath();

        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException(sprintf('Could not create migrations folder `%s`.', $path));
        }

        $className = Inflector::camelize($name);
        $version = Util::getCurrentTimestamp();
        $file = $path . DIRECTORY_SEPARATOR . $version . '_' . Inflector::underscore($className) . '.php';

        $content = $this->buildSnapshot($className, $schema);

        if (file_put_contents($file, $content) === false) {
            throw new RuntimeException(sprintf('Could not write migration file `%s`.', $file));
        }

        $io->success(sprintf('Baked snapshot `%s` (%d collections) to `%s`.', $className, count($schema), $file));

        return self::CODE_SUCCESS;
    }

    /**
     * Builds the snapshot migration content.
     *
     * @param string $className Migration class name
     * @param array<string, array<string, mixed>> $schema The dumped schema
     * @return string PHP file content
     */
    protected function buildSnapshot(string $className, array $schema): string
    {
        $printer = new PhpArrayPrinter();
        $lines = [];
        foreach ($schema as $name => $definition) {
            $collection = var_export($name, true);

            $options = $definition['options'] ?? [];
            if (isset($definition['validator'])) {
                $options['validator'] = $definition['validator'];
            }
            $lines[] = sprintf(
                '        $this->createCollection(%s, %s);',
                $collection,
                $printer->print($options, 1),
            );

            foreach ($definition['indexes'] ?? [] as $indexName => $indexDef) {
                $key = $indexDef['key'] ?? [];
                $options = $indexDef['options'] ?? [];
                $options['name'] ??= $indexName;
                $lines[] = sprintf(
                    '        $this->index(%s, %s, %s);',
                    $collection,
                    $printer->print($key, 1),
                    $printer->print($options, 1),
                );
            }
        }

        $body = $lines !== [] ? implode("\n", $lines) : '        // No collections to create.';

        return <<<PHP
<?php
declare(strict_types=1);

namespace App\Migration;

use Crustum\Mongo\Migration\BaseMigration;

class {$className} extends BaseMigration
{
    public function up(): void
    {
{$body}
    }

    public function down(): void
    {
        // Collections are not dropped by default; add explicit dropCollection()
        // calls if this snapshot must be reversible.
    }
}

PHP;
    }
}

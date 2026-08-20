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
use Crustum\Mongo\Migration\Migration\ManagerFactory;
use Crustum\Mongo\Migration\Migration\SchemaDumper;
use Crustum\Mongo\Migration\Util\PhpArrayPrinter;
use Crustum\Mongo\Migration\Util\Util;
use Override;
use RuntimeException;

/**
 * Bakes a migration snapshot that recreates the current Mongo schema.
 *
 * The generated migration captures every collection (validator + indexes) so
 * it can be applied to an empty database to reproduce the current state.
 *
 * @inspired-by \Migrations\Command\BakeMigrationSnapshotCommand
 */
class BakeMigrationSnapshotCommand extends Command
{
    use SnapshotTrait;

    /**
     * @inheritDoc
     */
    #[Override]
    public static function getDescription(): string
    {
        return 'Bake a Mongo migration snapshot of the current schema.';
    }

    /**
     * The default name added to the application command list.
     *
     * @return string
     */
    #[Override]
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
    #[Override]
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
        ])->addOption('generate-only', [
            'help' => 'Only generate the migration file; do not mark it as migrated',
            'boolean' => true,
        ])->addOption('no-lock', [
            'help' => 'Do not refresh the schema dump after baking',
            'boolean' => true,
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
        $file = $path . DIRECTORY_SEPARATOR . $version . '_' . Inflector::camelize($className) . '.php';

        $content = $this->buildSnapshot($className, $schema);

        if (file_put_contents($file, $content) === false) {
            throw new RuntimeException(sprintf('Could not write migration file `%s`.', $file));
        }

        $io->success(sprintf('Baked snapshot `%s` (%d collections) to `%s`.', $className, count($schema), $file));

        if (!$args->getOption('generate-only')) {
            $this->markSnapshotApplied($file, $args, $io);

            if (!$args->getOption('no-lock')) {
                $this->refreshDump($args, $io);
            }
        }

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
            $lines[] = sprintf("        \$this->collection('%s')", $name);

            $validator = $definition['validator'] ?? null;
            if ($validator !== null) {
                $lines[] = '            ->setValidator(' . $printer->print($validator, 2) . ')';
            }

            foreach ($definition['indexes'] ?? [] as $indexName => $indexDef) {
                $key = $indexDef['key'] ?? [];
                $options = $indexDef['options'] ?? [];
                $options['name'] ??= $indexName;
                $lines[] = sprintf(
                    '            ->addIndex(%s, %s)',
                    $printer->print($key, 2),
                    $printer->print($options, 2),
                );
            }

            $lines[] = '            ->create();';
        }

        $body = $lines !== [] ? implode("\n", $lines) : '        // No collections to create.';

        return <<<PHP
<?php
declare(strict_types=1);

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

<?php
declare(strict_types=1);

namespace Crustum\Mongo\Migration\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Datasource\ConnectionManager;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Migration\Config\ConfigInterface;
use Crustum\Mongo\Migration\Migration\ManagerFactory;
use Crustum\Mongo\Migration\Migration\SchemaDiff;
use Crustum\Mongo\Migration\Migration\SchemaDumper;
use Crustum\Mongo\Migration\Util\PhpArrayPrinter;
use Crustum\Mongo\Migration\Util\Util;
use Override;
use RuntimeException;

/**
 * Diff command compares the desired schema (schema-dump-mongo.lock) against
 * the live database and bakes a migration for the deltas.
 */
class DiffCommand extends Command
{
    use SnapshotTrait;

    /**
     * @inheritDoc
     */
    #[Override]
    public static function getDescription(): string
    {
        return 'Generate a migration from the schema diff (lock file vs live).';
    }

    /**
     * The default name added to the application command list.
     *
     * @return string
     */
    #[Override]
    public static function defaultName(): string
    {
        return 'mongo migrations diff';
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
            'Compare the schema dump lock file against the live Mongo schema and ' .
            'bake a migration for the deltas',
            '',
            '<info>mongo migrations diff</info>',
            '<info>mongo migrations diff --connection mongo</info>',
        ])->addArgument('name', [
            'help' => 'The migration name (default: SchemaDiff)',
            'required' => false,
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
        ])->addOption('schema-file', [
            'help' => 'The desired schema lock file (default: <migrations folder>/schema-dump-mongo.lock)',
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
        $connectionName = (string)$args->getOption('connection');
        $connection = ConnectionManager::get($connectionName);
        if (!$connection instanceof Connection) {
            throw new RuntimeException(sprintf(
                'Connection `%s` is not a %s instance.',
                $connectionName,
                Connection::class,
            ));
        }

        $file = $this->dumpPath($args);
        if (!file_exists($file)) {
            throw new RuntimeException(sprintf('Schema dump `%s` does not exist. Run `mongo schema dump` first.', $file));
        }

        $contents = file_get_contents($file);
        $desired = $contents !== false ? unserialize($contents) : false;
        if (!is_array($desired)) {
            throw new RuntimeException(sprintf('Schema dump `%s` is not a valid serialized schema.', $file));
        }

        $dumper = new SchemaDumper($connection);
        $actual = $dumper->dumpAll();

        $diff = new SchemaDiff();
        $operations = $diff->diff($desired, $actual);

        if ($operations === []) {
            $io->success('No schema differences found.');

            return self::CODE_SUCCESS;
        }

        $name = $args->getArgument('name');
        $className = is_string($name) && $name !== '' ? ucfirst($name) : 'SchemaDiff';

        $fileContent = $this->buildMigrationFile($className, $operations);
        $this->writeMigration($args, $io, $className, $fileContent, count($operations));

        return self::CODE_SUCCESS;
    }

    /**
     * Builds the migration file body from diff operations.
     *
     * @param string $className Migration class name
     * @param list<array<string, mixed>> $operations Diff operations
     * @return string PHP file content
     */
    protected function buildMigrationFile(string $className, array $operations): string
    {
        $printer = new PhpArrayPrinter();
        $lines = [];
        foreach ($operations as $op) {
            $collection = var_export($op['collection'], true);
            switch ($op['type']) {
                case 'createCollection':
                    $lines[] = sprintf('        $this->createCollection(%s, %s);', $collection, $printer->print($op['options'] ?? [], 1));
                    break;
                case 'dropCollection':
                    $lines[] = sprintf('        $this->dropCollection(%s);', $collection);
                    break;
                case 'createIndex':
                    $lines[] = sprintf('        $this->index(%s, %s, %s);', $collection, $printer->print($op['key'] ?? [], 1), $printer->print($op['options'] ?? [], 1));
                    break;
                case 'dropIndex':
                    $lines[] = sprintf('        $this->dropIndex(%s, %s);', $collection, var_export($op['name'] ?? '', true));
                    break;
                case 'setValidator':
                    $lines[] = sprintf('        $this->setValidator(%s, %s);', $collection, $printer->print($op['validator'] ?? null, 1));
                    break;
            }
        }

        $body = implode("\n", $lines);

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
    }
}

PHP;
    }

    /**
     * Resolves the schema dump lock file path.
     *
     * @param \Cake\Console\Arguments $args The command arguments
     * @return string The dump file path
     */
    protected function dumpPath(Arguments $args): string
    {
        $explicit = $args->getOption('schema-file');
        if (is_string($explicit)) {
            return $explicit;
        }

        $factory = new ManagerFactory([
            'plugin' => $args->getOption('plugin'),
            'source' => $args->getOption('source'),
            'connection' => (string)$args->getOption('connection'),
        ]);
        $config = $factory->createConfig();

        return $config->getMigrationPath() . DIRECTORY_SEPARATOR . DumpCommand::DUMP_FILE;
    }

    /**
     * Writes the migration file to the migrations folder.
     *
     * @param \Cake\Console\Arguments $args The command arguments
     * @param \Cake\Console\ConsoleIo $io The console io
     * @param string $className Migration class name
     * @param string $content File content
     * @param int $operationCount Number of operations
     * @return void
     */
    protected function writeMigration(Arguments $args, ConsoleIo $io, string $className, string $content, int $operationCount): void
    {
        $factory = new ManagerFactory([
            'plugin' => $args->getOption('plugin'),
            'source' => $args->getOption('source') ?: ConfigInterface::DEFAULT_MIGRATION_FOLDER,
            'connection' => (string)$args->getOption('connection'),
        ]);
        $config = $factory->createConfig();
        $path = $config->getMigrationPath();

        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException(sprintf('Could not create migrations folder `%s`.', $path));
        }

        foreach (glob($path . DIRECTORY_SEPARATOR . '*_' . $className . '.php') ?: [] as $existing) {
            if (is_file($existing)) {
                unlink($existing);
            }
        }

        $version = Util::getCurrentTimestamp();
        $file = $path . DIRECTORY_SEPARATOR . $version . '_' . $className . '.php';

        if (file_put_contents($file, $content) === false) {
            throw new RuntimeException(sprintf('Could not write migration file `%s`.', $file));
        }

        $io->success(sprintf('Baked `%s` (%d operations) to `%s`.', $className, $operationCount, $file));

        if (!$args->getOption('generate-only')) {
            $this->markSnapshotApplied($file, $args, $io);

            if (!$args->getOption('no-lock')) {
                $this->refreshDump($args, $io);
            }
        }
    }
}

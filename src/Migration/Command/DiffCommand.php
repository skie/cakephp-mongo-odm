<?php
declare(strict_types=1);

namespace Crustum\Mongo\Migration\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Datasource\ConnectionManager;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Migration\ManagerFactory;
use Crustum\Mongo\Migration\SchemaDiff;
use Crustum\Mongo\Migration\SchemaDumper;
use Crustum\Mongo\Migration\Util;
use RuntimeException;

/**
 * Diff command compares the desired schema (config/schema_mongo.php) against
 * the live database and bakes a migration for the deltas.
 */
class DiffCommand extends Command
{
    /**
     * @inheritDoc
     */
    public static function getDescription(): string
    {
        return 'Generate a migration from the schema diff (file vs live).';
    }

    /**
     * The default name added to the application command list.
     *
     * @return string
     */
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
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser->setDescription([
            'Compare config/schema_mongo.php against the live Mongo schema and ' .
            'bake a migration for the deltas',
            '',
            '<info>migrations diff</info>',
            '<info>migrations diff --connection mongo</info>',
        ])->addArgument('name', [
            'help' => 'The migration name (default: SchemaDiff)',
            'required' => false,
        ])->addOption('connection', [
            'short' => 'c',
            'help' => 'The datasource connection to use',
            'default' => 'mongo',
        ])->addOption('source', [
            'short' => 's',
            'help' => 'The folder where your migrations are',
        ])->addOption('schema-file', [
            'help' => 'The desired schema file (default: config/schema_mongo.php)',
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

        $schemaFile = $args->getOption('schema-file');
        $file = is_string($schemaFile) ? $schemaFile : CONFIG . 'schema_mongo.php';
        if (!file_exists($file)) {
            throw new RuntimeException(sprintf('Schema file `%s` does not exist. Run `schema dump` first.', $file));
        }
        $desired = include $file;
        if (!is_array($desired)) {
            throw new RuntimeException(sprintf('Schema file `%s` must return an array.', $file));
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
        $lines = [];
        foreach ($operations as $op) {
            $collection = var_export($op['collection'], true);
            switch ($op['type']) {
                case 'createCollection':
                    $lines[] = sprintf('        $this->createCollection(%s, %s);', $collection, var_export($op['options'] ?? [], true));
                    break;
                case 'dropCollection':
                    $lines[] = sprintf('        $this->dropCollection(%s);', $collection);
                    break;
                case 'createIndex':
                    $lines[] = sprintf('        $this->index(%s, %s, %s);', $collection, var_export($op['key'] ?? [], true), var_export($op['options'] ?? [], true));
                    break;
                case 'dropIndex':
                    $lines[] = sprintf('        $this->dropIndex(%s, %s);', $collection, var_export($op['name'] ?? '', true));
                    break;
                case 'setValidator':
                    $lines[] = sprintf('        $this->setValidator(%s, %s);', $collection, var_export($op['validator'] ?? null, true));
                    break;
            }
        }

        $body = implode("\n", $lines);

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
    }
}

PHP;
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
            'source' => $args->getOption('source') ?: 'MongoMigrations',
            'connection' => (string)$args->getOption('connection'),
        ]);
        $config = $factory->createConfig();
        $path = $config->getMigrationPath();

        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException(sprintf('Could not create migrations folder `%s`.', $path));
        }

        $version = Util::getCurrentTimestamp();
        $snake = strtolower((string)preg_replace('/(?<!^)[A-Z]/', '_$0', $className));
        $file = $path . DIRECTORY_SEPARATOR . $version . '_' . $snake . '.php';

        if (file_put_contents($file, $content) === false) {
            throw new RuntimeException(sprintf('Could not write migration file `%s`.', $file));
        }

        $io->success(sprintf('Baked `%s` (%d operations) to `%s`.', $className, $operationCount, $file));
    }
}

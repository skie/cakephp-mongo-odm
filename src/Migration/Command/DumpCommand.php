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
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Migration\Config\ConfigInterface;
use Crustum\Mongo\Migration\ManagerFactory;
use Crustum\Mongo\Migration\SchemaDumper;
use RuntimeException;

/**
 * Dumps the live Mongo schema to a `schema-dump-mongo.lock` file inside the
 * migrations folder.
 *
 * The dumped file is the "desired" schema used by `mongo migrations diff` and
 * as the file-backed prod schema (avoids per-request introspection).
 */
class DumpCommand extends Command
{
    /**
     * The lock file suffix, matching cakephp/migrations conventions.
     */
    public const DUMP_FILE = 'schema-dump-mongo.lock';

    /**
     * @inheritDoc
     */
    public static function getDescription(): string
    {
        return 'Dump the current Mongo schema to schema-dump-mongo.lock.';
    }

    /**
     * The default name added to the application command list.
     *
     * @return string
     */
    public static function defaultName(): string
    {
        return 'mongo schema dump';
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
            'Dump the current Mongo schema to schema-dump-mongo.lock in the migrations folder',
            '',
            '<info>mongo schema dump</info>',
            '<info>mongo schema dump --connection mongo</info>',
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
        ])->addOption('path', [
            'help' => 'The output file path (default: <migrations folder>/schema-dump-mongo.lock)',
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

        $dumper = new SchemaDumper($connection);
        $schema = $dumper->dumpAll();

        $file = $this->dumpPath($args);

        if (file_put_contents($file, serialize($schema)) === false) {
            throw new RuntimeException(sprintf('Could not write schema dump to `%s`.', $file));
        }

        $io->success(sprintf('Dumped schema for %d collections to `%s`.', count($schema), $file));

        return self::CODE_SUCCESS;
    }

    /**
     * Resolves the dump file path.
     *
     * @param \Cake\Console\Arguments $args The command arguments
     * @return string The dump file path
     */
    protected function dumpPath(Arguments $args): string
    {
        $explicit = $args->getOption('path');
        if (is_string($explicit)) {
            return $explicit;
        }

        $factory = new ManagerFactory([
            'plugin' => $args->getOption('plugin'),
            'source' => $args->getOption('source'),
            'connection' => (string)$args->getOption('connection'),
        ]);
        $config = $factory->createConfig();

        return $config->getMigrationPath() . DIRECTORY_SEPARATOR . self::DUMP_FILE;
    }
}

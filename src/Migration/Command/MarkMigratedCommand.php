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
use Crustum\Mongo\Migration\Config\ConfigInterface;
use Crustum\Mongo\Migration\Migration\ManagerFactory;

/**
 * MarkMigrated command marks migrations as migrated without running them.
 *
 * @ported-from \Migrations\Command\MarkMigratedCommand
 */
class MarkMigratedCommand extends Command
{
    /**
     * @inheritDoc
     */
    public static function getDescription(): string
    {
        return 'Marks migrations as migrated without running them.';
    }

    /**
     * The default name added to the application command list.
     *
     * @return string
     */
    public static function defaultName(): string
    {
        return 'mongo migrations mark_migrated';
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
            'Marks migrations as migrated without actually running them',
            '',
            'You can mark all, a specific version, or everything up to a target',
            '',
            '<info>migrations mark_migrated</info>',
            '<info>migrations mark_migrated --target 20260811000000</info>',
            '<info>migrations mark_migrated 20260811000000</info>',
        ])->addArgument('version', [
            'help' => 'The migration version to mark, or `all`',
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
        ])->addOption('target', [
            'short' => 't',
            'help' => 'The target version to mark as migrated up to (inclusive)',
        ])->addOption('exclude', [
            'help' => 'Exclude the target version from the marked range',
            'boolean' => true,
        ])->addOption('only', [
            'help' => 'Only mark the target version, not the whole range',
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
        $factory = new ManagerFactory([
            'plugin' => $args->getOption('plugin'),
            'source' => $args->getOption('source'),
            'connection' => $args->getOption('connection'),
        ]);

        $manager = $factory->createManager($io);
        $config = $manager->getConfig();
        $path = $config->getMigrationPath();

        $versions = $manager->getVersionsToMark($args);
        $output = $manager->markVersionsAsMigrated($path, $versions);
        foreach ($output as $line) {
            $io->out($line);
        }

        return self::CODE_SUCCESS;
    }
}

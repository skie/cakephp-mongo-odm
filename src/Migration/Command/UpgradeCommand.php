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
use Crustum\Mongo\Migration\Db\Adapter\CakeMongoAdapter;
use Crustum\Mongo\Migration\Migration\ManagerFactory;

/**
 * Upgrades the migration journal to the unified-ledger shape.
 *
 * @rewritten-from \Migrations\Command\UpgradeCommand
 */
class UpgradeCommand extends Command
{
    /**
     * @inheritDoc
     */
    public static function getDescription(): string
    {
        return 'Upgrade the migration journal to the unified-ledger shape.';
    }

    /**
     * The default name added to the application command list.
     *
     * @return string
     */
    public static function defaultName(): string
    {
        return 'mongo migrations upgrade';
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
            'Upgrades the Mongo migration journal to the unified-ledger shape',
            '',
            'Backfills the plugin field (null) on journal entries that predate',
            'plugin attribution and ensures the (version, plugin) unique index.',
            '',
            '<info>mongo migrations upgrade</info>',
            '<info>mongo migrations upgrade --dry-run</info>',
        ])->addOption('connection', [
            'short' => 'c',
            'help' => 'The datasource connection to use',
            'default' => 'mongo',
        ])->addOption('plugin', [
            'short' => 'p',
            'help' => 'The plugin to run migrations for',
        ])->addOption('source', [
            'short' => 's',
            'help' => 'The folder under config that migrations are in',
        ])->addOption('dry-run', [
            'boolean' => true,
            'help' => 'Preview what would be upgraded without making changes',
            'default' => false,
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
        $dryRun = (bool)$args->getOption('dry-run');

        $factory = new ManagerFactory([
            'plugin' => $args->getOption('plugin'),
            'source' => $args->getOption('source'),
            'connection' => $args->getOption('connection'),
        ]);
        $manager = $factory->createManager($io);
        $connection = $manager->getEnvironment()->getAdapter()->getConnection();

        $journal = $connection->getCollection(CakeMongoAdapter::MIGRATION_COLLECTION);

        $missingPluginFilter = ['plugin' => ['$exists' => false]];
        $stale = $journal->countDocuments($missingPluginFilter);

        if ($dryRun) {
            $io->out('<warning>DRY RUN - No changes will be made</warning>');
            $io->out('');
            $io->out(sprintf('Entries missing the plugin field: <info>%d</info>', $stale));
            $io->out('Would backfill plugin=null and ensure the (version, plugin) unique index.');

            return self::CODE_SUCCESS;
        }

        if ($stale > 0) {
            $journal->updateMany($missingPluginFilter, ['$set' => ['plugin' => null]]);
        }

        $journal->createIndex(['version' => 1, 'plugin' => 1], ['unique' => true]);

        $io->success(sprintf('Journal upgraded: %d entrie(s) backfilled, (version, plugin) unique index ensured.', $stale));

        return self::CODE_SUCCESS;
    }
}

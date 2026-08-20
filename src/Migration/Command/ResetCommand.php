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
use Override;

/**
 * Reset command rolls back all migrations and migrates again.
 *
 * @inspired-by \Migrations\Command\ResetCommand
 */
class ResetCommand extends Command
{
    /**
     * @inheritDoc
     */
    #[Override]
    public static function getDescription(): string
    {
        return 'Rollback all migrations and migrate again.';
    }

    /**
     * The default name added to the application command list.
     *
     * @return string
     */
    #[Override]
    public static function defaultName(): string
    {
        return 'mongo migrations reset';
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
            'Rollback all migrations and migrate again',
            '',
            'This is a destructive operation that resets the database to the initial migration state',
            '',
            '<info>migrations reset</info>',
            '<info>migrations reset --connection secondary</info>',
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
        ])->addOption('fake', [
            'help' => 'Perform fake migrations',
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
        $fake = (bool)$args->getOption('fake');

        $io->out('Rolling back all migrations');
        $manager->rollback(0, false, true, $fake);

        $io->out('Migrating to latest');
        $manager->migrate(null, $fake);

        return self::CODE_SUCCESS;
    }
}

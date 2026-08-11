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
use Crustum\Mongo\Migration\ManagerFactory;

/**
 * Seed command runs seeders.
 */
class SeedCommand extends Command
{
    /**
     * @inheritDoc
     */
    public static function getDescription(): string
    {
        return 'Run database seeders.';
    }

    /**
     * The default name added to the application command list.
     *
     * @return string
     */
    public static function defaultName(): string
    {
        return 'migrations seed';
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
            'Run database seeders',
            '',
            '<info>migrations seed</info>',
            '<info>migrations seed --seed UsersSeed</info>',
        ])->addArgument('seed', [
            'help' => 'The seed name to run (all seeds run when omitted)',
        ])->addOption('plugin', [
            'short' => 'p',
            'help' => 'The plugin to run migrations for',
        ])->addOption('connection', [
            'short' => 'c',
            'help' => 'The datasource connection to use',
            'default' => 'default',
        ])->addOption('source', [
            'short' => 's',
            'default' => ConfigInterface::DEFAULT_SEED_FOLDER,
            'help' => 'The folder where your seeds are',
        ])->addOption('force', [
            'short' => 'f',
            'help' => 'Force re-running seeds even if already executed',
            'boolean' => true,
        ])->addOption('fake', [
            'help' => 'Record seeds as executed without running them',
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

        $seed = $args->getArgument('seed');
        $manager->seed(
            is_string($seed) ? $seed : null,
            (bool)$args->getOption('force'),
            (bool)$args->getOption('fake'),
        );

        return self::CODE_SUCCESS;
    }
}

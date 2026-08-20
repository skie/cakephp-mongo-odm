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
use Crustum\Mongo\Migration\Util\Util;

/**
 * Seed reset command removes seeds from the execution log.
 *
 * @ported-from \Migrations\Command\SeedResetCommand
 */
class SeedResetCommand extends Command
{
    /**
     * @inheritDoc
     */
    public static function getDescription(): string
    {
        return 'Reset the seed execution state.';
    }

    /**
     * The default name added to the application command list.
     *
     * @return string
     */
    public static function defaultName(): string
    {
        return 'mongo migrations seed_reset';
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
            'The <info>reset</info> command removes seed execution records from the log',
            'allowing seeds to be re-run without the --force flag.',
            '',
            '<info>mongo migrations seed_reset</info>',
            '<info>mongo migrations seed_reset --seed Users</info>',
        ])->addOption('seed', [
            'help' => 'Comma-separated list of specific seeds to reset. Resets all seeds if not specified.',
        ])->addOption('plugin', [
            'short' => 'p',
            'help' => 'The plugin to reset seeds for',
        ])->addOption('connection', [
            'short' => 'c',
            'help' => 'The datasource connection to use',
            'default' => 'mongo',
        ])->addOption('source', [
            'short' => 's',
            'help' => 'The folder under config that seeds are in',
            'default' => ConfigInterface::DEFAULT_SEED_FOLDER,
        ])->addOption('dry-run', [
            'short' => 'd',
            'help' => 'Show what would be reset without actually doing it',
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
            'dry-run' => (bool)$args->getOption('dry-run'),
        ]);

        $manager = $factory->createManager($io);
        $config = $manager->getConfig();

        if ($config->isDryRun()) {
            $io->info('DRY-RUN mode enabled');
        }

        $io->verbose('<info>using connection</info> ' . $args->getOption('connection'));
        $io->verbose('<info>using paths</info> ' . $config->getSeedPath());

        $seeds = $manager->getSeeds();
        $adapter = $manager->getEnvironment()->getAdapter();

        $seedOption = $args->getOption('seed');
        $seedsToReset = $seeds;

        if ($seedOption) {
            $requestedSeeds = array_map(trim(...), explode(',', (string)$seedOption));
            $seedsToReset = [];

            foreach ($requestedSeeds as $requestedSeed) {
                $normalizedName = $manager->normalizeSeedName($requestedSeed, $seeds);
                if ($normalizedName === null) {
                    $io->error(sprintf('Seed `%s` does not exist.', $requestedSeed));

                    return self::CODE_ERROR;
                }

                $seedsToReset[$normalizedName] = $seeds[$normalizedName];
            }
        }

        if ($seedsToReset === []) {
            $io->warning('No seeds to reset.');

            return self::CODE_SUCCESS;
        }

        $io->out('');
        $resetAllMessage = $seedOption ? '<info>The following seeds will be reset:</info>' : '<info>All seeds will be reset:</info>';
        $io->out($resetAllMessage);
        foreach ($seedsToReset as $seed) {
            $io->out('  - ' . Util::getSeedDisplayName($seed->getName()));
        }

        $io->out('');

        if (!$config->isDryRun()) {
            $continue = $io->askChoice('Do you want to continue?', ['y', 'n'], 'n');
            if ($continue !== 'y') {
                $io->warning('Reset operation aborted.');

                return self::CODE_SUCCESS;
            }
        }

        $count = 0;
        foreach ($seedsToReset as $seed) {
            $seedName = Util::getSeedDisplayName($seed->getName());
            if ($manager->isSeedExecuted($seed)) {
                if (!$config->isDryRun()) {
                    $adapter->removeSeedFromLog($seed);
                }

                $io->info(sprintf('Reset: %s seed', $seedName));
                $count++;
            } else {
                $io->verbose(sprintf('Skipped (not executed): %s seed', $seedName));
            }
        }

        $io->out('');
        if ($config->isDryRun()) {
            $io->success(sprintf('DRY-RUN: Would reset %d seed(s).', $count));
        } else {
            $io->success(sprintf('Reset %d seed(s).', $count));
        }

        return self::CODE_SUCCESS;
    }
}

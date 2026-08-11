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
use Cake\Event\EventDispatcherTrait;
use Crustum\Mongo\Migration\Config\ConfigInterface;
use Crustum\Mongo\Migration\ManagerFactory;
use DateTime;
use LogicException;
use Throwable;

/**
 * Migrate command runs migrations.
 */
class MigrateCommand extends Command
{
    use EventDispatcherTrait;

    /**
     * @inheritDoc
     */
    public static function getDescription(): string
    {
        return 'Run un-applied migrations.';
    }

    /**
     * The default name added to the application command list.
     *
     * @return string
     */
    public static function defaultName(): string
    {
        return 'mongo migrations migrate';
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
            'Apply migrations to a Mongo datasource',
            '',
            'Will run all available migrations, optionally up to a specific version',
            '',
            '<info>migrations migrate --connection secondary</info>',
            '<info>migrations migrate --connection secondary --target 003</info>',
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
            'help' => 'The target version to migrate to',
        ])->addOption('date', [
            'short' => 'd',
            'help' => 'The date to migrate to',
        ])->addOption('count', [
            'short' => 'k',
            'help' => 'The number of migrations to run',
        ])->addOption('fake', [
            'help' => "Mark any migrations selected as run, but don't actually execute them",
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
        $event = $this->dispatchEvent('Migration.beforeMigrate');
        if ($event->isStopped()) {
            return $event->getResult() ? self::CODE_SUCCESS : self::CODE_ERROR;
        }
        $result = $this->executeMigrations($args, $io);
        $this->dispatchEvent('Migration.afterMigrate');

        return $result;
    }

    /**
     * Execute migrations based on console inputs.
     *
     * @param \Cake\Console\Arguments $args The command arguments
     * @param \Cake\Console\ConsoleIo $io The console io
     * @return int|null The exit code or null for success
     */
    protected function executeMigrations(Arguments $args, ConsoleIo $io): ?int
    {
        $version = $args->getOption('target') !== null ? (int)$args->getOption('target') : null;
        $date = $args->getOption('date');
        $fake = (bool)$args->getOption('fake');

        $count = $args->getOption('count') !== null ? (int)$args->getOption('count') : null;
        if ($count !== null && $count < 1) {
            throw new LogicException('Count must be > 0.');
        }
        if ($count && $date) {
            throw new LogicException('Can only use one of `--count` or `--date` options at a time.');
        }
        if ($version && $date) {
            throw new LogicException('Can only use one of `--version` or `--date` options at a time.');
        }

        $factory = new ManagerFactory([
            'plugin' => $args->getOption('plugin'),
            'source' => $args->getOption('source'),
            'connection' => $args->getOption('connection'),
        ]);

        $manager = $factory->createManager($io);
        $config = $manager->getConfig();

        $io->verbose('<info>using connection</info> ' . $args->getOption('connection'));
        $io->verbose('<info>using paths</info> ' . $config->getMigrationPath());
        $io->verbose('<info>ordering by</info> ' . $config->getVersionOrder() . ' time');

        if ($fake) {
            $io->out('<warning>warning</warning> performing fake migrations');
        }

        try {
            $start = microtime(true);
            if ($date !== null) {
                $manager->migrateToDateTime(new DateTime((string)$date), $fake);
            } else {
                $manager->migrate($version, $fake, $count);
            }
            $end = microtime(true);
        } catch (Throwable $e) {
            $io->err('<error>' . $e->getMessage() . '</error>');
            $io->verbose($e->getTraceAsString());

            return self::CODE_ERROR;
        }

        $io->comment('All Done. Took ' . sprintf('%.4fs', $end - $start));
        $io->out('');

        return self::CODE_SUCCESS;
    }
}

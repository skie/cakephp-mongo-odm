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
use DateTime;
use LogicException;
use Throwable;

/**
 * Rollback command reverts migrations.
 *
 * @inspired-by \Migrations\Command\RollbackCommand
 */
class RollbackCommand extends Command
{
    /**
     * @inheritDoc
     */
    public static function getDescription(): string
    {
        return 'Rollback migrations.';
    }

    /**
     * The default name added to the application command list.
     *
     * @return string
     */
    public static function defaultName(): string
    {
        return 'mongo migrations rollback';
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
            'Revert the last batch of migrations',
            '',
            'Will rollback all applied migrations, optionally down to a specific version',
            '',
            '<info>migrations rollback</info>',
            '<info>migrations rollback --connection secondary</info>',
            '<info>migrations rollback --target 20260811000000</info>',
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
            'help' => 'The target version to rollback to',
        ])->addOption('date', [
            'short' => 'd',
            'help' => 'The date to rollback to',
        ])->addOption('count', [
            'short' => 'k',
            'help' => 'The number of migrations to rollback',
        ])->addOption('force', [
            'short' => 'f',
            'help' => 'Force rollback even past a breakpoint',
            'boolean' => true,
        ])->addOption('fake', [
            'help' => "Mark migrations as reverted, but don't actually execute them",
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
        $version = $args->getOption('target') !== null ? (int)$args->getOption('target') : null;
        $date = $args->getOption('date');
        $force = (bool)$args->getOption('force');
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

        try {
            $start = microtime(true);
            if ($count) {
                $manager->rollbackByCount($count, $force, $fake);
            } elseif ($date !== null) {
                $manager->rollbackToDateTime(new DateTime((string)$date), $force);
            } else {
                $manager->rollback($version, $force, true, $fake);
            }

            $end = microtime(true);
        } catch (Throwable $throwable) {
            $io->err('<error>' . $throwable->getMessage() . '</error>');
            $io->verbose($throwable->getTraceAsString());

            return self::CODE_ERROR;
        }

        $io->comment('All Done. Took ' . sprintf('%.4fs', $end - $start));
        $io->out('');

        return self::CODE_SUCCESS;
    }
}

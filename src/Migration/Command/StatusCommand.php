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
 * Status command prints the migration status.
 */
class StatusCommand extends Command
{
    /**
     * @inheritDoc
     */
    public static function getDescription(): string
    {
        return 'Displays the migration status.';
    }

    /**
     * The default name added to the application command list.
     *
     * @return string
     */
    public static function defaultName(): string
    {
        return 'mongo migrations status';
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
            'Displays the status of all migrations',
            '',
            'Returns the list of migrations, applied or not',
            '',
            '<info>migrations status</info>',
            '<info>migrations status --connection secondary</info>',
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
        ])->addOption('format', [
            'help' => 'Format output: text or json',
            'choices' => ['text', 'json'],
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
        $format = $args->getOption('format');
        $status = $manager->printStatus(is_string($format) ? $format : null);

        if ($format === 'json') {
            $json = json_encode($status, JSON_PRETTY_PRINT);
            $io->out($json !== false ? $json : '[]');

            return self::CODE_SUCCESS;
        }

        $io->out('');
        $io->out('<info>Migrations status:</info>');
        $io->out('');
        foreach ($status as $entry) {
            $missing = isset($entry['missing']) && $entry['missing'];
            $statusLabel = $entry['status'] === 'up' ? '<success>up</success>' : '<warning>down</warning>';
            $name = (string)$entry['name'];
            if ($missing) {
                $name .= ' <error>(missing)</error>';
            }

            $io->out(sprintf(
                ' %s %s %s',
                str_pad((string)$entry['id'], 14, ' ', STR_PAD_LEFT),
                $statusLabel,
                $name,
            ));
        }

        return self::CODE_SUCCESS;
    }
}

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

/**
 * Bakes a Mongo migration from the schema diff (lock file vs live).
 *
 * Thin alias over `mongo migrations diff` exposed as a bake command, matching
 * the cakephp/migrations `bake migration_diff` command.
 */
class BakeMigrationDiffCommand extends Command
{
    /**
     * @inheritDoc
     */
    public static function getDescription(): string
    {
        return 'Bake a Mongo migration from the schema diff (lock vs live).';
    }

    /**
     * The default name added to the application command list.
     *
     * @return string
     */
    public static function defaultName(): string
    {
        return 'bake mongo_migration_diff';
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
            'Bake a Mongo migration from the schema diff (lock file vs live)',
            '',
            '<info>bin/cake bake mongo_migration_diff SchemaSync</info>',
        ])->addArgument('name', [
            'help' => 'The migration class name in CamelCase',
            'required' => false,
        ])->addOption('plugin', [
            'short' => 'p',
            'help' => 'The plugin to run migrations for',
        ])->addOption('connection', [
            'short' => 'c',
            'help' => 'The datasource connection to use',
            'default' => 'mongo',
        ])->addOption('source', [
            'short' => 's',
            'help' => 'The folder where your migrations are',
        ])->addOption('schema-file', [
            'help' => 'The desired schema lock file',
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
        $argv = [];

        $name = $args->getArgument('name');
        if (is_string($name) && $name !== '') {
            $argv[] = $name;
        }

        $optionNames = ['plugin', 'connection', 'source', 'schema-file'];
        foreach ($optionNames as $option) {
            $value = $args->getOption($option);
            if ($value !== null && $value !== '' && $value !== false) {
                $argv[] = '--' . $option;
                if (is_string($value)) {
                    $argv[] = $value;
                }
            }
        }

        return $this->executeCommand(DiffCommand::class, $argv, $io);
    }
}

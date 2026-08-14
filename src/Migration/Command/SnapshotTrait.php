<?php
declare(strict_types=1);

/**
 * Ported and adapted from cakephp/migrations (MIT License).
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc.
 * @license https://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace Crustum\Mongo\Migration\Command;

use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;

/**
 * Helpers shared by the snapshot bake commands.
 *
 * Ported from `Migrations\Command\SnapshotTrait`. The reference overrides the
 * bake-task `createFile()` hook; our bake commands write files directly, so the
 * commands call `markSnapshotApplied()` / `refreshDump()` explicitly after a
 * successful write.
 */
trait SnapshotTrait
{
    /**
     * Marks the baked snapshot as migrated so a subsequent diff does not
     * re-propose the schema it just captured.
     *
     * @param string $path Path to the newly created snapshot
     * @param \Cake\Console\Arguments $args The command arguments
     * @param \Cake\Console\ConsoleIo $io The console io
     * @return void
     */
    protected function markSnapshotApplied(string $path, Arguments $args, ConsoleIo $io): void
    {
        $fileName = pathinfo($path, PATHINFO_FILENAME);
        [$version] = explode('_', $fileName, 2);

        $newArgs = ['--target', $version, '--only'];
        $newArgs = array_merge($newArgs, $this->parseOptions($args));

        $io->out('Marking the migration ' . $fileName . ' as migrated...');
        $this->executeCommand(MarkMigratedCommand::class, $newArgs, $io);
    }

    /**
     * Refreshes the schema dump lock so a new diff can be generated afterward.
     *
     * @param \Cake\Console\Arguments $args The command arguments
     * @param \Cake\Console\ConsoleIo $io The console io
     * @return void
     */
    protected function refreshDump(Arguments $args, ConsoleIo $io): void
    {
        $newArgs = $this->parseOptions($args);

        $io->out('Creating a dump of the new database state...');
        $this->executeCommand(DumpCommand::class, $newArgs, $io);
    }

    /**
     * Parses the connection, plugin and source options into a new argv array.
     *
     * @param \Cake\Console\Arguments $args The command arguments
     * @return array<int, string>
     */
    protected function parseOptions(Arguments $args): array
    {
        $newArgs = [];
        if ($args->getOption('connection')) {
            $newArgs[] = '--connection';
            $newArgs[] = $args->getOption('connection');
        }

        if ($args->getOption('plugin')) {
            $newArgs[] = '--plugin';
            $newArgs[] = $args->getOption('plugin');
        }

        if ($args->getOption('source')) {
            $newArgs[] = '--source';
            $newArgs[] = $args->getOption('source');
        }

        return $newArgs;
    }
}

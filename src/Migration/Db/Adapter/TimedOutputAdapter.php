<?php
declare(strict_types=1);

/**
 * Ported and adapted from cakephp/migrations (MIT License).
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc.
 * @license https://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace Crustum\Mongo\Migration\Db\Adapter;

use Cake\Console\ConsoleIo;

/**
 * Wraps any adapter to record the time spent executing its commands.
 *
 * DDL commands are written to the console output (verbose level) with a
 * measured duration.
 */
class TimedOutputAdapter extends AdapterWrapper
{
    /**
     * Start timing a command.
     *
     * @return callable A function to call when the command finishes
     */
    public function startCommandTimer(): callable
    {
        $started = microtime(true);

        return function () use ($started): void {
            $end = microtime(true);
            $this->getIo()?->verbose('    -> ' . sprintf('%.4fs', $end - $started));
        };
    }

    /**
     * Write a command to the output.
     *
     * @param string $command Command name
     * @param array<int, mixed> $args Command args
     * @return void
     */
    public function writeCommand(string $command, array $args = []): void
    {
        $io = $this->getIo();
        if ($io instanceof ConsoleIo && $io->level() < ConsoleIo::VERBOSE) {
            return;
        }

        if ($args !== []) {
            $outArr = [];
            foreach ($args as $arg) {
                if (is_array($arg)) {
                    $arg = array_map(fn(mixed $value): string => "'" . $value . "'", $arg);
                    $outArr[] = '[' . implode(', ', $arg) . ']';
                    continue;
                }

                $outArr[] = "'" . $arg . "'";
            }
            $this->getIo()?->verbose(' -- ' . $command . '(' . implode(', ', $outArr) . ')');

            return;
        }

        $this->getIo()?->verbose(' -- ' . $command);
    }

    /**
     * @inheritDoc
     */
    public function createCollection(string $name, array $options = []): void
    {
        $end = $this->startCommandTimer();
        $this->writeCommand('createCollection', [$name]);
        $this->getAdapter()->createCollection($name, $options);
        $end();
    }

    /**
     * @inheritDoc
     */
    public function dropCollection(string $name): void
    {
        $end = $this->startCommandTimer();
        $this->writeCommand('dropCollection', [$name]);
        $this->getAdapter()->dropCollection($name);
        $end();
    }

    /**
     * @inheritDoc
     */
    public function renameCollection(string $from, string $to, bool $dropTarget = false): void
    {
        $end = $this->startCommandTimer();
        $this->writeCommand('renameCollection', [$from, $to]);
        $this->getAdapter()->renameCollection($from, $to, $dropTarget);
        $end();
    }

    /**
     * @inheritDoc
     */
    public function createIndex(string $name, array|string $key, array $options = []): string
    {
        $end = $this->startCommandTimer();
        $this->writeCommand('createIndex', [$name, $key]);
        $indexName = $this->getAdapter()->createIndex($name, $key, $options);
        $end();

        return $indexName;
    }

    /**
     * @inheritDoc
     */
    public function dropIndex(string $name, string $indexName): void
    {
        $end = $this->startCommandTimer();
        $this->writeCommand('dropIndex', [$name, $indexName]);
        $this->getAdapter()->dropIndex($name, $indexName);
        $end();
    }

    /**
     * @inheritDoc
     */
    public function setValidator(
        string $name,
        ?array $validator,
        ?string $validationLevel = null,
        ?string $validationAction = null,
    ): void {
        $end = $this->startCommandTimer();
        $this->writeCommand('setValidator', [$name]);
        $this->getAdapter()->setValidator($name, $validator, $validationLevel, $validationAction);
        $end();
    }
}

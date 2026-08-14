<?php
declare(strict_types=1);

/**
 * Ported and adapted from cakephp/migrations (MIT License).
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc.
 * @license https://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace Crustum\Mongo\Migration\Db\Adapter;

use Crustum\Mongo\Migration\Migration\IrreversibleMigrationException;

/**
 * Recording proxy adapter.
 *
 * Extends the `AdapterWrapper` (like the reference `RecordingAdapter`) and
 * records DDL commands so `change()` migrations can be reversed for the `down`
 * direction. Inverse commands are executed in reverse order; all other methods
 * are delegated to the wrapped adapter.
 */
class RecordingAdapter extends AdapterWrapper
{
    /**
     * Recorded commands, each `[method, args]`.
     *
     * @var list<array{string, array<int, mixed>}>
     */
    protected array $commands = [];

    /**
     * @inheritDoc
     */
    public function createCollection(string $name, array $options = []): void
    {
        $this->commands[] = ['createCollection', [$name, $options]];
    }

    /**
     * @inheritDoc
     */
    public function dropCollection(string $name): void
    {
        $this->commands[] = ['dropCollection', [$name]];
    }

    /**
     * @inheritDoc
     */
    public function renameCollection(string $from, string $to, bool $dropTarget = false): void
    {
        $this->commands[] = ['renameCollection', [$from, $to, $dropTarget]];
    }

    /**
     * @inheritDoc
     */
    public function createIndex(string $name, array|string $key, array $options = []): string
    {
        $indexName = $options['name'] ?? $this->defaultIndexName($key);
        $this->commands[] = ['createIndex', [$name, $indexName, $key]];

        return $indexName;
    }

    /**
     * @inheritDoc
     */
    public function dropIndex(string $name, string $indexName): void
    {
        $this->commands[] = ['dropIndex', [$name, $indexName]];
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
        $this->commands[] = ['setValidator', [$name, $validator, $validationLevel, $validationAction]];
    }

    /**
     * Executes the recorded commands in reverse.
     *
     * @throws \Crustum\Mongo\Migration\Migration\IrreversibleMigrationException When a recorded command cannot be reversed.
     * @return void
     */
    public function executeInvertedCommands(): void
    {
        foreach (array_reverse($this->commands) as [$method, $args]) {
            $inverse = $this->inverseMethod($method);
            $this->getAdapter()->{$inverse}(...$this->invertArgs($method, $args));
        }
    }

    /**
     * Maps a recorded method to its inverse.
     *
     * @param string $method Recorded method
     * @throws \Crustum\Mongo\Migration\Migration\IrreversibleMigrationException
     * @return string The inverse method
     */
    protected function inverseMethod(string $method): string
    {
        return match ($method) {
            'createCollection' => 'dropCollection',
            'dropCollection' => 'createCollection',
            'renameCollection' => 'renameCollection',
            'createIndex' => 'dropIndex',
            'setValidator' => 'setValidator',
            default => throw new IrreversibleMigrationException(sprintf(
                'Cannot reverse a "%s" command',
                $method,
            )),
        };
    }

    /**
     * Re-orders arguments for the inverse call.
     *
     * @param string $method Recorded method
     * @param array<int, mixed> $args Recorded arguments
     * @return array<int, mixed> Arguments for the inverse call
     */
    protected function invertArgs(string $method, array $args): array
    {
        return match ($method) {
            'renameCollection' => [$args[1], $args[0], $args[2] ?? false],
            'createIndex' => [$args[0], $args[1]],
            'dropCollection' => [$args[0], []],
            'setValidator' => [$args[0], $args[1], $args[2] ?? null, $args[3] ?? null],
            default => $args,
        };
    }

    /**
     * Computes the default index name MongoDB generates for a key map.
     *
     * Mirrors the server-side naming so `createIndex()` can be recorded without
     * touching the database and reversed by name.
     *
     * @param array<string, int|string>|string $key The index key(s)
     * @return string The generated index name
     */
    protected function defaultIndexName(array|string $key): string
    {
        $keys = is_string($key) ? [$key => 1] : $key;

        $parts = [];
        foreach ($keys as $field => $direction) {
            $suffix = match (true) {
                $direction === 1 => '1',
                $direction === -1 => '-1',
                default => (string)$direction,
            };
            $parts[] = $field . '_' . $suffix;
        }

        return implode('_', $parts);
    }
}

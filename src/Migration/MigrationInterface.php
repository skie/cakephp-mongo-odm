<?php
declare(strict_types=1);

/**
 * Ported and adapted from cakephp/migrations (MIT License).
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc.
 * @license https://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace Crustum\Mongo\Migration;

use Cake\Console\ConsoleIo;
use Crustum\Mongo\Migration\Adapter\AdapterInterface;
use Crustum\Mongo\Migration\Config\ConfigInterface;

/**
 * Migration interface.
 *
 * Implements the same methods as cakephp/migrations but the SQL query
 * builders are replaced by Mongo-native operations (collections, indexes,
 * validators) executed through the adapter.
 */
interface MigrationInterface
{
    /**
     * @var string
     */
    public const CHANGE = 'change';

    /**
     * @var string
     */
    public const UP = 'up';

    /**
     * @var string
     */
    public const DOWN = 'down';

    /**
     * @var string
     */
    public const INIT = 'init';

    /**
     * Sets the database adapter.
     *
     * @param \Crustum\Mongo\Migration\Adapter\AdapterInterface $adapter Database adapter
     * @return $this
     */
    public function setAdapter(AdapterInterface $adapter): static;

    /**
     * Gets the database adapter.
     *
     * @return \Crustum\Mongo\Migration\Adapter\AdapterInterface
     */
    public function getAdapter(): AdapterInterface;

    /**
     * Sets the Console IO object to be used.
     *
     * @param \Cake\Console\ConsoleIo $io The IO
     * @return $this
     */
    public function setIo(ConsoleIo $io): static;

    /**
     * Gets the Console IO object to be used.
     *
     * @return \Cake\Console\ConsoleIo|null
     */
    public function getIo(): ?ConsoleIo;

    /**
     * Gets the config.
     *
     * @return \Crustum\Mongo\Migration\Config\ConfigInterface|null
     */
    public function getConfig(): ?ConfigInterface;

    /**
     * Sets the config.
     *
     * @param \Crustum\Mongo\Migration\Config\ConfigInterface $config Configuration object
     * @return $this
     */
    public function setConfig(ConfigInterface $config): static;

    /**
     * Gets the name.
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Sets the migration version number.
     *
     * @param int $version Version
     * @return $this
     */
    public function setVersion(int $version): static;

    /**
     * Gets the migration version number.
     *
     * @return int
     */
    public function getVersion(): int;

    /**
     * Sets whether this migration is being applied or reverted.
     *
     * @param bool $isMigratingUp True if the migration is being applied
     * @return $this
     */
    public function setMigratingUp(bool $isMigratingUp): static;

    /**
     * Gets whether this migration is being applied or reverted.
     *
     * True means that the migration is being applied.
     *
     * @return bool
     */
    public function isMigratingUp(): bool;

    /**
     * Hook method to decide if this migration should use transactions.
     *
     * By default, if the adapter supports transactions, a transaction will be
     * opened before the migration begins and committed when it completes.
     *
     * @return bool
     */
    public function useTransactions(): bool;

    /**
     * Checks whether a collection exists.
     *
     * @param string $collectionName Collection name
     * @return bool
     */
    public function hasCollection(string $collectionName): bool;

    /**
     * Returns the names of all collections.
     *
     * @return list<string>
     */
    public function listCollections(): array;

    /**
     * Creates a collection.
     *
     * @param string $collectionName Collection name
     * @param array<string, mixed> $options Collection options (validator, validationLevel, capped, …)
     * @return void
     */
    public function createCollection(string $collectionName, array $options = []): void;

    /**
     * Drops a collection.
     *
     * @param string $collectionName Collection name
     * @return void
     */
    public function dropCollection(string $collectionName): void;

    /**
     * Renames a collection.
     *
     * @param string $from Current collection name
     * @param string $to New collection name
     * @param bool $dropTarget Whether to drop an existing target first
     * @return void
     */
    public function renameCollection(string $from, string $to, bool $dropTarget = false): void;

    /**
     * Creates an index on a collection.
     *
     * @param string $collectionName Collection name
     * @param array<string, int|string>|string $key Index key(s), e.g. `['author_id' => 1]`
     * @param array<string, mixed> $options Index options (unique, sparse, expireAfterSeconds, …)
     * @return string The index name
     */
    public function index(string $collectionName, array|string $key, array $options = []): string;

    /**
     * Creates a unique index on a collection.
     *
     * @param string $collectionName Collection name
     * @param array<string, int|string>|string $key Index key(s)
     * @param array<string, mixed> $options Index options
     * @return string The index name
     */
    public function uniqueIndex(string $collectionName, array|string $key, array $options = []): string;

    /**
     * Drops an index from a collection.
     *
     * @param string $collectionName Collection name
     * @param string $indexName Index name
     * @return void
     */
    public function dropIndex(string $collectionName, string $indexName): void;

    /**
     * Sets (or removes) the validator of a collection.
     *
     * @param string $collectionName Collection name
     * @param array<string, mixed>|null $validator The `$jsonSchema` rules, or null to clear
     * @param string|null $validationLevel Validation level
     * @param string|null $validationAction Validation action
     * @return void
     */
    public function setValidator(
        string $collectionName,
        ?array $validator,
        ?string $validationLevel = null,
        ?string $validationAction = null,
    ): void;

    /**
     * Perform checks on the migration, printing a warning if there are
     * potential problems.
     *
     * @return void
     */
    public function preFlightCheck(): void;

    /**
     * Perform checks on the migration after completion.
     *
     * @return void
     */
    public function postFlightCheck(): void;

    /**
     * Checks whether the migration should be executed.
     *
     * @return bool
     */
    public function shouldExecute(): bool;
}

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
use MongoDB\Collection;

/**
 * Seed interface.
 */
interface SeedInterface
{
    /**
     * @var string
     */
    public const RUN = 'run';

    /**
     * @var string
     */
    public const INIT = 'init';

    /**
     * Run the seeder.
     *
     * @return void
     */
    public function run(): void;

    /**
     * Return seeds dependencies.
     *
     * @return array<int, string>
     */
    public function getDependencies(): array;

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
     * Set the Console IO object to be used.
     *
     * @param \Cake\Console\ConsoleIo $io The IO
     * @return $this
     */
    public function setIo(ConsoleIo $io): static;

    /**
     * Get the Console IO object to be used.
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
     * Returns a Mongo collection for data operations.
     *
     * @param string $collectionName Collection name
     * @return \MongoDB\Collection
     */
    public function collection(string $collectionName): Collection;

    /**
     * Insert data into a collection.
     *
     * @param string $collectionName Collection name
     * @param array<string, mixed> $data Data
     * @return void
     */
    public function insert(string $collectionName, array $data): void;

    /**
     * Insert data into a collection, skipping rows that would cause duplicate key conflicts.
     *
     * @param string $collectionName Collection name
     * @param array<string, mixed> $data Data
     * @param array<string, mixed> $filter Unique filter to check existence against
     * @return void
     */
    public function insertOrSkip(string $collectionName, array $data, array $filter = []): void;

    /**
     * Insert data into a collection, updating on a duplicate key conflict.
     *
     * @param string $collectionName Collection name
     * @param array<string, mixed> $data Data
     * @param array<string, mixed> $filter Unique filter for the upsert
     * @return void
     */
    public function insertOrUpdate(string $collectionName, array $data, array $filter = []): void;

    /**
     * Checks whether a collection exists.
     *
     * @param string $collectionName Collection name
     * @return bool
     */
    public function hasCollection(string $collectionName): bool;

    /**
     * Checks whether the seed should be executed.
     *
     * @return bool
     */
    public function shouldExecute(): bool;

    /**
     * Checks whether this seed is idempotent (can run multiple times safely).
     *
     * @return bool
     */
    public function isIdempotent(): bool;

    /**
     * Gives the ability to a seeder to call another seeder.
     *
     * @param string $seeder Name of the seeder to call from the current seed
     * @param array<string, mixed> $options The CLI options for the seeder
     * @return void
     */
    public function call(string $seeder, array $options = []): void;
}

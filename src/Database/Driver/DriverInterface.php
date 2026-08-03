<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Driver;

use Crustum\Mongo\Database\Enum\DriverFeature;
use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Database;
use MongoDB\Driver\Manager;

/**
 * Contract for a MongoDB driver.
 *
 * Mongo-native: deliberately does NOT extend `Cake\Database\Driver` (PDO
 * machinery). The driver owns the `MongoDB\Client` and exposes database,
 * collection and manager accessors plus capability probing.
 */
interface DriverInterface
{
    /**
     * Returns the MongoDB client, connecting on demand.
     *
     * @return \MongoDB\Client
     */
    public function getClient(): Client;

    /**
     * Returns the configured database, connecting on demand.
     *
     * @return \MongoDB\Database
     */
    public function getDatabase(): Database;

    /**
     * Returns the underlying driver manager.
     *
     * @return \MongoDB\Driver\Manager
     */
    public function getManager(): Manager;

    /**
     * Returns a collection by name.
     *
     * @param string $name Collection name.
     * @return \MongoDB\Collection
     */
    public function getCollection(string $name): Collection;

    /**
     * Returns whether the server supports the given capability.
     *
     * @param \Crustum\Mongo\Database\Enum\DriverFeature $feature The capability to probe.
     * @return bool
     */
    public function supports(DriverFeature $feature): bool;

    /**
     * Returns the driver configuration.
     *
     * @return array<string, mixed>
     */
    public function config(): array;

    /**
     * Establishes the connection.
     *
     * @return void
     */
    public function connect(): void;

    /**
     * Closes the connection.
     *
     * @return void
     */
    public function disconnect(): void;
}

<?php
declare(strict_types=1);

namespace Crustum\Mongo\Datasource;

use Cake\Datasource\SchemaInterface;

/**
 * Contract for introspecting Mongo collections and their schemas.
 *
 * The concrete implementation lives in the Database layer
 * (`Crustum\Mongo\Database\Schema\SchemaCollection`), which has access to the
 * connection/driver. This interface returns the base `Cake\Datasource\SchemaInterface`
 * so the Datasource layer stays free of Database imports (per the dependency rule).
 */
interface SchemaCollectionInterface
{
    /**
     * Returns the list of collection names in the database.
     *
     * @return list<string>
     */
    public function listCollections(): array;

    /**
     * Returns the schema for the given collection.
     *
     * @param string $name Collection name.
     * @return \Cake\Datasource\SchemaInterface
     */
    public function describe(string $name): SchemaInterface;

    /**
     * Clears the schema cache, optionally for a single collection.
     *
     * @param string|null $name Collection name; null clears all.
     * @return void
     */
    public function clearCache(?string $name = null): void;
}

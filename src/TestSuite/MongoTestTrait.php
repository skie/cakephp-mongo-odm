<?php
declare(strict_types=1);

namespace Crustum\Mongo\TestSuite;

use Cake\Datasource\ConnectionManager;
use Crustum\Mongo\Database\Connection;
use MongoDB\Collection;

/**
 * Mix into `Cake\TestSuite\TestCase` (optionally with `IntegrationTestTrait`)
 * to get Mongo connection/collection helpers without forgoing SQL or
 * controller integration testing.
 *
 * Fixture support is via `Crustum\Mongo\TestSuite\TestFixture` mixed into the
 * regular `$fixtures` list — Cake's `FixtureHelper`/`TruncateStrategy` are
 * connection-generic and handle SQL and Mongo fixtures together.
 *
 * Usage:
 * ```
 * class ArticlesControllerTest extends Cake\TestSuite\TestCase
 * {
 *     use Cake\TestSuite\IntegrationTestTrait;
 *     use Crustum\Mongo\TestSuite\MongoTestTrait;
 * }
 * ```
 */
trait MongoTestTrait
{
    /**
     * Returns the default Mongo connection name.
     *
     * Override in the test class to use another connection.
     *
     * @return string
     */
    public static function defaultMongoConnectionName(): string
    {
        return 'test_mongo';
    }

    /**
     * Returns the Mongo connection.
     *
     * @return \Crustum\Mongo\Database\Connection
     */
    public function getMongoConnection(): Connection
    {
        $connection = ConnectionManager::get(static::defaultMongoConnectionName());
        assert($connection instanceof Connection);

        return $connection;
    }

    /**
     * Returns a collection by name.
     *
     * @param string $name Collection name.
     * @return \MongoDB\Collection
     */
    public function getCollection(string $name): Collection
    {
        return $this->getMongoConnection()->getCollection($name);
    }
}

<?php
declare(strict_types=1);

namespace Crustum\Mongo\TestSuite;

use Cake\Core\App;
use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\Fixture\FixtureStrategyInterface;
use Cake\TestSuite\Fixture\TruncateStrategy;
use Crustum\Mongo\Database\Connection;
use MongoDB\Collection;

/**
 * Mix into `Cake\TestSuite\TestCase` (optionally with `IntegrationTestTrait`)
 * to get Mongo connection/collection helpers and a Mongo-aware fixture
 * strategy without forgoing SQL or controller integration testing.
 *
 * Fixture support is via `Crustum\Mongo\TestSuite\TestFixture` mixed into the
 * regular `$fixtures` list — Cake's `FixtureHelper` is connection-generic and
 * handles SQL and Mongo fixtures together. `TransactionStrategy` cannot be used
 * for Mongo (no SQL savepoints), so when Mongo fixtures are present this trait
 * falls back to `TruncateStrategy`; pure-SQL tests keep the app-configured
 * strategy.
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

    /**
     * Returns the fixture strategy used by these tests.
     *
     * When Mongo fixtures are present, `TransactionStrategy` cannot be used
     * (Mongo has no SQL savepoints), so the Mongo strategy is used instead.
     * Pure-SQL tests keep the app-configured strategy untouched.
     *
     * @return \Cake\TestSuite\Fixture\FixtureStrategyInterface
     */
    protected function getFixtureStrategy(): FixtureStrategyInterface
    {
        if ($this->hasMongoFixtures()) {
            return $this->getMongoFixtureStrategy();
        }

        return parent::getFixtureStrategy();
    }

    /**
     * Returns the fixture strategy used for Mongo fixtures.
     *
     * Override in the test class (or set `TestSuite.mongoFixtureStrategy`) to
     * use a different strategy. Defaults to `TruncateStrategy`.
     *
     * @return \Cake\TestSuite\Fixture\FixtureStrategyInterface
     */
    protected function getMongoFixtureStrategy(): FixtureStrategyInterface
    {
        /** @var class-string<\Cake\TestSuite\Fixture\FixtureStrategyInterface>|null $className */
        $className = Configure::read('TestSuite.mongoFixtureStrategy');
        if (is_string($className) && class_exists($className)) {
            return new $className();
        }

        return new TruncateStrategy();
    }

    /**
     * Returns whether the test's fixture list contains Mongo fixtures.
     *
     * Resolves each fixture name to its class and checks for
     * `Crustum\Mongo\TestSuite\TestFixture`.
     *
     * @return bool
     */
    protected function hasMongoFixtures(): bool
    {
        foreach ($this->getFixtures() as $fixtureName) {
            if ($this->resolveFixtureClass($fixtureName) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolves a fixture name to a Mongo fixture class, or null.
     *
     * Mirrors `Cake\TestSuite\Fixture\FixtureHelper::loadFixtures()` name
     * resolution (supports `app.X`, `core.X`, `plugin.Plugin.X`). A class
     * qualifies as Mongo when it implements `MongoFixtureInterface`.
     *
     * @param string $fixtureName Fixture name (e.g. `app.Articles`).
     * @return class-string<\Crustum\Mongo\TestSuite\MongoFixtureInterface>|null
     */
    protected function resolveFixtureClass(string $fixtureName): ?string
    {
        if (!str_contains($fixtureName, '.')) {
            $className = $fixtureName;
        } else {
            [$type, $pathName] = explode('.', $fixtureName, 2);
            $path = explode('/', $pathName);
            $name = (string)array_pop($path);
            $additionalPath = implode('\\', $path);

            if ($type === 'core') {
                $baseNamespace = 'Cake';
            } elseif ($type === 'app') {
                $baseNamespace = Configure::read('App.namespace');
            } elseif ($type === 'plugin') {
                [$plugin, $name] = explode('.', $pathName);
                $baseNamespace = str_replace('/', '\\', (string)$plugin);
                $additionalPath = null;
            } else {
                return null;
            }

            $className = implode('\\', array_filter([
                $baseNamespace,
                'Test\Fixture',
                $additionalPath,
                $name . 'Fixture',
            ]));
        }

        $resolved = App::className($className, '', '');
        if ($resolved === null || !is_subclass_of($resolved, MongoFixtureInterface::class)) {
            return null;
        }

        /** @var class-string<\Crustum\Mongo\TestSuite\MongoFixtureInterface> $resolved */
        return $resolved;
    }
}

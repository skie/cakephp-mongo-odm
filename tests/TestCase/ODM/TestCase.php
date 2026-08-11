<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM;

use Cake\Datasource\ConnectionManager;
use Cake\Routing\Router;
use Cake\TestSuite\Fixture\FixtureStrategyInterface;
use Cake\TestSuite\Fixture\TruncateStrategy;
use Cake\TestSuite\TestCase as BaseTestCase;
use Crustum\Mongo\ODM\Locator\LocatorAwareTrait;
use Crustum\Mongo\TestSuite\MongoTestTrait;

/**
 * Base test case for ODM unit and integration tests.
 *
 * Integration tests declare Mongo fixtures via `$fixtures` using the
 * `plugin.Crustum/Mongo.<Name>` convention and use `getMongoConnection()` /
 * `getCollection()` to reach the `test_mongo` connection. Pure unit tests
 * may extend this class too; without declared fixtures nothing is loaded.
 */
abstract class TestCase extends BaseTestCase
{
    use MongoTestTrait;
    use LocatorAwareTrait;

    /**
     * Returns the fixture strategy used by the ODM test harness.
     *
     * The default strategy (`TruncateStrategy`) works with
     * `Crustum\Mongo\TestSuite\TestFixture` because `FixtureHelper` treats
     * connections that are not `Cake\Database\Connection` generically: it
     * inserts records and truncates collections around each test. Pinning the
     * strategy here also guards against a `TestSuite.fixtureStrategy` config
     * (e.g. `TransactionStrategy`) that requires `Cake\Database\Connection`
     * and therefore cannot manage Mongo fixtures.
     *
     * @return \Cake\TestSuite\Fixture\FixtureStrategyInterface
     */
    protected function getFixtureStrategy(): FixtureStrategyInterface
    {
        return new TruncateStrategy();
    }

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        ConnectionManager::alias('test_mongo', 'mongo');
        Router::reload();
    }

    /**
     * Clears the collection locator so collections and associations built in
     * one test never leak into the next, matching `TableLocator::clear()` in
     * Cake's `TestCase::tearDown()`.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        parent::tearDown();
        $this->getCollectionLocator()->clear();
        $this->collectionLocator = null;
    }
}

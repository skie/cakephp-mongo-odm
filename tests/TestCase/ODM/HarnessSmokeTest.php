<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM;

use Crustum\Mongo\Database\Connection;

/**
 * Smoke test proving the ODM integration harness end-to-end: the
 * `plugin.Crustum/Mongo.Articles` fixture convention resolves to
 * `Crustum\Mongo\Test\Fixture\ArticlesFixture`, records land in the Mongo
 * `articles` collection on the `test_mongo` connection, and `TruncateStrategy`
 * restores clean state after each test.
 */
final class HarnessSmokeTest extends TestCase
{
    /**
     * @var string[]
     */
    protected array $fixtures = [
        'plugin.Crustum/Mongo.Articles',
    ];

    public function testFixtureRecordsLoadedIntoMongo(): void
    {
        $collection = $this->getMongoConnection()->getCollection('articles');

        $this->assertSame(3, $collection->countDocuments());

        $titles = [];
        foreach ($collection->find() as $document) {
            $titles[] = $document['title'];
        }
        $this->assertContains('First Article', $titles);
        $this->assertContains('Second Article', $titles);
        $this->assertContains('Third Article', $titles);
    }

    public function testGetMongoConnectionReturnsMongoConnection(): void
    {
        $connection = $this->getMongoConnection();

        $this->assertInstanceOf(Connection::class, $connection);
    }

    public function testFixturesTruncatedAfterTest(): void
    {
        $collection = $this->getMongoConnection()->getCollection('articles');

        // TruncateStrategy runs after the previous test and before this one,
        // so the collection holds exactly the fixture records.
        $this->assertSame(3, $collection->countDocuments());

        // Mutating the collection proves the next test does not inherit data:
        // the teardown of THIS test must truncate before the next one runs.
        $collection->insertOne(['title' => 'Stray Document']);
        $this->assertSame(4, $collection->countDocuments());
    }

    public function testStrayDocumentsDoNotLeakBetweenTests(): void
    {
        // The previous test inserted a stray document; the harness truncates
        // between tests, so only fixture records must remain.
        $collection = $this->getMongoConnection()->getCollection('articles');

        $this->assertSame(3, $collection->countDocuments());
    }
}

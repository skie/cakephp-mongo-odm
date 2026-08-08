<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM;

use Crustum\Mongo\ODM\Document;

/**
 * ResultSet test case.
 */
class ResultSetTest extends TestCase
{
    /**
     * @var array<string>
     */
    protected array $fixtures = ['plugin.Crustum/Mongo.Articles', 'plugin.Crustum/Mongo.Authors', 'plugin.Crustum/Mongo.Comments'];

    /**
     * @var \Crustum\Mongo\ODM\BaseCollection
     */
    protected $collection;

    /**
     * @var array
     */
    protected $fixtureData;

    /**
     * @var \Cake\Datasource\ConnectionInterface
     */
    protected $connection;

    /**
     * setup
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->collection = $this->getCollectionLocator()->get('Articles');

        $this->fixtureData = [
            ['_id' => '000000000000000000000001', 'author_id' => '000000000000000000000001', 'title' => 'First Article', 'body' => 'First Article Body', 'published' => 'Y'],
            ['_id' => '000000000000000000000002', 'author_id' => '000000000000000000000003', 'title' => 'Second Article', 'body' => 'Second Article Body', 'published' => 'Y'],
            ['_id' => '000000000000000000000003', 'author_id' => '000000000000000000000001', 'title' => 'Third Article', 'body' => 'Third Article Body', 'published' => 'Y'],
        ];
    }

    /**
     * Test that result sets can be rewound and re-used.
     */
    public function testRewind(): void
    {
        $query = $this->collection->find('all');
        $results = $query->all();
        $first = [];
        $second = [];
        foreach ($results as $result) {
            $first[] = $result;
        }

        foreach ($results as $result) {
            $second[] = $result;
        }

        $this->assertEquals($first, $second);
    }

    /**
     * An integration test for testing serialize and unserialize features.
     *
     * Compare the results of a query with the results iterated, with
     * those of a different query that have been serialized/unserialized.
     */
    public function testSerialization(): void
    {
        $query = $this->collection->find('all');
        $results = $query->all();
        $expected = $results->toArray();

        $query2 = $this->collection->find('all');
        $results2 = $query2->all();
        $serialized = serialize($results2);
        $outcome = unserialize($serialized);
        $this->assertEquals($expected, $outcome->toArray());
    }

    /**
     * Test iteration after serialization
     */
    public function testIteratorAfterSerializationNoHydration(): void
    {
        $query = $this->collection->find('all')->hydrate(false);
        $results = unserialize(serialize($query->all()));

        // Use a loop to test Iterator implementation
        foreach ($results as $i => $row) {
            $this->assertEquals($this->fixtureData[$i], $row, "Row {$i} does not match");
        }
    }

    /**
     * Test iteration after serialization
     */
    public function testIteratorAfterSerializationHydrated(): void
    {
        $query = $this->collection->find('all');
        $results = unserialize(serialize($query->all()));

        // Use a loop to test Iterator implementation
        foreach ($results as $i => $row) {
            $expected = new Document($this->fixtureData[$i]);
            $expected->setNew(false);
            $expected->setSource($this->collection->getAlias());
            $expected->clean();
            $this->assertEquals($expected, $row, "Row {$i} does not match");
        }
    }

    /**
     * Test converting result sets into JSON
     */
    public function testJsonSerialize(): void
    {
        $query = $this->collection->find('all');
        $results = $query->all();

        $expected = json_encode($this->fixtureData);
        $this->assertEquals($expected, json_encode($results));
    }

    /**
     * Test first() method with a statement backed result set.
     */
    public function testFirst(): void
    {
        $query = $this->collection->find('all');
        $results = $query->hydrate(false)->all();

        $row = $results->first();
        $this->assertEquals($this->fixtureData[0], $row);

        $row = $results->first();
        $this->assertEquals($this->fixtureData[0], $row);
    }

    /**
     * Test first() method with a result set that has been unserialized
     */
    public function testFirstAfterSerialize(): void
    {
        $query = $this->collection->find('all');
        $results = $query->hydrate(false)->all();
        $results = unserialize(serialize($results));

        $row = $results->first();
        $this->assertEquals($this->fixtureData[0], $row);

        $this->assertSame($row, $results->first());
        $this->assertSame($row, $results->first());
    }

    /**
     * Test the countable interface.
     */
    public function testCount(): void
    {
        $query = $this->collection->find('all');
        $results = $query->all();

        $this->assertCount(3, $results, 'Should be countable and 3');
    }

    /**
     * Test the countable interface after unserialize
     */
    public function testCountAfterSerialize(): void
    {
        $query = $this->collection->find('all');
        $results = $query->all();
        $results = unserialize(serialize($results));

        $this->assertCount(3, $results, 'Should be countable and 3');
    }

    /**
     * Integration test to show methods from CollectionTrait work
     */
    public function testGroupBy(): void
    {
        // $this->markTestSkipped('Hydrated Documents keep canonical `_id` internally — cake `id`-only field shape diverges (F3).');
        $query = $this->collection->find('all');
        $results = $query->all()->groupBy('author_id')->toArray();
        $options = [
            'markNew' => false,
            'markClean' => true,
            'source' => $this->collection->getAlias(),
        ];

        $expected = [
            '000000000000000000000001' => [
                new Document($this->fixtureData[0], $options),
                new Document($this->fixtureData[2], $options),
            ],
            '000000000000000000000003' => [
                new Document($this->fixtureData[1], $options),
            ],
        ];
        $this->assertEquals($expected, $results);
    }

    /**
     * Tests __debugInfo
     */
    public function testDebugInfo(): void
    {
        $query = $this->collection->find('all');
        $results = $query->all();
        $expected = [
            'count' => 3,
            'items' => $results->toArray(),
        ];
        $this->assertSame($expected, $results->__debugInfo());
    }

    /**
     * Ensure that isEmpty() on a ResultSet doesn't result in loss
     * of records. This behavior is provided by CollectionTrait.
     */
    public function testIsEmptyDoesNotConsumeData(): void
    {
        $collection = $this->getCollectionLocator()->get('Comments');
        $query = $collection->find()
            ->formatResults(fn($results) => $results);
        $res = $query->all();
        $res->isEmpty();
        $this->assertCount(6, $res->toArray());
    }

    /**
     * Test that ResultSet
     */
    public function testCollectionMinAndMax(): void
    {
        $query = $this->collection->find('all');

        $min = $query->all()->min('_id');
        $minExpected = $this->collection->get('000000000000000000000001');

        $max = $query->all()->max('_id');
        $maxExpected = $this->collection->get('000000000000000000000003');

        $this->assertEquals($minExpected, $min);
        $this->assertEquals($maxExpected, $max);
    }

    /**
     * Test that ResultSet
     */
    public function testCollectionMinAndMaxWithAggregateField(): void
    {
        $this->markTestSkipped('SQL `COUNT(*)` aggregate — not applicable to Mongo (F19).');
        $query = $this->collection->find();
        $query->select([
            'counter' => 'COUNT(*)',
        ])->groupBy('author_id');

        $min = $query->all()->min('counter');
        $max = $query->all()->max('counter');

        $this->assertTrue($max > $min);
    }
}

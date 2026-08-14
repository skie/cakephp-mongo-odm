<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Migration\Db;

use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Database\Schema\SchemaManager;
use Crustum\Mongo\Migration\Db\Adapter\CakeMongoAdapter;
use Crustum\Mongo\Migration\Db\Adapter\RecordingAdapter;
use Crustum\Mongo\Migration\Db\Collection;
use Crustum\Mongo\Test\TestCase\Migration\Stub\FakeAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

/**
 * Tests the fluent Db\Collection migration builder.
 *
 * Unit sections use an in-memory fake adapter; create/update/drop run against
 * the real Mongo connection.
 */
#[CoversClass(Collection::class)]
class CollectionTest extends TestCase
{
    /**
     * @var \Crustum\Mongo\Database\Connection
     */
    protected Connection $connection;

    /**
     * @var \Crustum\Mongo\Database\Schema\SchemaManager
     */
    protected SchemaManager $manager;

    /**
     * @var \Crustum\Mongo\Migration\Db\Adapter\CakeMongoAdapter
     */
    protected CakeMongoAdapter $adapter;

    /**
     * Collections created during the test run.
     *
     * @var list<string>
     */
    protected array $created = [];

    /**
     * Set up before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = ConnectionManager::get('test_mongo');
        $this->manager = new SchemaManager($this->connection);
        $this->adapter = new CakeMongoAdapter($this->connection);
    }

    /**
     * Tear down after each test.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->created as $name) {
            if (in_array($name, $this->manager->listCollections(), true)) {
                $this->manager->dropCollection($name);
            }
        }
        parent::tearDown();
    }

    /**
     * Test getAdapter throws when no adapter was provided.
     *
     * @return void
     */
    public function testGetAdapterThrows(): void
    {
        $collection = new Collection('articles');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('There is no database adapter set yet, cannot proceed');

        $collection->getAdapter();
    }

    /**
     * Test hasPendingActions reflects declared actions.
     *
     * @return void
     */
    public function testHasPendingActions(): void
    {
        $collection = new Collection('articles', [], new FakeAdapter());

        $this->assertFalse($collection->hasPendingActions());

        $collection->addField('name', 'string');
        $this->assertTrue($collection->hasPendingActions());

        $collection->reset();
        $this->assertFalse($collection->hasPendingActions());

        $collection->addIndex(['email']);
        $this->assertTrue($collection->hasPendingActions());

        $collection->reset();
        $collection->setValidator(['$jsonSchema' => ['bsonType' => 'object', 'properties' => []]]);
        $this->assertTrue($collection->hasPendingActions());

        $collection->reset();
        $collection->drop();
        $this->assertTrue($collection->hasPendingActions());
    }

    /**
     * Test field bookkeeping.
     *
     * @return void
     */
    public function testFieldBookkeeping(): void
    {
        $collection = new Collection('articles', [], new FakeAdapter());

        $collection->addField('name', 'string');
        $collection->addColumn('author_id', 'objectid');
        $this->assertTrue($collection->hasField('name'));
        $this->assertTrue($collection->hasField('author_id'));
        $this->assertFalse($collection->hasField('nope'));

        $collection->removeField('name');
        $this->assertFalse($collection->hasField('name'));
    }

    /**
     * Test create() with fields emits a createCollection carrying a validator
     * built from the fields, plus index creation.
     *
     * @return void
     */
    public function testCreateWithFieldsAgainstFakeAdapter(): void
    {
        $fake = new FakeAdapter();
        $collection = new Collection('articles', [], $fake);
        $collection->addField('name', 'string');
        $collection->addField('author_id', 'objectid');
        $collection->addIndex(['author_id']);

        $collection->create();

        $this->assertArrayHasKey('articles', $fake->collections);
        $validator = $fake->collections['articles']['validator'];
        $this->assertSame('object', $validator['$jsonSchema']['bsonType']);
        $this->assertSame('string', $validator['$jsonSchema']['properties']['name']['bsonType']);
        $this->assertSame('objectId', $validator['$jsonSchema']['properties']['author_id']['bsonType']);
        $this->assertArrayHasKey('author_id_1', $fake->collections['articles']['indexes']);
    }

    /**
     * Test create() with an explicit validator option.
     *
     * @return void
     */
    public function testCreateWithExplicitValidatorAgainstFakeAdapter(): void
    {
        $fake = new FakeAdapter();
        $validator = ['$jsonSchema' => ['bsonType' => 'object', 'properties' => ['x' => ['bsonType' => 'int']]]];
        $collection = new Collection('articles', ['validator' => $validator], $fake);

        $collection->create();

        $this->assertSame($validator, $fake->collections['articles']['validator']);
    }

    /**
     * Test drop() against the fake adapter drops the collection.
     *
     * @return void
     */
    public function testDropAgainstFakeAdapter(): void
    {
        $fake = new FakeAdapter();
        $fake->createCollection('articles');

        $collection = new Collection('articles', [], $fake);
        $collection->drop();
        $collection->create();

        $this->assertArrayNotHasKey('articles', $fake->collections);
    }

    /**
     * Test create() round-trip against a real database.
     *
     * @return void
     */
    public function testCreateRoundTrip(): void
    {
        $name = 'mig_col_create';
        $this->created[] = $name;

        $collection = new Collection($name, [], $this->adapter);
        $collection->addField('name', 'string');
        $collection->addField('author_id', 'objectid');
        $collection->addIndex(['author_id']);
        $collection->create();

        $this->assertTrue($this->adapter->hasCollection($name));

        $validator = $this->manager->getValidator($name);
        $this->assertSame(
            'objectId',
            $validator['$jsonSchema']['properties']['author_id']['bsonType'],
        );

        $indexes = $this->manager->listIndexes($name);
        $this->assertArrayHasKey('author_id_1', $indexes);
    }

    /**
     * Test update() merges new fields into the existing validator.
     *
     * @return void
     */
    public function testUpdateMergesFields(): void
    {
        $name = 'mig_col_update';
        $this->created[] = $name;

        $collection = new Collection($name, [], $this->adapter);
        $collection->addField('name', 'string');
        $collection->create();

        $update = new Collection($name, [], $this->adapter);
        $update->addField('email', 'string');
        $update->update();

        $validator = $this->manager->getValidator($name);
        $properties = $validator['$jsonSchema']['properties'];
        $this->assertArrayHasKey('name', $properties);
        $this->assertArrayHasKey('email', $properties);
    }

    /**
     * Test drop() against a real database removes the collection.
     *
     * @return void
     */
    public function testDropRoundTrip(): void
    {
        $name = 'mig_col_drop';
        $this->created[] = $name;

        $collection = new Collection($name, [], $this->adapter);
        $collection->addField('name', 'string');
        $collection->create();
        $this->assertTrue($this->adapter->hasCollection($name));

        $drop = new Collection($name, [], $this->adapter);
        $drop->drop();
        $drop->create();

        $this->assertFalse($this->adapter->hasCollection($name));
    }

    /**
     * Test a create() recorded through the RecordingAdapter reverses to a drop.
     *
     * The plan records a `createCollection` command by intent (not by live
     * existence), so re-running the same create() in the down direction and
     * executing the inverted commands drops the collection — even though it
     * already exists.
     *
     * @return void
     */
    public function testCreateIsReversedThroughRecordingAdapter(): void
    {
        $name = 'mig_col_reverse';
        $this->created[] = $name;

        $collection = new Collection($name, [], $this->adapter);
        $collection->addField('name', 'string');
        $collection->create();
        $this->assertTrue($this->adapter->hasCollection($name));

        $recording = new RecordingAdapter($this->adapter);
        $replay = new Collection($name, [], $recording);
        $replay->addField('name', 'string');
        $replay->create();
        $recording->executeInvertedCommands();

        $this->assertFalse($this->adapter->hasCollection($name));
    }

    /**
     * Test a field removal on update is applied to the validator.
     *
     * @return void
     */
    public function testUpdateRemovesField(): void
    {
        $name = 'mig_col_remove_field';
        $this->created[] = $name;

        $collection = new Collection($name, [], $this->adapter);
        $collection->addField('name', 'string');
        $collection->addField('email', 'string');
        $collection->create();

        $update = new Collection($name, [], $this->adapter);
        $update->removeField('email');
        $update->update();

        $validator = $this->manager->getValidator($name);
        $properties = $validator['$jsonSchema']['properties'];
        $this->assertArrayHasKey('name', $properties);
        $this->assertArrayNotHasKey('email', $properties);
    }
}

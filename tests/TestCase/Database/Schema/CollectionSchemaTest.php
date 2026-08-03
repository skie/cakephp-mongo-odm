<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Schema;

use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Database\Schema\CollectionSchema;

/**
 * Test case for the CollectionSchema
 */
class CollectionSchemaTest extends TestCase
{
    /**
     * @var \Crustum\Mongo\Database\Connection
     */
    protected Connection $connection;

    /**
     * Set up before each test
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = ConnectionManager::get('test_mongo');
    }

    /**
     * Test the name()
     *
     * @return void
     */
    public function testName(): void
    {
        $collection = $this->connection->getCollection('test_users');
        $database = $this->connection->getDatabase();
        $schema = new CollectionSchema('test_users', $collection, $database);
        $this->assertSame('test_users', $schema->name());
    }

    /**
     * Test fields() with programmatically added fields
     *
     * @return void
     */
    public function testFields(): void
    {
        $mongoCollection = $this->connection->getCollection('test_users');
        $database = $this->connection->getDatabase();
        $schema = new CollectionSchema('test_users', $mongoCollection, $database);

        $schema->addField('user_id', ['type' => 'objectid']);
        $schema->addField('title', ['type' => 'string']);
        $schema->addField('body', ['type' => 'string']);

        $fields = $schema->fields();
        $this->assertContains('user_id', $fields);
        $this->assertContains('title', $fields);
        $this->assertContains('body', $fields);
    }

    /**
     * Test field() with programmatically added fields
     *
     * @return void
     */
    public function testField(): void
    {
        $mongoCollection = $this->connection->getCollection('test_users');
        $database = $this->connection->getDatabase();
        $schema = new CollectionSchema('test_users', $mongoCollection, $database);

        $schema->addField('user_id', ['type' => 'objectid']);
        $schema->addField('title', ['type' => 'string', 'comment' => 'Title field']);
        $schema->addField('body', ['type' => 'string']);

        $field = $schema->field('user_id');
        $this->assertNotNull($field);
        $this->assertSame('objectid', $field['type'] ?? null);

        $field = $schema->field('title');
        $this->assertNotNull($field);
        $this->assertSame('string', $field['type'] ?? null);
        $this->assertSame('Title field', $field['comment'] ?? null);

        $this->assertNull($schema->field('nope'));
    }

    /**
     * Test fieldType()
     *
     * @return void
     */
    public function testFieldType(): void
    {
        $mongoCollection = $this->connection->getCollection('test_users');
        $database = $this->connection->getDatabase();
        $schema = new CollectionSchema('test_users', $mongoCollection, $database);

        $schema->addField('user_id', ['type' => 'objectid']);
        $schema->addField('title', ['type' => 'string']);

        $this->assertSame('objectid', $schema->fieldType('user_id'));
        $this->assertSame('string', $schema->fieldType('title'));
        $this->assertNull($schema->fieldType('nope'));
    }

    /**
     * Test addField() method
     *
     * @return void
     */
    public function testAddField(): void
    {
        $mongoCollection = $this->connection->getCollection('test_users');
        $database = $this->connection->getDatabase();
        $schema = new CollectionSchema('test_users', $mongoCollection, $database);

        $result = $schema->addField('name', ['type' => 'string', 'length' => 255]);
        $this->assertSame($schema, $result);

        $field = $schema->getField('name');
        $this->assertNotNull($field);
        $this->assertSame('string', $field['type']);
        $this->assertSame(255, $field['length']);
    }

    /**
     * Test addField() with string type
     *
     * @return void
     */
    public function testAddFieldWithStringType(): void
    {
        $mongoCollection = $this->connection->getCollection('test_users');
        $database = $this->connection->getDatabase();
        $schema = new CollectionSchema('test_users', $mongoCollection, $database);

        $schema->addField('email', 'string');
        $field = $schema->getField('email');
        $this->assertNotNull($field);
        $this->assertSame('string', $field['type']);
    }

    /**
     * Test getField() method
     *
     * @return void
     */
    public function testGetField(): void
    {
        $mongoCollection = $this->connection->getCollection('test_users');
        $database = $this->connection->getDatabase();
        $schema = new CollectionSchema('test_users', $mongoCollection, $database);

        $schema->addField('name', ['type' => 'string']);
        $field = $schema->getField('name');
        $this->assertNotNull($field);
        $this->assertSame('string', $field['type']);

        $this->assertNull($schema->getField('nope'));
    }

    /**
     * Test hasField() method
     *
     * @return void
     */
    public function testHasField(): void
    {
        $mongoCollection = $this->connection->getCollection('test_users');
        $database = $this->connection->getDatabase();
        $schema = new CollectionSchema('test_users', $mongoCollection, $database);

        $schema->addField('name', ['type' => 'string']);
        $this->assertTrue($schema->hasField('name'));
        $this->assertFalse($schema->hasField('nope'));
    }

    /**
     * Test removeField() method
     *
     * @return void
     */
    public function testRemoveField(): void
    {
        $mongoCollection = $this->connection->getCollection('test_users');
        $database = $this->connection->getDatabase();
        $schema = new CollectionSchema('test_users', $mongoCollection, $database);

        $schema->addField('name', ['type' => 'string']);
        $this->assertTrue($schema->hasField('name'));

        $result = $schema->removeField('name');
        $this->assertSame($schema, $result);
        $this->assertFalse($schema->hasField('name'));
    }

    /**
     * Test setFieldType() method
     *
     * @return void
     */
    public function testSetFieldType(): void
    {
        $mongoCollection = $this->connection->getCollection('test_users');
        $database = $this->connection->getDatabase();
        $schema = new CollectionSchema('test_users', $mongoCollection, $database);

        $schema->addField('name', ['type' => 'string']);
        $result = $schema->setFieldType('name', 'text');
        $this->assertSame($schema, $result);

        $this->assertSame('text', $schema->getFieldType('name'));
    }

    /**
     * Test setFieldType() creates field if it doesn't exist
     *
     * @return void
     */
    public function testSetFieldTypeCreatesField(): void
    {
        $mongoCollection = $this->connection->getCollection('test_users');
        $database = $this->connection->getDatabase();
        $schema = new CollectionSchema('test_users', $mongoCollection, $database);

        $schema->setFieldType('new_field', 'integer');
        $this->assertTrue($schema->hasField('new_field'));
        $this->assertSame('integer', $schema->getFieldType('new_field'));
    }

    /**
     * Test typeMap() method
     *
     * @return void
     */
    public function testTypeMap(): void
    {
        $mongoCollection = $this->connection->getCollection('test_users');
        $database = $this->connection->getDatabase();
        $schema = new CollectionSchema('test_users', $mongoCollection, $database);

        $schema->addField('user_id', ['type' => 'objectid']);
        $schema->addField('name', ['type' => 'string']);
        $schema->addField('age', ['type' => 'integer']);

        $typeMap = $schema->typeMap();
        $this->assertSame('objectid', $typeMap['user_id']);
        $this->assertSame('string', $typeMap['name']);
        $this->assertSame('integer', $typeMap['age']);
    }

    /**
     * Test auto-inference of *_id fields as objectid
     *
     * @return void
     */
    public function testAutoInferObjectId(): void
    {
        $mongoCollection = $this->connection->getCollection('test_users');
        $database = $this->connection->getDatabase();
        $schema = new CollectionSchema('test_users', $mongoCollection, $database);

        $schema->addField('user_id', ['type' => 'string']);
        $schema->addField('post_id', ['type' => 'string']);

        $typeMap = $schema->typeMap();
        $this->assertSame('objectid', $typeMap['user_id']);
        $this->assertSame('objectid', $typeMap['post_id']);
    }

    /**
     * Test auto-inference of _id field as objectid
     *
     * @return void
     */
    public function testAutoInferPrimaryKey(): void
    {
        $mongoCollection = $this->connection->getCollection('test_users');
        $database = $this->connection->getDatabase();
        $schema = new CollectionSchema('test_users', $mongoCollection, $database);

        $schema->addField('_id', ['type' => 'string']);
        $schema->addField('id', ['type' => 'string']);

        $typeMap = $schema->typeMap();
        $this->assertSame('objectid', $typeMap['_id']);
        $this->assertSame('objectid', $typeMap['id']);
    }

    /**
     * Test indexes() method
     *
     * @return void
     */
    public function testIndexes(): void
    {
        $mongoCollection = $this->connection->getCollection('test_users');
        $database = $this->connection->getDatabase();
        $schema = new CollectionSchema('test_users', $mongoCollection, $database);

        $collectiones = $schema->indexes();
        $this->assertIsArray($collectiones);
    }

    /**
     * Test validationRules() method
     *
     * @return void
     */
    public function testValidationRules(): void
    {
        $mongoCollection = $this->connection->getCollection('test_users');
        $database = $this->connection->getDatabase();
        $schema = new CollectionSchema('test_users', $mongoCollection, $database);

        $rules = $schema->validationRules();
        $this->assertIsArray($rules);
    }
}

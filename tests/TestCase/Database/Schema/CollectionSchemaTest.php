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

        $schema->addField('user_id', []);
        $schema->addField('post_id', []);

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

        $schema->addField('_id', []);
        $schema->addField('id', []);

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

    /**
     * Test primaryKey() returns _id.
     *
     * @return void
     */
    public function testPrimaryKey(): void
    {
        $schema = new CollectionSchema('test_users');
        $this->assertSame('_id', $schema->primaryKey());
    }

    /**
     * Test automatic _id is exposed as objectid in the type map.
     *
     * @return void
     */
    public function testAutoIdInTypeMap(): void
    {
        $schema = new CollectionSchema('test_users');
        $typeMap = $schema->typeMap();
        $this->assertSame('objectid', $typeMap['_id']);
    }

    /**
     * Test an explicit _id field type wins over the inferred objectid.
     *
     * @return void
     */
    public function testExplicitIdTypeWins(): void
    {
        $schema = new CollectionSchema('test_users');
        $schema->addField('_id', ['type' => 'string']);

        $this->assertSame('string', $schema->fieldType('_id'));
        $this->assertSame('string', $schema->typeMap()['_id']);
    }

    /**
     * Test BSON type spelling is normalized to the canonical type name.
     *
     * @return void
     */
    public function testBsonTypeNormalization(): void
    {
        $schema = new CollectionSchema('test_users');
        $schema->addField('author_id', ['type' => 'string']);
        $schema->setValidationRules([
            '$jsonSchema' => [
                'bsonType' => 'object',
                'properties' => [
                    'object_id_field' => ['bsonType' => 'objectId'],
                    'long_field' => ['bsonType' => 'long'],
                    'double_field' => ['bsonType' => 'double'],
                    'decimal_field' => ['bsonType' => 'decimal'],
                    'binary_field' => ['bsonType' => 'binData'],
                ],
            ],
        ]);

        $this->assertSame('objectid', $schema->fieldType('object_id_field'));
        $this->assertSame('int64', $schema->fieldType('long_field'));
        $this->assertSame('float', $schema->fieldType('double_field'));
        $this->assertSame('decimal128', $schema->fieldType('decimal_field'));
        $this->assertSame('binary', $schema->fieldType('binary_field'));
    }

    /**
     * Test unknown BSON types remain nullable instead of being guessed.
     *
     * @return void
     */
    public function testUnknownBsonTypeIsNull(): void
    {
        $schema = new CollectionSchema('test_users');
        $schema->setValidationRules([
            '$jsonSchema' => [
                'bsonType' => 'object',
                'properties' => [
                    'weird_field' => ['bsonType' => 'unknownBsonType'],
                ],
            ],
        ]);

        $this->assertNull($schema->fieldType('weird_field'));
    }

    /**
     * Test dotted fieldType() resolves through nested object properties.
     *
     * @return void
     */
    public function testDottedFieldTypeThroughObjects(): void
    {
        $schema = new CollectionSchema('test_users');
        $schema->setValidationRules([
            '$jsonSchema' => [
                'bsonType' => 'object',
                'properties' => [
                    'address' => [
                        'bsonType' => 'object',
                        'properties' => [
                            'city' => ['bsonType' => 'string'],
                            'zip' => ['bsonType' => 'string'],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame('string', $schema->fieldType('address.city'));
        $this->assertSame('string', $schema->fieldType('address.zip'));
        $this->assertNull($schema->fieldType('address.missing'));
        $this->assertNull($schema->fieldType('missing.city'));
    }

    /**
     * Test dotted fieldType() resolves through arrays of objects.
     *
     * @return void
     */
    public function testDottedFieldTypeThroughArrays(): void
    {
        $schema = new CollectionSchema('test_users');
        $schema->setValidationRules([
            '$jsonSchema' => [
                'bsonType' => 'object',
                'properties' => [
                    'comments' => [
                        'bsonType' => 'array',
                        'items' => [
                            'bsonType' => 'object',
                            'properties' => [
                                'author_id' => ['bsonType' => 'objectId'],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame('objectid', $schema->fieldType('comments.author_id'));
        $this->assertNull($schema->fieldType('comments.missing'));
    }

    /**
     * Test dotted fieldType() returns null when traversal hits a scalar.
     *
     * @return void
     */
    public function testDottedFieldTypeScalarTraversalFails(): void
    {
        $schema = new CollectionSchema('test_users');
        $schema->setValidationRules([
            '$jsonSchema' => [
                'bsonType' => 'object',
                'properties' => [
                    'title' => ['bsonType' => 'string'],
                ],
            ],
        ]);

        $this->assertNull($schema->fieldType('title.something'));
    }

    /**
     * Test dotted field() returns the leaf definition with validation info.
     *
     * @return void
     */
    public function testDottedField(): void
    {
        $schema = new CollectionSchema('test_users');
        $schema->setValidationRules([
            '$jsonSchema' => [
                'bsonType' => 'object',
                'properties' => [
                    'address' => [
                        'bsonType' => 'object',
                        'properties' => [
                            'city' => ['bsonType' => 'string'],
                        ],
                    ],
                ],
            ],
        ]);

        $field = $schema->field('address.city');
        $this->assertNotNull($field);
        $this->assertSame('string', $field['validation']['bsonType']);

        $this->assertNull($schema->field('address.missing'));
    }

    /**
     * Test typeMap() includes validator-derived types normalized to canonical names.
     *
     * @return void
     */
    public function testTypeMapIncludesValidatorTypes(): void
    {
        $schema = new CollectionSchema('test_users');
        $schema->setValidationRules([
            '$jsonSchema' => [
                'bsonType' => 'object',
                'properties' => [
                    'object_id_field' => ['bsonType' => 'objectId'],
                    'title' => ['bsonType' => 'string'],
                ],
            ],
        ]);

        $typeMap = $schema->typeMap();
        $this->assertSame('objectid', $typeMap['object_id_field']);
        $this->assertSame('string', $typeMap['title']);
        $this->assertSame('objectid', $typeMap['_id']);
    }

    /**
     * Test a validator-declared _id keeps its explicit type.
     *
     * @return void
     */
    public function testExplicitIdValidatorPreserved(): void
    {
        $schema = new CollectionSchema('test_users');
        $schema->setValidationRules([
            '$jsonSchema' => [
                'bsonType' => 'object',
                'properties' => [
                    '_id' => ['bsonType' => 'string'],
                ],
            ],
        ]);

        $this->assertSame('string', $schema->typeMap()['_id']);
    }
}

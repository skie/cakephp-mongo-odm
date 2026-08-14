<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Migration\Util;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Migration\Util\ColumnParser;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests the Mongo ColumnParser.
 *
 * Adapted from cakephp/migrations `ColumnParserTest` for the crustum Mongo
 * output shape: `parseFields()` returns canonical Mongo type names
 * (`type`/`null`/`length`/`precision`/`default`/`unique`) instead of the SQL
 * `columnType`/`options` map.
 */
#[CoversClass(ColumnParser::class)]
class ColumnParserTest extends TestCase
{
    /**
     * @var \Crustum\Mongo\Migration\Util\ColumnParser
     */
    protected ColumnParser $columnParser;

    /**
     * Setup method.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->columnParser = new ColumnParser();
    }

    /**
     * Test parseFields maps names to canonical Mongo types.
     *
     * @return void
     */
    public function testParseFields(): void
    {
        $this->assertSame([
            'id' => [
                'type' => 'objectid',
                'null' => false,
            ],
        ], $this->columnParser->parseFields(['id']));

        $this->assertSame([
            'id' => [
                'type' => 'objectid',
                'null' => false,
            ],
            'name' => [
                'type' => 'string',
                'null' => false,
            ],
        ], $this->columnParser->parseFields(['id', 'name']));

        $this->assertSame([
            'name' => [
                'type' => 'string',
                'null' => false,
                'length' => 255,
            ],
            'description' => [
                'type' => 'string',
                'null' => true,
                'length' => 255,
            ],
            'age' => [
                'type' => 'integer',
                'null' => true,
                'length' => 11,
            ],
            'amount' => [
                'type' => 'decimal128',
                'null' => true,
                'precision' => 6,
                'scale' => 3,
            ],
        ], $this->columnParser->parseFields([
            'name:string',
            'description:string?',
            'age:integer?',
            'amount:decimal?[6,3]',
        ]));
    }

    /**
     * Test parseFields with explicit lengths.
     *
     * @return void
     */
    public function testParseFieldsWithLengths(): void
    {
        $this->assertSame([
            'name' => [
                'type' => 'string',
                'null' => false,
                'length' => 125,
            ],
            'description' => [
                'type' => 'string',
                'null' => true,
                'length' => 50,
            ],
        ], $this->columnParser->parseFields([
            'name:string[125]',
            'description:string?[50]',
        ]));
    }

    /**
     * Test parseFields with default values.
     *
     * @return void
     */
    public function testParseFieldsWithDefaultValues(): void
    {
        $this->assertSame([
            'active' => [
                'type' => 'boolean',
                'null' => false,
                'default' => true,
            ],
        ], $this->columnParser->parseFields(['active:boolean:default[true]']));

        $this->assertSame([
            'count' => [
                'type' => 'integer',
                'null' => false,
                'length' => 11,
                'default' => 0,
            ],
        ], $this->columnParser->parseFields(['count:integer:default[0]']));

        $this->assertSame([
            'status' => [
                'type' => 'string',
                'null' => false,
                'length' => 255,
                'default' => 'pending',
            ],
        ], $this->columnParser->parseFields(["status:string:default['pending']"]));

        $this->assertSame([
            'role' => [
                'type' => 'string',
                'null' => true,
                'length' => 255,
                'default' => 'user',
            ],
        ], $this->columnParser->parseFields(["role:string?:default['user']"]));
    }

    /**
     * Test parseFields with a unique index marker.
     *
     * @return void
     */
    public function testParseFieldsWithUniqueMarker(): void
    {
        $this->assertSame([
            'email' => [
                'type' => 'string',
                'null' => false,
                'length' => 255,
                'default' => null,
                'unique' => true,
            ],
        ], $this->columnParser->parseFields(['email:string:default[null]:unique']));
    }

    /**
     * Test parseIndexes emits unique indexes.
     *
     * @return void
     */
    public function testParseIndexes(): void
    {
        $this->assertSame([
            'unique_id' => [
                'key' => ['id' => 1],
                'unique' => true,
            ],
        ], $this->columnParser->parseIndexes(['id:integer:unique']));

        $this->assertSame([
            'unique_email' => [
                'key' => ['email' => 1],
                'unique' => true,
            ],
        ], $this->columnParser->parseIndexes(['email:string:unique:UNIQUE_USER']));
    }

    /**
     * Test parseIndexes skips non-unique index types.
     *
     * @return void
     */
    public function testParseIndexesSkipsNonUnique(): void
    {
        $this->assertSame([], $this->columnParser->parseIndexes(['count:integer:default[0]:index:IDX_COUNT']));
        $this->assertSame([], $this->columnParser->parseIndexes(['name:string']));
    }

    /**
     * Test validArguments filters malformed columns.
     *
     * @return void
     */
    public function testValidArguments(): void
    {
        $this->assertSame(['id'], $this->columnParser->validArguments(['id']));
        $this->assertSame(['id', 'field:string:unique'], $this->columnParser->validArguments(['id', 'field:string:unique']));
        $this->assertSame(['id'], $this->columnParser->validArguments(['not valid', 'id']));
        $this->assertSame([], $this->columnParser->validArguments(['']));
    }

    /**
     * Test getType resolves the abstract type for a field.
     *
     * @return void
     */
    public function testGetType(): void
    {
        $this->assertSame('objectid', $this->columnParser->getType('id', null));
        $this->assertSame('objectid', $this->columnParser->getType('author_id', null));
        $this->assertSame('datetime', $this->columnParser->getType('created', null));
        $this->assertSame('datetime', $this->columnParser->getType('modified', null));
        $this->assertSame('decimal', $this->columnParser->getType('latitude', null));
        $this->assertSame('string', $this->columnParser->getType('some_field', null));
        $this->assertSame('string', $this->columnParser->getType('name', 'string'));
        $this->assertSame('boolean', $this->columnParser->getType('field', 'boolean'));
        $this->assertSame('uuid', $this->columnParser->getType('id', 'uuid'));
    }

    /**
     * Test getTypeAndLength.
     *
     * @return void
     */
    public function testGetTypeAndLength(): void
    {
        $this->assertSame(['string', 255], $this->columnParser->getTypeAndLength('name', 'string'));
        $this->assertSame(['string', 128], $this->columnParser->getTypeAndLength('name', 'string[128]'));
        $this->assertSame(['integer', 9], $this->columnParser->getTypeAndLength('counter', 'integer[9]'));
        $this->assertSame(['biginteger', 18], $this->columnParser->getTypeAndLength('bigcounter', 'biginteger[18]'));
        $this->assertSame(['objectid', null], $this->columnParser->getTypeAndLength('id', null));
        $this->assertSame(['string', null], $this->columnParser->getTypeAndLength('username', null));
        $this->assertSame(['datetime', null], $this->columnParser->getTypeAndLength('created', null));
        $this->assertSame(['decimal', [10, 6]], $this->columnParser->getTypeAndLength('latitude', 'decimal[10,6]'));
    }

    /**
     * Test getLength.
     *
     * @return void
     */
    public function testGetLength(): void
    {
        $this->assertSame(255, $this->columnParser->getLength('string'));
        $this->assertSame(11, $this->columnParser->getLength('integer'));
        $this->assertSame(4, $this->columnParser->getLength('tinyinteger'));
        $this->assertSame(6, $this->columnParser->getLength('smallinteger'));
        $this->assertSame(20, $this->columnParser->getLength('biginteger'));
        $this->assertSame([10, 6], $this->columnParser->getLength('decimal'));
        $this->assertNull($this->columnParser->getLength('text'));
        $this->assertNull($this->columnParser->getLength('boolean'));
    }

    /**
     * Test mapType maps abstract types to canonical Mongo types.
     *
     * @return void
     */
    public function testMapType(): void
    {
        $this->assertSame('string', $this->columnParser->mapType(null));
        $this->assertSame('objectid', $this->columnParser->mapType('objectid'));
        $this->assertSame('objectid', $this->columnParser->mapType('id'));
        $this->assertSame('integer', $this->columnParser->mapType('integer'));
        $this->assertSame('int', $this->columnParser->mapType('tinyinteger'));
        $this->assertSame('int64', $this->columnParser->mapType('biginteger'));
        $this->assertSame('float', $this->columnParser->mapType('float'));
        $this->assertSame('decimal128', $this->columnParser->mapType('decimal'));
        $this->assertSame('decimal128', $this->columnParser->mapType('decimal128'));
        $this->assertSame('boolean', $this->columnParser->mapType('boolean'));
        $this->assertSame('date', $this->columnParser->mapType('datetime'));
        $this->assertSame('timestamp', $this->columnParser->mapType('timestamp'));
        $this->assertSame('binary', $this->columnParser->mapType('binary'));
        $this->assertSame('hash', $this->columnParser->mapType('json'));
        $this->assertSame('array', $this->columnParser->mapType('array'));
        $this->assertSame('collection', $this->columnParser->mapType('collection'));
        $this->assertSame('string', $this->columnParser->mapType('unknown_type'));
    }

    /**
     * Test parseDefaultValue.
     *
     * @return void
     */
    public function testParseDefaultValue(): void
    {
        $this->assertNull($this->columnParser->parseDefaultValue('null', 'string'));
        $this->assertNull($this->columnParser->parseDefaultValue('NULL', 'string'));
        $this->assertTrue($this->columnParser->parseDefaultValue('true', 'boolean'));
        $this->assertTrue($this->columnParser->parseDefaultValue('TRUE', 'boolean'));
        $this->assertFalse($this->columnParser->parseDefaultValue('false', 'boolean'));
        $this->assertSame(0, $this->columnParser->parseDefaultValue('0', 'integer'));
        $this->assertSame(123, $this->columnParser->parseDefaultValue('123', 'integer'));
        $this->assertSame(-456, $this->columnParser->parseDefaultValue('-456', 'integer'));
        $this->assertSame(1.5, $this->columnParser->parseDefaultValue('1.5', 'decimal'));
        $this->assertSame(-2.75, $this->columnParser->parseDefaultValue('-2.75', 'decimal'));
        $this->assertSame('hello', $this->columnParser->parseDefaultValue("'hello'", 'string'));
        $this->assertSame('world', $this->columnParser->parseDefaultValue('"world"', 'string'));
        $this->assertSame('CURRENT_TIMESTAMP', $this->columnParser->parseDefaultValue('CURRENT_TIMESTAMP', 'datetime'));
    }

    /**
     * Test collectionFor infers the collection name from a field.
     *
     * @return void
     */
    public function testCollectionFor(): void
    {
        $this->assertSame('authors', $this->columnParser->collectionFor('author_id'));
        $this->assertSame('users', $this->columnParser->collectionFor('user_id'));
        $this->assertSame('categories', $this->columnParser->collectionFor('category_id'));
        $this->assertSame('names', $this->columnParser->collectionFor('name'));
    }
}

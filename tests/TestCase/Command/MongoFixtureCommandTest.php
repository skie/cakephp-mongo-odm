<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Command;

use Crustum\Mongo\Command\Bake\MongoFixtureCommand;
use Crustum\Mongo\Database\Schema\CollectionSchema;
use MongoDB\BSON\Binary;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use ReflectionClass;

/**
 * MongoFixtureCommandTest class
 *
 * Port of `Bake\Test\TestCase\Command\FixtureCommandTest` for the Mongo ODM.
 *
 * Only pure method tests are used here: `MongoFixtureCommand::getPath()`
 * resolves to the plugin's real `tests/Fixture/` directory, so integration
 * baking would write into the plugin's own test tree (the reference avoids
 * this by pointing ROOT at a throwaway test app). Sample-value generation is
 * exercised without touching the filesystem.
 */
class MongoFixtureCommandTest extends TestCase
{
    /**
     * Test the sample value generation for each Mongo type.
     *
     * @return void
     */
    public function testSampleValue(): void
    {
        $command = new MongoFixtureCommand();
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('sampleValue');

        $this->assertInstanceOf(ObjectId::class, $method->invoke($command, 'objectid'));
        $this->assertSame(1, $method->invoke($command, 'integer'));
        $this->assertSame(1, $method->invoke($command, 'int64'));
        $this->assertSame(1.5, $method->invoke($command, 'float'));
        $this->assertSame(1.5, $method->invoke($command, 'decimal128'));
        $this->assertTrue($method->invoke($command, 'boolean'));
        $this->assertInstanceOf(UTCDateTime::class, $method->invoke($command, 'date'));
        $this->assertInstanceOf(UTCDateTime::class, $method->invoke($command, 'datetime'));
        $this->assertInstanceOf(UTCDateTime::class, $method->invoke($command, 'timestamp'));
        $this->assertSame([], $method->invoke($command, 'array'));
        $this->assertSame([], $method->invoke($command, 'collection'));
        $this->assertSame([], $method->invoke($command, 'hash'));
        $this->assertInstanceOf(Binary::class, $method->invoke($command, 'binary'));
        $this->assertSame('Sample data', $method->invoke($command, 'unknown'));
    }

    /**
     * Test that sample records skip the `_id` field and only include schema
     * columns.
     *
     * @return void
     */
    public function testSampleRecords(): void
    {
        $command = new MongoFixtureCommand();
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('sampleRecords');

        $schema = new CollectionSchema('test_users');
        $schema->addField('_id', ['type' => 'objectid']);
        $schema->addField('username', ['type' => 'string']);
        $schema->addField('active', ['type' => 'boolean']);

        $records = $method->invoke($command, $schema);
        $this->assertCount(1, $records);
        $this->assertArrayNotHasKey('_id', $records[0]);
        $this->assertSame('Sample data', $records[0]['username']);
        $this->assertTrue($records[0]['active']);
    }

    /**
     * Test that an empty schema produces no records.
     *
     * @return void
     */
    public function testSampleRecordsEmptySchema(): void
    {
        $command = new MongoFixtureCommand();
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('sampleRecords');

        $schema = new CollectionSchema('empty');
        $schema->addField('_id', ['type' => 'objectid']);

        $this->assertSame([], $method->invoke($command, $schema));
    }

    /**
     * Test that the canonical type mapping normalizes raw bson spellings.
     *
     * @return void
     */
    public function testCanonicalType(): void
    {
        $command = new MongoFixtureCommand();
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('canonicalType');

        $this->assertSame('integer', $method->invoke($command, 'int'));
        $this->assertSame('boolean', $method->invoke($command, 'bool'));
        $this->assertSame('objectid', $method->invoke($command, 'objectId'));
        $this->assertSame('int64', $method->invoke($command, 'long'));
        $this->assertSame('float', $method->invoke($command, 'double'));
        $this->assertSame('decimal128', $method->invoke($command, 'decimal'));
        $this->assertSame('binary', $method->invoke($command, 'binData'));
        $this->assertSame('hash', $method->invoke($command, 'object'));
        $this->assertSame('collection', $method->invoke($command, 'array'));
        $this->assertSame('string', $method->invoke($command, 'string'));
    }
}

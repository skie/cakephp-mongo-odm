<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Log;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Log\MongoLogger;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Unit tests for MongoLogger database scoping.
 */
#[CoversClass(MongoLogger::class)]
class MongoLoggerTest extends TestCase
{
    /**
     * Test unscoped loggers accept every database.
     *
     * @return void
     */
    public function testUnscopedAcceptsAnyDatabase(): void
    {
        $logger = new MongoLogger(new MemoryLogger());

        $this->assertTrue($logger->acceptsDatabase('mongo_demo'));
        $this->assertTrue($logger->acceptsDatabase('mongo_demo_test'));
        $this->assertNull($logger->getDatabase());
    }

    /**
     * Test scoped loggers ignore other databases.
     *
     * @return void
     */
    public function testScopedLoggerIgnoresOtherDatabase(): void
    {
        $inner = new MemoryLogger();
        $logger = new MongoLogger($inner, ['database' => 'mongo_demo']);

        $logger->log('debug', 'skip', [
            'command' => ['find' => 'tags'],
            'database' => 'mongo_demo_test',
            'duration_ms' => 1.0,
            'numReturn' => 0,
        ]);
        $this->assertCount(0, $inner->records);

        $logger->log('debug', 'keep', [
            'command' => ['find' => 'tags'],
            'database' => 'mongo_demo',
            'collection' => 'tags',
            'duration_ms' => 1.0,
            'numReturn' => 1,
        ]);
        $this->assertCount(1, $inner->records);
        $this->assertSame('mongo_demo', $logger->getDatabase());
    }

    /**
     * Test schema/reflection commands are skipped by default.
     *
     * @return void
     */
    public function testSchemaCommandsAreSkippedByDefault(): void
    {
        $inner = new MemoryLogger();
        $logger = new MongoLogger($inner, ['database' => 'mongo_demo']);

        $logger->log('debug', 'schema', [
            'command' => ['listCollections' => 1, 'filter' => ['name' => 'tags']],
            'database' => 'mongo_demo',
            'duration_ms' => 0.2,
            'numReturn' => 1,
        ]);
        $this->assertCount(0, $inner->records);

        $withSchema = new MongoLogger(new MemoryLogger(), [
            'database' => 'mongo_demo',
            'includeSchemaCommands' => true,
        ]);
        $this->assertTrue($withSchema->isSchemaCommand(['listIndexes' => 'tags']));
    }

    /**
     * Test aggregate commands log the full pipeline, not an empty filter.
     *
     * @return void
     */
    public function testAggregateLogsPipeline(): void
    {
        $inner = new MemoryLogger();
        $logger = new MongoLogger($inner, ['database' => 'mongo_demo']);

        $pipeline = [
            ['$match' => ['_id' => ['$oid' => '6a83402dffa5eb770505fa77']]],
            ['$lookup' => ['from' => 'profiles', 'as' => 'profile']],
        ];

        $logger->log('debug', 'aggregate', [
            'command' => [
                'aggregate' => 'users',
                'pipeline' => $pipeline,
                'cursor' => [],
                '$db' => 'mongo_demo',
                'lsid' => ['id' => ['$binary' => 'abc', '$type' => '04']],
            ],
            'database' => 'mongo_demo',
            'collection' => 'users',
            'duration_ms' => 0.688,
            'numReturn' => 1,
        ]);

        $this->assertCount(1, $inner->records);
        $payload = json_decode($inner->records[0][1], true);
        $this->assertSame('aggregate', $payload['operation']);
        $this->assertSame('users', $payload['collection']);
        $this->assertSame('users', $payload['aggregate']);
        $this->assertSame($pipeline, $payload['pipeline']);
        $this->assertArrayNotHasKey('$db', $payload);
        $this->assertArrayNotHasKey('lsid', $payload);
        $this->assertArrayNotHasKey('filter', $payload);
    }

    /** 
     * Test find commands keep filter and drop driver metadata.
     *
     * @return void
     */
    public function testFindLogsFilter(): void
    {
        $inner = new MemoryLogger();
        $logger = new MongoLogger($inner, ['database' => 'mongo_demo']);

        $filter = ['user_id' => ['$in' => [['$oid' => '6a83402dffa5eb770505fa77']]]];

        $logger->log('debug', 'find', [
            'command' => [
                'find' => 'profiles',
                'filter' => $filter,
                '$db' => 'mongo_demo',
            ],
            'database' => 'mongo_demo',
            'collection' => 'profiles',
            'duration_ms' => 0.5,
            'numReturn' => 1,
        ]);

        $payload = json_decode($inner->records[0][1], true);
        $this->assertSame('find', $payload['operation']);
        $this->assertSame('profiles', $payload['find']);
        $this->assertSame($filter, $payload['filter']);
    }
}

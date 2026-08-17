<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Database\Log;

use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Database\Log\CommandSubscriber;
use Crustum\Mongo\Database\Log\MongoLogger;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for CommandSubscriber against a real Mongo connection.
 */
#[CoversClass(CommandSubscriber::class)]
class CommandSubscriberTest extends TestCase
{
    /**
     * @var \Crustum\Mongo\Database\Connection
     */
    protected Connection $connection;

    /**
     * @var \Crustum\Mongo\Test\TestCase\Database\Log\MemoryLogger
     */
    protected MemoryLogger $inner;

    /**
     * @var \Crustum\Mongo\Database\Log\MongoLogger
     */
    protected MongoLogger $mongoLogger;

    /**
     * @var \Crustum\Mongo\Database\Log\CommandSubscriber
     */
    protected CommandSubscriber $subscriber;

    /**
     * Set up before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        CommandSubscriber::resetMonitor();
        $this->connection = ConnectionManager::get('test_mongo');
        $this->inner = new MemoryLogger();
        $this->mongoLogger = new MongoLogger($this->inner);
        $this->subscriber = CommandSubscriber::attach($this->mongoLogger);
    }

    /**
     * Tear down after each test.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        CommandSubscriber::resetMonitor();
        parent::tearDown();
    }

    /**
     * Test a find command is logged with a numReturn of the matched rows.
     *
     * @return void
     */
    public function testFindCommandIsLogged(): void
    {
        $collection = $this->connection->getDatabase()->selectCollection('log_find_test');
        $collection->drop();
        $collection->insertMany([
            ['title' => 'one'],
            ['title' => 'two'],
        ]);

        $this->inner->records = [];
        $collection->find(['title' => 'one'])->toArray();

        $record = $this->assertLoggedCommand('find');
        $this->assertSame('log_find_test', $record['context']['collection']);
        $this->assertSame(1, $record['context']['numReturn']);
        $this->assertGreaterThanOrEqual(0, $record['context']['duration_ms']);
    }

    /**
     * Test an insert command is logged with n = 1.
     *
     * @return void
     */
    public function testInsertCommandIsLogged(): void
    {
        $collection = $this->connection->getDatabase()->selectCollection('log_insert_test');
        $collection->drop();

        $this->inner->records = [];
        $collection->insertOne(['title' => 'three']);

        $record = $this->assertLoggedCommand('insert');
        $this->assertSame('log_insert_test', $record['context']['collection']);
        $this->assertSame(1, $record['context']['numReturn']);
    }

    /**
     * Test commands are not logged while the subscriber is disabled.
     *
     * @return void
     */
    public function testDisabledSubscriberLogsNothing(): void
    {
        CommandSubscriber::detach($this->mongoLogger);
        $this->assertFalse($this->subscriber->isEnabled());

        $collection = $this->connection->getDatabase()->selectCollection('log_disabled_test');
        $collection->drop();
        $collection->insertOne(['title' => 'four']);

        $this->assertCount(0, $this->inner->records);
    }

    /**
     * Test repeated enable() does not register the subscriber twice.
     *
     * @return void
     */
    public function testEnableIsIdempotent(): void
    {
        $collection = $this->connection->getDatabase()->selectCollection('log_enable_once_test');
        $collection->drop();

        $this->subscriber->enable();
        $this->subscriber->enable();
        $this->assertTrue($this->subscriber->isEnabled());

        $this->inner->records = [];
        $collection->insertOne(['title' => 'once']);

        $inserts = [];
        foreach ($this->inner->records as $record) {
            $message = $record[2];
            if (is_array($message) && ($message['command']['insert'] ?? null) !== null) {
                $inserts[] = $record;
            }
        }

        $this->assertCount(1, $inserts);
    }

    /**
     * Test two drivers share one process-wide subscriber.
     *
     * @return void
     */
    public function testAttachSharesSingleMonitor(): void
    {
        $secondInner = new MemoryLogger();
        $secondLogger = new MongoLogger($secondInner);
        $second = CommandSubscriber::attach($secondLogger);

        $this->assertSame($this->subscriber, $second);
        $this->assertSame(2, $this->subscriber->loggerCount());

        CommandSubscriber::detach($secondLogger);
        $this->assertSame(1, $this->subscriber->loggerCount());
        $this->assertTrue($this->subscriber->isEnabled());
    }

    /**
     * Test each logger only records commands for its configured database.
     *
     * @return void
     */
    public function testLoggersFilterByDatabase(): void
    {
        CommandSubscriber::resetMonitor();

        $database = (string)$this->connection->config()['database'];
        $matchedInner = new MemoryLogger();
        $otherInner = new MemoryLogger();
        CommandSubscriber::attach(new MongoLogger($matchedInner, ['database' => $database]));
        CommandSubscriber::attach(new MongoLogger($otherInner, ['database' => $database . '_other']));

        $collection = $this->connection->getDatabase()->selectCollection('log_db_filter_test');
        $collection->drop();
        $matchedInner->records = [];
        $otherInner->records = [];
        $collection->insertOne(['title' => 'scoped']);

        $this->assertNotEmpty($matchedInner->records);
        $this->assertCount(0, $otherInner->records);
    }

    /**
     * Asserts a logged record for the given operation and returns it.
     *
     * @param string $operation The expected operation name.
     * @return array{level: string, message: string, context: array<string, mixed>}
     */
    protected function assertLoggedCommand(string $operation): array
    {
        $this->assertNotEmpty($this->inner->records);

        $matching = [];
        foreach ($this->inner->records as $record) {
            $message = $record[2];
            if (is_array($message) && ($message['command'][$operation] ?? null) !== null) {
                $matching[] = $record;
            }
        }

        $this->assertNotEmpty($matching, "No logged record for {$operation}");

        return [
            'level' => $matching[0][0],
            'message' => $matching[0][1],
            'context' => $matching[0][2],
        ];
    }
}

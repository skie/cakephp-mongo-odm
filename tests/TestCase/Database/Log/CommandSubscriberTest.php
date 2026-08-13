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
        $this->connection = ConnectionManager::get('test_mongo');
        $this->inner = new MemoryLogger();
        $this->subscriber = new CommandSubscriber(new MongoLogger($this->inner));
    }

    /**
     * Tear down after each test.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->subscriber->disable();
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

        $this->subscriber->enable();
        $collection->find(['title' => 'one'])->toArray();
        $this->subscriber->disable();

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

        $this->subscriber->enable();
        $collection->insertOne(['title' => 'three']);
        $this->subscriber->disable();

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
        $collection = $this->connection->getDatabase()->selectCollection('log_disabled_test');
        $collection->drop();

        $collection->insertOne(['title' => 'four']);

        $this->assertCount(0, $this->inner->records);
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

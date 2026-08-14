<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Migration\Command;

use Cake\Console\ConsoleIo;
use Cake\Console\TestSuite\StubConsoleInput;
use Cake\Console\TestSuite\StubConsoleOutput;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Migration\Command\UpgradeCommand;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests the `mongo migrations upgrade` command.
 */
#[CoversClass(UpgradeCommand::class)]
class UpgradeCommandTest extends TestCase
{
    /**
     * @var \Crustum\Mongo\Database\Connection
     */
    protected Connection $connection;

    /**
     * Set up before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = ConnectionManager::get('test_mongo');
        $this->connection->getCollection('_migrations')->deleteMany([]);
    }

    /**
     * Tear down after each test.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->connection->getCollection('_migrations')->deleteMany([]);
        parent::tearDown();
    }

    /**
     * Runs the command with the given argv and returns the plain output.
     *
     * @param array<int, string> $argv Command line arguments
     * @return string
     */
    protected function runCommand(array $argv): string
    {
        $command = new UpgradeCommand();
        $out = new StubConsoleOutput();
        $out->setOutputAs(StubConsoleOutput::PLAIN);
        $io = new ConsoleIo($out, $out, new StubConsoleInput([]));

        $command->run($argv, $io);

        return implode("\n", $out->messages());
    }

    /**
     * Test upgrade backfills the plugin field and ensures the unique index.
     *
     * @return void
     */
    public function testUpgradeBackfillsPluginAndEnsuresIndex(): void
    {
        $this->connection->getCollection('_migrations')->insertOne([
            'version' => 20260811000000,
            'migration_name' => 'LegacyEntry',
        ]);

        $output = $this->runCommand(['--connection', 'test_mongo']);

        $this->assertStringContainsString('Journal upgraded', $output);

        $entry = $this->connection->getCollection('_migrations')->findOne(['version' => 20260811000000]);
        $this->assertArrayHasKey('plugin', $entry);
        $this->assertNull($entry['plugin']);

        $hasUnique = false;
        foreach ($this->connection->getCollection('_migrations')->listIndexes() as $index) {
            $key = $index->getKey();
            if (($key['version'] ?? null) === 1 && ($key['plugin'] ?? null) === 1 && $index->isUnique()) {
                $hasUnique = true;
                break;
            }
        }
        $this->assertTrue($hasUnique);
    }

    /**
     * Test dry-run reports without changing the journal.
     *
     * @return void
     */
    public function testUpgradeDryRun(): void
    {
        $this->connection->getCollection('_migrations')->insertOne([
            'version' => 20260811000000,
            'migration_name' => 'LegacyEntry',
        ]);

        $output = $this->runCommand(['--connection', 'test_mongo', '--dry-run']);

        $this->assertStringContainsString('DRY RUN', $output);

        $entry = $this->connection->getCollection('_migrations')->findOne(['version' => 20260811000000]);
        $this->assertArrayNotHasKey('plugin', $entry);
    }
}

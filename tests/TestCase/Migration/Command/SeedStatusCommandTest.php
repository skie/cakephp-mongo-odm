<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Migration\Command;

use Cake\Console\ConsoleIo;
use Cake\Console\TestSuite\StubConsoleInput;
use Cake\Console\TestSuite\StubConsoleOutput;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Migration\Command\SeedStatusCommand;
use Crustum\Mongo\Migration\Migrations;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests the `mongo migrations seed_status` command.
 */
#[CoversClass(SeedStatusCommand::class)]
class SeedStatusCommandTest extends TestCase
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
        $this->connection->getCollection('_seeds')->deleteMany([]);
        $this->connection->getDatabase()->dropCollection('mig_seed_users');
        $this->connection->getDatabase()->dropCollection('mig_seed_posts');
    }

    /**
     * Tear down after each test.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->connection->getCollection('_seeds')->deleteMany([]);
        $this->connection->getDatabase()->dropCollection('mig_seed_users');
        $this->connection->getDatabase()->dropCollection('mig_seed_posts');
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
        $command = new SeedStatusCommand();
        $out = new StubConsoleOutput();
        $out->setOutputAs(StubConsoleOutput::PLAIN);
        $io = new ConsoleIo($out, $out, new StubConsoleInput([]));

        $command->run($argv, $io);

        return implode("\n", $out->messages());
    }

    /**
     * Seeds a named seed through the facade.
     *
     * @param string $seed Seed name
     * @return void
     */
    protected function seed(string $seed): void
    {
        $seeds = new Migrations(['connection' => 'test_mongo']);
        $seeds->seed(['seed' => $seed, 'source' => 'ManagerSeeds', 'force' => true]);
    }

    /**
     * Test status reports seeds as pending before execution.
     *
     * @return void
     */
    public function testStatusPending(): void
    {
        $output = $this->runCommand(['--connection', 'test_mongo', '--source', 'ManagerSeeds']);

        $this->assertStringContainsString('UserSeeder seed', $output);
        $this->assertStringContainsString('pending', $output);
    }

    /**
     * Test status reports executed seeds with their timestamp.
     *
     * @return void
     */
    public function testStatusExecuted(): void
    {
        $this->seed('UserSeeder');

        $output = $this->runCommand(['--connection', 'test_mongo', '--source', 'ManagerSeeds']);

        $this->assertStringContainsString('UserSeeder seed', $output);
        $this->assertStringContainsString('executed', $output);
    }

    /**
     * Test json format returns machine-readable status.
     *
     * @return void
     */
    public function testStatusJson(): void
    {
        $output = $this->runCommand(['--connection', 'test_mongo', '--source', 'ManagerSeeds', '--format', 'json']);

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $names = array_column($decoded, 'seedName');
        $this->assertContains('UserSeeder seed', $names);
        $this->assertContains('pending', array_column($decoded, 'status'));
    }
}

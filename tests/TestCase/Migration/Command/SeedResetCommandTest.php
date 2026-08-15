<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Migration\Command;

use Cake\Console\ConsoleIo;
use Cake\Console\TestSuite\StubConsoleInput;
use Cake\Console\TestSuite\StubConsoleOutput;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Migration\Command\SeedResetCommand;
use Crustum\Mongo\Migration\Migrations;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests the `mongo migrations seed_reset` command.
 */
#[CoversClass(SeedResetCommand::class)]
class SeedResetCommandTest extends TestCase
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
     * @param array<int, string> $input Console input responses
     * @return string
     */
    protected function runCommand(array $argv, array $input = []): string
    {
        $command = new SeedResetCommand();
        $out = new StubConsoleOutput();
        $out->setOutputAs(StubConsoleOutput::PLAIN);

        $io = new ConsoleIo($out, $out, new StubConsoleInput($input));

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
     * Test reset removes executed seed records after confirmation.
     *
     * @return void
     */
    public function testResetRemovesSeedRecords(): void
    {
        $this->seed('UserSeeder');
        $this->assertSame(1, $this->connection->getCollection('_seeds')->countDocuments());

        $output = $this->runCommand(
            ['--connection', 'test_mongo', '--source', 'ManagerSeeds'],
            ['y'],
        );

        $this->assertStringContainsString('Reset 1 seed(s).', $output);
        $this->assertSame(0, $this->connection->getCollection('_seeds')->countDocuments());
    }

    /**
     * Test reset only touches the requested seed.
     *
     * @return void
     */
    public function testResetSpecificSeed(): void
    {
        $this->seed('UserSeeder');
        $this->seed('PostSeeder');

        $this->runCommand(
            ['--connection', 'test_mongo', '--source', 'ManagerSeeds', '--seed', 'UserSeeder'],
            ['y'],
        );

        $names = array_column(
            iterator_to_array($this->connection->getCollection('_seeds')->find(), false),
            'seed_name',
        );
        $this->assertNotContains('UserSeeder', $names);
        $this->assertContains('PostSeeder', $names);
    }

    /**
     * Test dry-run does not remove records.
     *
     * @return void
     */
    public function testResetDryRun(): void
    {
        $this->seed('UserSeeder');

        $output = $this->runCommand(['--connection', 'test_mongo', '--source', 'ManagerSeeds', '--dry-run']);

        $this->assertStringContainsString('DRY-RUN: Would reset 1 seed(s).', $output);
        $this->assertSame(1, $this->connection->getCollection('_seeds')->countDocuments());
    }

    /**
     * Test aborting the confirmation keeps the records.
     *
     * @return void
     */
    public function testResetAborted(): void
    {
        $this->seed('UserSeeder');

        $output = $this->runCommand(
            ['--connection', 'test_mongo', '--source', 'ManagerSeeds'],
            ['n'],
        );

        $this->assertStringContainsString('Reset operation aborted.', $output);
        $this->assertSame(1, $this->connection->getCollection('_seeds')->countDocuments());
    }
}

<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Migration\Command;

use Cake\Console\ConsoleIo;
use Cake\Console\TestSuite\StubConsoleInput;
use Cake\Console\TestSuite\StubConsoleOutput;
use Cake\TestSuite\TestCase;
use Crustum\Mongo\Migration\Command\BakeSimpleMigrationCommand;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests the `bake mongo_migration_simple` command.
 */
#[CoversClass(BakeSimpleMigrationCommand::class)]
class BakeSimpleMigrationCommandTest extends TestCase
{
    /**
     * Files baked during the test run.
     *
     * @var list<string>
     */
    protected array $baked = [];

    /**
     * Tear down after each test.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->baked as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
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
        $command = new BakeSimpleMigrationCommand();
        $out = new StubConsoleOutput();
        $out->setOutputAs(StubConsoleOutput::PLAIN);
        $io = new ConsoleIo($out, $out, new StubConsoleInput([]));

        $command->run($argv, $io);

        return implode("\n", $out->messages());
    }

    /**
     * Test baking a plain migration file.
     *
     * @return void
     */
    public function testBakesPlainMigration(): void
    {
        $output = $this->runCommand(['BakeSimpleTest']);

        $this->assertStringContainsString('Baked migration `BakeSimpleTest`', $output);

        $files = glob(CONFIG . 'MongoMigrations' . DS . '*_bake_simple_test.php');
        $this->assertNotEmpty($files);
        $this->baked[] = $files[0];

        $content = file_get_contents($files[0]);
        $this->assertStringContainsString('use Crustum\Mongo\Migration\BaseMigration;', $content);
        $this->assertStringContainsString('class BakeSimpleTest extends BaseMigration', $content);
        $this->assertStringContainsString('public function change(): void', $content);
    }
}

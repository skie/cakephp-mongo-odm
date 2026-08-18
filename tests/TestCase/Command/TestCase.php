<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Command;

use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\Routing\Router;
use Cake\TestSuite\StringCompareTrait;
use Cake\TestSuite\TestCase as BaseTestCase;
use TestApp\BakeTestApplication;

/**
 * Base test case for the Crustum/Mongo bake commands.
 *
 * Uses the console integration test trait (executes `bake mongo_*` commands
 * through a real app) and the string compare trait (asserts generated files
 * against `tests/comparisons/` baselines). The app used for command discovery
 * is `TestApp\BakeTestApplication`, which loads Cake/TwigView + Bake + Crustum/Mongo.
 */
abstract class TestCase extends BaseTestCase
{
    use ConsoleIntegrationTestTrait;
    use StringCompareTrait;

    /**
     * The last generated file path (cleaned up in tearDown).
     *
     * @var string
     */
    protected string $generatedFile = '';

    /**
     * Generated file paths (cleaned up in tearDown).
     *
     * @var array<string>
     */
    protected array $generatedFiles = [];

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        parent::setUp();
        Router::reload();
        $this->configApplication(BakeTestApplication::class, null);
        self::setAppNamespace('TestApp');
        mongoTestMigrationDir();
        mongoTestCleanMigrationDir();
    }

    /**
     * Migration source folder name (under CONFIG), isolated per ParaTest worker.
     *
     * @return string
     */
    protected function migrationSource(): string
    {
        return mongoTestMigrationSource();
    }

    /**
     * Absolute path to the migration folder for this test process.
     *
     * @return string
     */
    protected function migrationDir(): string
    {
        return mongoTestMigrationDir();
    }

    /**
     * Appends `--source` when the command targets the default migrations folder.
     *
     * @param string $command Console command line.
     * @return string
     */
    protected function withMigrationSource(string $command): string
    {
        if (str_contains($command, '--source')) {
            return $command;
        }

        return $command . ' --source ' . $this->migrationSource();
    }

    /**
     * @inheritDoc
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        if ($this->generatedFile !== '' && file_exists($this->generatedFile)) {
            unlink($this->generatedFile);
            $this->generatedFile = '';
        }

        foreach ($this->generatedFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }

        $this->generatedFiles = [];
        mongoTestCleanMigrationDir();
    }

    /**
     * Assert that a list of files exist.
     *
     * @param list<string> $files The list of files to check.
     * @param string $message The message to use if a check fails.
     */
    protected function assertFilesExist(array $files, string $message = ''): void
    {
        foreach ($files as $file) {
            $this->assertFileExists($file, $message);
        }
    }

    /**
     * Assert that a file contains a substring.
     *
     * @param string $expected The expected content.
     * @param string $path The path to check.
     * @param string $message The error message.
     */
    protected function assertFileContains(string $expected, string $path, string $message = ''): void
    {
        $this->assertFileExists($path, 'Cannot test contents, file does not exist.');

        $contents = file_get_contents($path);
        $this->assertStringContainsString($expected, $contents, $message);
    }

    /**
     * Assert that a file does not contain a substring.
     *
     * @param string $expected The content that should be absent.
     * @param string $path The path to check.
     * @param string $message The error message.
     */
    protected function assertFileNotContains(string $expected, string $path, string $message = ''): void
    {
        $this->assertFileExists($path, 'Cannot test contents, file does not exist.');

        $contents = file_get_contents($path);
        $this->assertStringNotContainsString($expected, $contents, $message);
    }
}

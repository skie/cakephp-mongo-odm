<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Command;

use Cake\Console\CommandInterface;
use Crustum\Mongo\Test\TestCase\Command\TestCase;

/**
 * MongoFixtureCommandTest class
 */
class MongoFixtureCommandTest extends TestCase
{
    /**
     * setUp method
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->_compareBasePath = ROOT . DS . 'tests' . DS . 'comparisons' . DS . 'Command' . DS;
    }

    /**
     * Test baking a fixture with sample records from the live schema.
     *
     * `test_users` has no existing fixture file, so the bake target is new.
     *
     * @return void
     */
    public function testBakeFixture(): void
    {
        $this->generatedFile = ROOT . DS . 'tests' . DS . 'Fixture' . DS . 'TestUsersFixture.php';
        $this->exec('bake mongofixture TestUsers --connection mongo', ['y']);

        $this->assertExitCode(CommandInterface::CODE_SUCCESS);
        $this->assertFileExists($this->generatedFile);

        $result = file_get_contents($this->generatedFile);
        $this->assertStringContainsString('class TestUsersFixture extends TestFixture', $result);
        $this->assertStringContainsString("public string \$connection = 'test_mongo';", $result);
        $this->assertStringContainsString("public string \$collection = 'test_users';", $result);
        $this->assertStringContainsString('public array $records = [', $result);
    }

    /**
     * Test baking a fixture for a non-existent collection stays schema-less.
     *
     * @return void
     */
    public function testBakeFixtureNoSchema(): void
    {
        $this->generatedFile = ROOT . DS . 'tests' . DS . 'Fixture' . DS . 'BakeEmptyFixture.php';
        $this->exec('bake mongofixture BakeEmpty --connection mongo', ['y']);

        $this->assertExitCode(CommandInterface::CODE_SUCCESS);
        $this->assertFileExists($this->generatedFile);

        $result = file_get_contents($this->generatedFile);
        $this->assertStringContainsString('class BakeEmptyFixture extends TestFixture', $result);
        $this->assertStringContainsString('public array $records = [', $result);
    }
}

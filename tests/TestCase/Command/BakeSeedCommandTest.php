<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Command;

use Cake\Console\BaseCommand;

/**
 * BakeSeedCommandTest class
 *
 * Port of `Migrations\Test\TestCase\Command\BakeSeedCommandTest` for the Mongo
 * seed bake command (`bake mongo_seed`). Output goes to
 * `CONFIG/MongoSeeds/` (throwaway test app config) with fresh unique names and
 * is cleaned up in tearDown.
 */
class BakeSeedCommandTest extends TestCase
{
    /**
     * @inheritDoc
     */
    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (glob(CONFIG . 'MongoSeeds' . DS . 'Bake*Seed.php') ?: [] as $file) {
            unlink($file);
        }
    }

    /**
     * Test baking a seed class.
     *
     * @return void
     */
    public function testBasicBaking(): void
    {
        $this->exec('bake mongo_seed BakeArticles --connection mongo');

        $this->assertExitCode(BaseCommand::CODE_SUCCESS);
        $file = CONFIG . 'MongoSeeds' . DS . 'BakeArticlesSeed.php';
        $this->assertFileExists($file);

        $result = file_get_contents($file);
        $this->assertStringContainsString('class BakeArticlesSeed extends BaseSeed', $result);
        $this->assertStringContainsString('use Crustum\\Mongo\\Migration\\BaseSeed;', $result);
        $this->assertStringContainsString('public function run(): void', $result);
    }

    /**
     * Test that baking a seed with the same name aborts.
     *
     * @return void
     */
    public function testDuplicateName(): void
    {
        $this->exec('bake mongo_seed BakeArticles --connection mongo');
        $this->exec('bake mongo_seed BakeArticles --connection mongo');

        $this->assertExitCode(BaseCommand::CODE_ERROR);
        $this->assertErrorContains('A seed with the name `BakeArticlesSeed` already exists.');
    }

    /**
     * Test that `--force` overwrites an existing seed.
     *
     * @return void
     */
    public function testDuplicateNameWithForce(): void
    {
        $this->exec('bake mongo_seed BakeArticles --connection mongo');
        $this->exec('bake mongo_seed BakeArticles --connection mongo --force');

        $this->assertExitCode(BaseCommand::CODE_SUCCESS);
        $this->assertFileExists(CONFIG . 'MongoSeeds' . DS . 'BakeArticlesSeed.php');
    }

    /**
     * Test that an invalid class name aborts.
     *
     * @return void
     */
    public function testInvalidName(): void
    {
        $this->exec('bake mongo_seed 123Invalid --connection mongo');

        $this->assertExitCode(BaseCommand::CODE_ERROR);
    }

    /**
     * Test that the seed file uses the underscored table name.
     *
     * @return void
     */
    public function testTableNameInComment(): void
    {
        $this->exec('bake mongo_seed BakeArticles --connection mongo');

        $file = CONFIG . 'MongoSeeds' . DS . 'BakeArticlesSeed.php';
        $this->assertFileExists($file);
        $result = file_get_contents($file);

        $this->assertStringContainsString("// \$this->insert('bake_articles', [", $result);
    }
}

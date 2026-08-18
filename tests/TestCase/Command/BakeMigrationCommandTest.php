<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Command;

use Cake\Console\BaseCommand;
use Crustum\Mongo\Migration\Command\BakeMigrationCommand;

/**
 * BakeMigrationCommandTest class
 *
 * Port of `Migrations\Test\TestCase\Command\BakeMigrationCommandTest` for the
 * Mongo migration bake command (`bake mongo_migration`). Only the ports that
 * map to our Mongo command are included; SQL-specific cases (primary_key
 * references, anonymous style, reserved-keyword prefixing) have no Mongo
 * analog and are skipped.
 *
 * Output goes to `CONFIG/MongoMigrations/` (the throwaway test app config),
 * always with fresh unique class names, and files are cleaned up in tearDown.
 */
class BakeMigrationCommandTest extends TestCase
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
     * @inheritDoc
     */
    protected function tearDown(): void
    {
        mongoTestCleanMigrationDir($this->migrationDir());
        parent::tearDown();
    }

    /**
     * Test an empty migration.
     *
     * @return void
     */
    public function testNoContents(): void
    {
        $this->exec($this->withMigrationSource('bake mongo_migration BakeNoContents --connection mongo'));

        $this->assertExitCode(BaseCommand::CODE_SUCCESS);
        $files = glob($this->migrationDir() . '*_BakeNoContents.php');
        $this->assertNotEmpty($files);
        $result = file_get_contents($files[0]);

        $this->assertStringContainsString('class BakeNoContents extends BaseMigration', $result);
        $this->assertStringContainsString('use Crustum\\Mongo\\Migration\\BaseMigration;', $result);
        $this->assertStringContainsString('public function change(): void', $result);
    }

    /**
     * Test creating a migration with fields.
     *
     * @return void
     */
    public function testCreateWithFields(): void
    {
        $this->exec($this->withMigrationSource('bake mongo_migration CreateUsers name:string age:int? email:string:unique --connection mongo'));

        $files = glob($this->migrationDir() . '*_CreateUsers.php');
        $this->assertNotEmpty($files);
        $result = file_get_contents($files[0]);

        $this->assertStringContainsString("->addColumn('name', 'string', [", $result);
        $this->assertStringContainsString("->addColumn('age', 'integer', [", $result);
        $this->assertStringContainsString("'null' => true,", $result);
        $this->assertStringContainsString("->addColumn('email', 'string', [", $result);
        $this->assertStringContainsString("'unique' => true,", $result);
        $this->assertStringContainsString('public function change(): void', $result);
        $this->assertStringContainsString('->create();', $result);
    }

    /**
     * Test that an `Add*To*` migration infers the collection and uses `update()`.
     *
     * @return void
     */
    public function testCreateAddFieldToProducts(): void
    {
        $this->exec($this->withMigrationSource('bake mongo_migration AddPriceToProducts price:int --connection mongo'));

        $files = glob($this->migrationDir() . '*_AddPriceToProducts.php');
        $this->assertNotEmpty($files);
        $result = file_get_contents($files[0]);

        $this->assertStringContainsString("->addColumn('price', 'integer')", $result);
        $this->assertStringContainsString('->update();', $result);
    }

    /**
     * Test the collection name inference.
     *
     * @return void
     */
    public function testCollectionNameInference(): void
    {
        $command = new BakeMigrationCommand();
        $this->assertSame('articles', $command->collectionName('CreateArticles'));
        $this->assertSame('products', $command->collectionName('AddPriceToProducts'));
        $this->assertSame('users', $command->collectionName('RemoveFieldsFromUsers'));
        $this->assertSame('things', $command->collectionName('Things'));
    }

    /**
     * Test that baking a migration with the same name aborts.
     *
     * @return void
     */
    public function testCreateDuplicateName(): void
    {
        $this->exec($this->withMigrationSource('bake mongo_migration BakeDupCreate --connection mongo'));
        $this->exec($this->withMigrationSource('bake mongo_migration BakeDupCreate --connection mongo'));

        $this->assertExitCode(BaseCommand::CODE_ERROR);
        $this->assertErrorContains('A migration with the name `BakeDupCreate` already exists.');
    }

    /**
     * Test that `--force` deletes the existing file and bakes a new one.
     *
     * @return void
     */
    public function testCreateDuplicateNameWithForce(): void
    {
        $this->exec($this->withMigrationSource('bake mongo_migration BakeDupCreate --connection mongo'));
        $files = glob($this->migrationDir() . '*_BakeDupCreate.php');
        $filePath = $files[0] ?? null;
        sleep(1);

        $this->exec($this->withMigrationSource('bake mongo_migration BakeDupCreate --connection mongo --force'));

        $this->assertExitCode(BaseCommand::CODE_SUCCESS);
        $files = glob($this->migrationDir() . '*_BakeDupCreate.php');
        $this->assertNotEquals($filePath, $files[0] ?? null);
    }
}

<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Command;

use Cake\Console\BaseCommand;

/**
 * BakeMigrationSnapshotCommandTest class
 *
 * Port of `Migrations\Test\TestCase\Command\BakeMigrationSnapshotCommandTest`
 * for the Mongo snapshot bake command (`bake mongo_migration_snapshot`).
 * Output goes to `CONFIG/MongoMigrations/` (throwaway test app config) and is
 * cleaned up in tearDown. SQL-specific snapshot scenarios (auto-id, collation,
 * on-update, postgres timestamptz) have no Mongo analog and are skipped.
 */
class BakeMigrationSnapshotCommandTest extends TestCase
{
    /**
     * @inheritDoc
     */
    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (glob($this->migrationDir() . '*_bake_snapshot*.php') ?: [] as $file) {
            unlink($file);
        }
    }

    /**
     * Test baking a snapshot that recreates the current schema.
     *
     * @return void
     */
    public function testNotEmptySnapshot(): void
    {
        $this->exec($this->withMigrationSource('bake mongo_migration_snapshot BakeSnapshotArticles --connection mongo'));

        $this->assertExitCode(BaseCommand::CODE_SUCCESS);
        $files = glob($this->migrationDir() . '*_bake_snapshot_articles.php');
        $this->assertNotEmpty($files);

        $result = file_get_contents($files[0]);
        $this->assertStringContainsString('class BakeSnapshotArticles extends BaseMigration', $result);
        // Every collection in the test schema is captured with a create().
        $this->assertStringContainsString('->create();', $result);
    }

    /**
     * Test that a missing name aborts.
     *
     * @return void
     */
    public function testNoName(): void
    {
        $this->exec($this->withMigrationSource('bake mongo_migration_snapshot --connection mongo'));

        $this->assertExitCode(BaseCommand::CODE_ERROR);
    }
}

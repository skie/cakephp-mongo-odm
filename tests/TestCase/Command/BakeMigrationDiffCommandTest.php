<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Command;

use Cake\Console\BaseCommand;
use RuntimeException;

/**
 * BakeMigrationDiffCommandTest class
 *
 * Port of `Migrations\Test\TestCase\Command\BakeMigrationDiffCommandTest` for
 * the Mongo diff bake command (`bake mongo_migration_diff`, a thin alias over
 * `mongo migrations diff`). The lock file and baked migrations live in
 * `CONFIG/MongoMigrations/` (throwaway test app config) and are cleaned up.
 * SQL-specific scenarios (comparison DBs, unified tables, per-dialect diffs)
 * have no Mongo analog and are skipped.
 */
class BakeMigrationDiffCommandTest extends TestCase
{
    /**
     * @inheritDoc
     */
    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (glob(CONFIG . 'MongoMigrations' . DS . '*_bake_schema_sync*.php') ?: [] as $file) {
            unlink($file);
        }
        $lock = CONFIG . 'MongoMigrations' . DS . 'schema-dump-mongo.lock';
        if (file_exists($lock)) {
            unlink($lock);
        }
    }

    /**
     * Test that a diff against a matching lock file reports no differences.
     *
     * The lock is produced by `mongo schema dump` so it exactly matches the
     * live schema.
     *
     * @return void
     */
    public function testNoDifferences(): void
    {
        $this->exec('mongo schema dump --connection mongo');
        $this->assertExitCode(BaseCommand::CODE_SUCCESS);

        $this->exec('bake mongo_migration_diff BakeSchemaSync --connection mongo');

        $this->assertExitCode(BaseCommand::CODE_SUCCESS);
        $this->assertOutputContains('No schema differences found.');
    }

    /**
     * Test that a diff without a lock file throws.
     *
     * @return void
     */
    public function testMissingDump(): void
    {
        $lock = CONFIG . 'MongoMigrations' . DS . 'schema-dump-mongo.lock';
        if (file_exists($lock)) {
            unlink($lock);
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not exist. Run `mongo schema dump` first.');
        $this->exec('bake mongo_migration_diff BakeSchemaSync --connection mongo');
    }

    /**
     * Test that a diff with a stale lock file (empty desired schema) bakes a
     * migration dropping the collections that exist live but are not desired.
     *
     * @return void
     */
    public function testDiffBakesMigration(): void
    {
        // Empty desired schema (nothing yet migrated).
        file_put_contents(
            CONFIG . 'MongoMigrations' . DS . 'schema-dump-mongo.lock',
            serialize([]),
        );

        $this->exec('bake mongo_migration_diff BakeSchemaSync --connection mongo');

        $this->assertExitCode(BaseCommand::CODE_SUCCESS);
        $files = glob(CONFIG . 'MongoMigrations' . DS . '*_bake_schema_sync.php');
        $this->assertNotEmpty($files);
        $result = file_get_contents($files[0]);

        $this->assertStringContainsString('class BakeSchemaSync extends BaseMigration', $result);
        $this->assertStringContainsString('dropCollection', $result);
    }
}

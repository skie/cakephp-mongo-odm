<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Migration\Adapter;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Migration\BaseMigration;
use Crustum\Mongo\Migration\Db\Adapter\RecordingAdapter;
use Crustum\Mongo\Migration\MigrationInterface;
use Crustum\Mongo\Test\TestCase\Migration\Stub\FakeAdapter;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests the RecordingAdapter that reverses `change()` migrations.
 *
 * Ported from cakephp/migrations `RecordingAdapterTest`, rewritten for the
 * Mongo command set (create/drop/rename collection, create/drop index,
 * setValidator).
 */
#[CoversClass(RecordingAdapter::class)]
class RecordingAdapterTest extends TestCase
{
    /**
     * @var \Crustum\Mongo\Test\TestCase\Migration\Stub\FakeAdapter
     */
    protected FakeAdapter $fake;

    /**
     * @var \Crustum\Mongo\Migration\Db\Adapter\RecordingAdapter
     */
    protected RecordingAdapter $recording;

    /**
     * Set up before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = new FakeAdapter();
        $this->recording = new RecordingAdapter($this->fake);
    }

    /**
     * Test createCollection is recorded and reversed to dropCollection.
     *
     * @return void
     */
    public function testCreateCollectionIsReversedToDrop(): void
    {
        $this->recording->createCollection('mig_articles');

        $this->assertSame([], array_keys($this->fake->collections));

        $this->recording->executeInvertedCommands();

        $this->assertSame(['dropCollection', ['mig_articles', []]], $this->fake->calls[0]);
        $this->assertArrayNotHasKey('mig_articles', $this->fake->collections);
    }

    /**
     * Test dropCollection is recorded and reversed to createCollection.
     *
     * @return void
     */
    public function testDropCollectionIsReversedToCreate(): void
    {
        $this->fake->createCollection('mig_articles');
        $this->recording->dropCollection('mig_articles');

        $this->recording->executeInvertedCommands();

        $this->assertSame(['createCollection', ['mig_articles', []]], $this->fake->calls[1]);
        $this->assertArrayHasKey('mig_articles', $this->fake->collections);
    }

    /**
     * Test renameCollection is reversed by swapping the names.
     *
     * @return void
     */
    public function testRenameCollectionIsReversed(): void
    {
        $this->fake->createCollection('mig_articles');
        $this->recording->renameCollection('mig_articles', 'posts');

        $this->recording->executeInvertedCommands();

        $this->assertSame(['renameCollection', ['posts', 'mig_articles', false]], $this->fake->calls[1]);
        $this->assertArrayHasKey('mig_articles', $this->fake->collections);
        $this->assertArrayNotHasKey('posts', $this->fake->collections);
    }

    /**
     * Test createIndex is recorded with its generated name and reversed to dropIndex.
     *
     * @return void
     */
    public function testCreateIndexIsReversedToDropIndex(): void
    {
        $indexName = $this->recording->createIndex('mig_articles', ['author_id' => 1]);

        $this->assertSame('author_id_1', $indexName);

        $this->recording->executeInvertedCommands();

        $this->assertSame(['dropIndex', ['mig_articles', 'author_id_1']], $this->fake->calls[0]);
    }

    /**
     * Test createIndex uses an explicit name option.
     *
     * @return void
     */
    public function testCreateIndexUsesExplicitName(): void
    {
        $indexName = $this->recording->createIndex('mig_articles', ['author_id' => 1], ['name' => 'author_idx']);

        $this->assertSame('author_idx', $indexName);

        $this->recording->executeInvertedCommands();

        $this->assertSame(['dropIndex', ['mig_articles', 'author_idx']], $this->fake->calls[0]);
    }

    /**
     * Test setValidator is reversed by re-applying the same validator.
     *
     * @return void
     */
    public function testSetValidatorIsReversedToSetValidator(): void
    {
        $validator = ['$jsonSchema' => ['bsonType' => 'object', 'properties' => []]];
        $this->recording->setValidator('mig_articles', $validator);

        $this->recording->executeInvertedCommands();

        $this->assertSame(['setValidator', ['mig_articles', $validator, null, null]], $this->fake->calls[0]);
    }

    /**
     * Test the recording executes multiple commands in reverse order.
     *
     * @return void
     */
    public function testCommandsExecuteInReverseOrder(): void
    {
        $this->recording->createCollection('mig_articles');
        $this->recording->createCollection('posts');

        $this->recording->executeInvertedCommands();

        $this->assertSame(['dropCollection', ['posts', []]], $this->fake->calls[0]);
        $this->assertSame(['dropCollection', ['mig_articles', []]], $this->fake->calls[1]);
    }

    /**
     * Test executeInvertedCommands with no recorded commands is a no-op.
     *
     * @return void
     */
    public function testExecuteInvertedCommandsEmpty(): void
    {
        $this->recording->executeInvertedCommands();

        $this->assertSame([], $this->fake->calls);
    }

    /**
     * Test journal and breakpoint calls delegate to the wrapped adapter.
     *
     * @return void
     */
    public function testJournalDelegatesToWrappedAdapter(): void
    {
        $migration = new class (20260811000000) extends BaseMigration {
        };

        $this->fake->versions = [20260811000000];
        $this->fake->versionLog[20260811000000] = ['version' => 20260811000000, 'migration_name' => 'X'];

        $this->assertSame([20260811000000], $this->recording->getVersions());
        $this->assertSame([20260811000000], array_keys($this->recording->getVersionLog()));

        $this->recording->migrated($migration, MigrationInterface::UP, 'now', 'then');
        $this->assertContains(20260811000000, $this->fake->versions);

        $this->recording->setBreakpoint($migration);
        $this->assertSame(1, $this->fake->breakpoints[20260811000000]);

        $this->recording->toggleBreakpoint($migration);
        $this->assertSame(0, $this->fake->breakpoints[20260811000000]);

        $this->recording->unsetBreakpoint($migration);
        $this->assertSame(0, $this->fake->breakpoints[20260811000000]);

        $this->fake->breakpoints[20260811000000] = 1;
        $this->assertSame(1, $this->recording->resetAllBreakpoints());
    }

    /**
     * Test transaction methods delegate to the wrapped adapter.
     *
     * @return void
     */
    public function testTransactionsDelegate(): void
    {
        $this->fake->transactionSupport = true;

        $this->assertTrue($this->recording->hasTransactions());

        $this->recording->beginTransaction();
        $this->recording->commitTransaction();
        $this->recording->rollbackTransaction();

        $this->assertSame('beginTransaction', $this->fake->calls[1][0]);
        $this->assertSame('commitTransaction', $this->fake->calls[2][0]);
        $this->assertSame('rollbackTransaction', $this->fake->calls[3][0]);
    }

    /**
     * Test getAdapter returns the decorated adapter.
     *
     * @return void
     */
    public function testGetAdapter(): void
    {
        $this->assertSame($this->fake, $this->recording->getAdapter());
    }
}

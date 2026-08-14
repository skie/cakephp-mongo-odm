<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Migration\Db\Plan;

use Cake\TestSuite\TestCase;
use Crustum\Mongo\Migration\Db\Action\AddField;
use Crustum\Mongo\Migration\Db\Action\AddIndex;
use Crustum\Mongo\Migration\Db\Action\CreateCollection;
use Crustum\Mongo\Migration\Db\Action\DropCollection;
use Crustum\Mongo\Migration\Db\Action\DropIndex;
use Crustum\Mongo\Migration\Db\Plan\Intent;
use Crustum\Mongo\Migration\Db\Plan\Plan;
use Crustum\Mongo\Test\TestCase\Migration\Stub\FakeAdapter;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests the Db\Plan\Plan conflict resolution and execution ordering.
 */
#[CoversClass(Plan::class)]
class PlanTest extends TestCase
{
    /**
     * Test addIndex + dropIndex for the same index are a net no-op.
     *
     * @return void
     */
    public function testAddThenDropIndexIsNoop(): void
    {
        $intent = new Intent();
        $intent->addAction(new AddIndex('mig_articles', 'author_id_1', ['author_id' => 1]));
        $intent->addAction(new DropIndex('mig_articles', 'author_id_1'));

        $fake = new FakeAdapter();
        (new Plan($intent))->execute($fake);

        $methods = array_column($fake->calls, 0);
        $this->assertNotContains('createIndex', $methods);
        $this->assertNotContains('dropIndex', $methods);
    }

    /**
     * Test dropIndex followed by addIndex for the same name is also a no-op.
     *
     * @return void
     */
    public function testDropThenAddIndexIsNoop(): void
    {
        $intent = new Intent();
        $intent->addAction(new DropIndex('mig_articles', 'author_id_1'));
        $intent->addAction(new AddIndex('mig_articles', 'author_id_1', ['author_id' => 1]));

        $fake = new FakeAdapter();
        (new Plan($intent))->execute($fake);

        $methods = array_column($fake->calls, 0);
        $this->assertNotContains('createIndex', $methods);
        $this->assertNotContains('dropIndex', $methods);
    }

    /**
     * Test a dropCollection cancels every other action for that collection.
     *
     * @return void
     */
    public function testDropCollectionCancelsOthers(): void
    {
        $intent = new Intent();
        $intent->addAction(new CreateCollection('mig_articles', []));
        $intent->addAction(new AddField('mig_articles', 'name', 'string'));
        $intent->addAction(new AddIndex('mig_articles', 'author_id_1', ['author_id' => 1]));
        $intent->addAction(new DropCollection('mig_articles'));

        $fake = new FakeAdapter();
        (new Plan($intent))->execute($fake);

        $methods = array_column($fake->calls, 0);
        $this->assertSame(['dropCollection'], $methods);
    }

    /**
     * Test declared fields build the collection validator.
     *
     * @return void
     */
    public function testFieldsBuildValidator(): void
    {
        $intent = new Intent();
        $intent->addAction(new CreateCollection('mig_articles', []));
        $intent->addAction(new AddField('mig_articles', 'name', 'string'));
        $intent->addAction(new AddField('mig_articles', 'author_id', 'objectid'));

        $fake = new FakeAdapter();
        (new Plan($intent))->execute($fake);

        $validator = $fake->collections['mig_articles']['validator'];
        $this->assertSame('object', $validator['$jsonSchema']['bsonType']);
        $this->assertSame('string', $validator['$jsonSchema']['properties']['name']['bsonType']);
        $this->assertSame('objectId', $validator['$jsonSchema']['properties']['author_id']['bsonType']);
    }
}

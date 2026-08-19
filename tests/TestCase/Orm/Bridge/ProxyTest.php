<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Orm\Bridge;

use Cake\ORM\Association as CakeAssociation;
use Crustum\Mongo\Orm\Bridge\Proxy\BelongsToProxy;
use Crustum\Mongo\Test\TestCase\ODM\TestCase;
use TestApp\Model\Table\OrdersTable;

/**
 * Tests the P3 ORM proxies: registration on `Table->associations()`, the
 * no-op `saveAssociated()`, `__call` forwarding, batched `eagerLoader()`, and
 * the `saveWithBridge()` / `patchWithBridge()` two-phase save.
 */
class ProxyTest extends TestCase
{
    /**
     * SQL + Mongo fixtures for the bridge source and targets.
     *
     * @var array<string>
     */
    protected array $fixtures = [
        'plugin.Crustum/Mongo.Authors',
        'plugin.Crustum/Mongo.Orm/Orders',
    ];

    /**
     * The bridge source table (real SQL `orders`).
     *
     * @var \TestApp\Model\Table\OrdersTable
     */
    protected OrdersTable $Orders;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->Orders = $this->fetchTable(OrdersTable::class);
    }

    /**
     * Tests that mongoBelongsTo() registers a BelongsToProxy association.
     *
     * @return void
     */
    public function testRegistersBelongsToProxy(): void
    {
        $association = $this->Orders->associations()->get('Authors');
        $this->assertInstanceOf(BelongsToProxy::class, $association);
        $this->assertInstanceOf(CakeAssociation::class, $association);
        $this->assertSame('author', $association->getProperty());
        $this->assertSame(CakeAssociation::STRATEGY_SELECT, $association->getStrategy());
        $this->assertFalse($association->canBeJoined());
    }

    /**
     * Tests that saveAssociated() is a no-op returning the entity unchanged.
     *
     * @return void
     */
    public function testSaveAssociatedIsNoOp(): void
    {
        $order = $this->Orders->newEmptyEntity();
        $order->set('author', ['name' => 'nobody']);

        $association = $this->Orders->associations()->get('Authors');
        $result = $association->saveAssociated($order);

        $this->assertSame($order, $result);
        $this->assertSame(['name' => 'nobody'], $result->get('author'));
    }

    /**
     * Tests that __call() forwards to the target Mongo collection.
     *
     * @return void
     */
    public function testCallForwardsToCollection(): void
    {
        /** @var \Crustum\Mongo\Orm\Bridge\Proxy\BelongsToProxy $association */
        $association = $this->Orders->associations()->get('Authors');

        $this->assertSame('authors', $association->getCollection());
    }

    /**
     * Tests that eagerLoader() builds a batched map and injects per row.
     *
     * @return void
     */
    public function testEagerLoaderBatchesAndInjects(): void
    {
        /** @var \Crustum\Mongo\Orm\Bridge\Proxy\BelongsToProxy $association */
        $association = $this->Orders->associations()->get('Authors');

        $map = $association->getBridge()->loadByKeys([
            '000000000000000000000001',
            '000000000000000000000002',
        ]);

        $this->assertArrayHasKey('000000000000000000000001', $map);
        $this->assertArrayHasKey('000000000000000000000002', $map);

        $loader = $association->eagerLoader([
            'keys' => ['000000000000000000000001', '000000000000000000000002'],
            'nestKey' => 'Authors',
        ]);

        $row = $loader([
            'Orders__author_id' => '000000000000000000000001',
            'Orders__id' => 1,
        ]);
        $this->assertArrayHasKey('Authors', $row);
        $this->assertSame('mariano', $row['Authors']->get('name'));
    }

    /**
     * Tests that lazy find() reaches the Mongo target via the bridge.
     *
     * @return void
     */
    public function testLazyFindThroughBridge(): void
    {
        /** @var \Crustum\Mongo\Orm\Bridge\Proxy\BelongsToProxy $association */
        $association = $this->Orders->associations()->get('Authors');

        $query = $association->getBridge()->find();
        $this->assertCount(4, $query->all()->toList());
    }

    /**
     * Tests that patchWithBridge() strips bridge properties and re-applies them.
     *
     * @return void
     */
    public function testPatchWithBridgeStripsAndReapplies(): void
    {
        $order = $this->Orders->get(1);
        $data = [
            'customer_name' => 'alice-updated',
            'documents' => [['title' => 'new post']],
        ];

        $result = $this->Orders->patchWithBridge($order, $data);

        $this->assertSame('alice-updated', $result->get('customer_name'));
        $this->assertSame([['title' => 'new post']], $result->get('documents'));
        $this->assertTrue($result->isDirty('documents'));
    }

    /**
     * Tests that saveWithBridge() persists the SQL row then writes Mongo docs.
     *
     * The SQL row is saved against the real `orders` table; the dirty Mongo
     * `documents` property is written to the `documents` collection with the
     * source primary key as the foreign key.
     *
     * @return void
     */
    public function testSaveWithBridgePersistsMongoDocuments(): void
    {
        $documents = $this->getCollectionLocator()->get('Documents');
        $documents->deleteAll([]);

        $order = $this->Orders->newEmptyEntity();
        $order->set('customer_name', 'bridged');
        $order->set('documents', [['title' => 'bridged doc']]);
        $order->setDirty('documents', true);

        $saved = $this->Orders->saveWithBridge($order);

        $this->assertNotFalse($saved);
        $this->assertNotNull($saved->get('id'));

        $docs = $documents->find()->where(['order_id' => $saved->get('id')])->toArray();
        $this->assertCount(1, $docs);
        $this->assertSame('bridged doc', $docs[0]->get('title'));
    }

    /**
     * Tests that saveWithBridge() honors the `associate` option.
     *
     * Only the listed bridge properties are persisted; unlisted dirty ones are
     * skipped.
     *
     * @return void
     */
    public function testSaveWithBridgeAssociateFiltersProperties(): void
    {
        $documents = $this->getCollectionLocator()->get('Documents');
        $profiles = $this->getCollectionLocator()->get('Profiles');
        $documents->deleteAll([]);
        $profilesBefore = $profiles->find()->count();

        $order = $this->Orders->newEmptyEntity();
        $order->set('customer_name', 'bridged');
        $order->set('documents', [['title' => 'kept']]);
        $order->setDirty('documents', true);
        $order->set('profile', ['username' => 'skipped']);
        $order->setDirty('profile', true);

        $saved = $this->Orders->saveWithBridge($order, ['associate' => ['documents']]);

        $this->assertNotFalse($saved);
        $this->assertNotNull($saved->get('id'));

        $docs = $documents->find()->where(['order_id' => $saved->get('id')])->toArray();
        $this->assertCount(1, $docs);
        $this->assertSame('kept', $docs[0]->get('title'));

        $this->assertSame(
            $profilesBefore,
            $profiles->find()->count(),
            'bridge properties not listed in `associate` must be skipped',
        );
    }
}

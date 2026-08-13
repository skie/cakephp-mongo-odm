<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Orm\Bridge;

use Cake\ORM\Table;
use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\ODM\Query\SelectQuery;
use Crustum\Mongo\Orm\Bridge\HasOne;
use Crustum\Mongo\Orm\Bridge\MongoCollectionAwareInterface;
use Crustum\Mongo\Test\TestCase\ODM\TestCase;
use TestApp\Model\Entity\Order;

/**
 * Tests the Direction-1 HasOne bridge association (Mongo doc → SQL row).
 *
 * The SQL source PK is an int; a Mongo `Posts` document carries the matching
 * int `order_id` (unique). This proves the bridge works with real ORM int
 * primary keys — no hex conversion needed.
 */
class HasOneTest extends TestCase
{
    /**
     * The Orders source table (bridge-aware fake).
     *
     * @var \Cake\ORM\Table&\Crustum\Mongo\Orm\Bridge\MongoCollectionAwareInterface
     */
    protected Table&MongoCollectionAwareInterface $Orders;

    /**
     * The Posts Mongo collection.
     *
     * @var \Crustum\Mongo\ODM\BaseCollection
     */
    protected BaseCollection $Posts;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->Orders = $this->fetchTable('TestApp\Model\Table\BridgeOrdersTable');
        $this->Posts = $this->getCollectionLocator()->get('Posts');

        $this->Posts->deleteAll([]);
        $this->Posts->saveOrFail($this->Posts->newDocument(['order_id' => 1, 'title' => 'order one post']));
        $this->Posts->saveOrFail($this->Posts->newDocument(['order_id' => 2, 'title' => 'order two post']));
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        unset($this->Orders, $this->Posts);

        parent::tearDown();
    }

    /**
     * Tests that HasOne loads the single matching Mongo document.
     *
     * @return void
     */
    public function testLoadHasOne(): void
    {
        $association = new HasOne('Posts', $this->Orders, [
            'foreignKey' => 'order_id',
            'property' => 'post',
        ]);

        $order = new Order(['id' => 1, 'customer_name' => 'alice']);
        $association->load([$order]);

        $this->assertNotNull($order->post);
        $this->assertSame('order one post', $order->post->get('title'));
    }

    /**
     * Tests that HasOne attaches null when nothing matches.
     *
     * @return void
     */
    public function testLoadHasOneNoMatch(): void
    {
        $association = new HasOne('Posts', $this->Orders, [
            'foreignKey' => 'order_id',
            'property' => 'post',
        ]);

        $order = new Order(['id' => 99, 'customer_name' => 'nobody']);
        $association->load([$order]);

        $this->assertNull($order->post);
    }

    /**
     * Tests that HasOne attaches one document per source row.
     *
     * @return void
     */
    public function testLoadHasOnePerRow(): void
    {
        $association = new HasOne('Posts', $this->Orders, [
            'foreignKey' => 'order_id',
            'property' => 'post',
        ]);

        $orderOne = new Order(['id' => 1, 'customer_name' => 'alice']);
        $orderTwo = new Order(['id' => 2, 'customer_name' => 'bob']);
        $association->load([$orderOne, $orderTwo]);

        $this->assertSame('order one post', $orderOne->post->get('title'));
        $this->assertSame('order two post', $orderTwo->post->get('title'));
    }

    /**
     * Tests that the default foreign key follows the `{source}_id` convention.
     *
     * @return void
     */
    public function testDefaultForeignKeyConvention(): void
    {
        $association = new HasOne('Posts', $this->Orders);

        $this->assertSame('bridge_order_id', $association->getForeignKey());
        $this->assertSame('id', $association->getBindingKey());
        $this->assertSame('post', $association->getProperty());
    }

    /**
     * Tests that find() returns an un-executed query against the target.
     *
     * @return void
     */
    public function testFindReturnsLazyQuery(): void
    {
        $association = new HasOne('Posts', $this->Orders, [
            'foreignKey' => 'order_id',
        ]);

        $query = $association->find();
        $this->assertInstanceOf(SelectQuery::class, $query);
        $this->assertCount(2, $query->all()->toList());
    }
}

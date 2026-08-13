<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Orm\Bridge;

use Cake\ORM\Table;
use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\ODM\Query\SelectQuery;
use Crustum\Mongo\Orm\Bridge\HasMany;
use Crustum\Mongo\Orm\Bridge\MongoCollectionAwareInterface;
use Crustum\Mongo\Test\TestCase\ODM\TestCase;
use TestApp\Model\Entity\Order;

/**
 * Tests the Direction-1 HasMany bridge association (Mongo docs → SQL row).
 *
 * The SQL source PK is an int; the Mongo `Posts` collection documents carry a
 * matching int `order_id`. This proves the bridge works with real ORM int
 * primary keys — no hex conversion needed.
 */
class HasManyTest extends TestCase
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
        $this->Posts->saveOrFail($this->Posts->newDocument(['order_id' => 1, 'title' => 'order one another']));
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
     * Tests that HasMany loads all matching documents as an array.
     *
     * @return void
     */
    public function testLoadHasMany(): void
    {
        $association = new HasMany('Posts', $this->Orders, [
            'foreignKey' => 'order_id',
            'property' => 'posts',
        ]);

        $order = new Order(['id' => 1, 'customer_name' => 'alice']);
        $association->load([$order]);

        $this->assertCount(2, $order->posts);
        $this->assertSame(
            ['order one post', 'order one another'],
            array_map(fn($p) => $p->get('title'), $order->posts),
        );
    }

    /**
     * Tests that HasMany returns an empty array when nothing matches.
     *
     * @return void
     */
    public function testLoadHasManyNoMatch(): void
    {
        $association = new HasMany('Posts', $this->Orders, [
            'foreignKey' => 'order_id',
            'property' => 'posts',
        ]);

        $order = new Order(['id' => 99, 'customer_name' => 'nobody']);
        $association->load([$order]);

        $this->assertSame([], $order->posts);
    }

    /**
     * Tests that HasMany groups by key across multiple source rows.
     *
     * @return void
     */
    public function testLoadHasManyGroupsByKey(): void
    {
        $association = new HasMany('Posts', $this->Orders, [
            'foreignKey' => 'order_id',
            'property' => 'posts',
        ]);

        $orderOne = new Order(['id' => 1, 'customer_name' => 'alice']);
        $orderTwo = new Order(['id' => 2, 'customer_name' => 'bob']);
        $association->load([$orderOne, $orderTwo]);

        $this->assertCount(2, $orderOne->posts);
        $this->assertCount(1, $orderTwo->posts);
    }

    /**
     * Tests that the default foreign key follows the `{source}_id` convention.
     *
     * @return void
     */
    public function testDefaultForeignKeyConvention(): void
    {
        $association = new HasMany('Posts', $this->Orders);

        $this->assertSame('bridge_order_id', $association->getForeignKey());
        $this->assertSame('id', $association->getBindingKey());
        $this->assertSame('posts', $association->getProperty());
    }

    /**
     * Tests that find() returns an un-executed query against the target.
     *
     * @return void
     */
    public function testFindReturnsLazyQuery(): void
    {
        $association = new HasMany('Posts', $this->Orders, [
            'foreignKey' => 'order_id',
        ]);

        $query = $association->find();
        $this->assertInstanceOf(SelectQuery::class, $query);
        $this->assertCount(3, $query->all()->toList());
    }
}

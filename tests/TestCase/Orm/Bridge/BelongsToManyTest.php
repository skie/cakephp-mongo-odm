<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Orm\Bridge;

use Cake\ORM\Table;
use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\Orm\Bridge\BelongsToMany;
use Crustum\Mongo\Orm\Bridge\MongoCollectionAwareInterface;
use Crustum\Mongo\Test\TestCase\ODM\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use TestApp\Model\Entity\Order;

/**
 * Tests the Direction-1 BelongsToMany bridge association (SQL row ↔ Mongo docs).
 *
 * Covers both pivot designs: junction collection (`orders_tags`) and the
 * in-document `order_ids` array pivot.
 */
#[CoversClass(BelongsToMany::class)]
class BelongsToManyTest extends TestCase
{
    /**
     * The Orders source table (bridge-aware fake).
     *
     * @var \Cake\ORM\Table&\Crustum\Mongo\Orm\Bridge\MongoCollectionAwareInterface
     */
    protected Table&MongoCollectionAwareInterface $Orders;

    /**
     * The Tags Mongo collection (real type).
     *
     * @var \Crustum\Mongo\ODM\BaseCollection
     */
    protected BaseCollection $Tags;

    /**
     * The junction Mongo collection.
     *
     * @var \Crustum\Mongo\ODM\BaseCollection
     */
    protected BaseCollection $Junction;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->Orders = $this->fetchTable('TestApp\Model\Table\BridgeOrdersTable');
        $this->Tags = $this->getCollectionLocator()->get('BridgeTags');
        $this->Junction = $this->getCollectionLocator()->get('OrdersTags');

        $this->Tags->deleteAll([]);
        $this->Junction->deleteAll([]);

        $this->Tags->saveOrFail($this->Tags->newDocument(['_id' => '000000000000000000000001', 'name' => 'urgent']));
        $this->Tags->saveOrFail($this->Tags->newDocument(['_id' => '000000000000000000000002', 'name' => 'review']));
        $this->Tags->saveOrFail($this->Tags->newDocument(['_id' => '000000000000000000000003', 'name' => 'archived']));

        $this->Junction->saveOrFail($this->Junction->newDocument(['bridge_order_id' => 1, 'tag_id' => '000000000000000000000001']));
        $this->Junction->saveOrFail($this->Junction->newDocument(['bridge_order_id' => 1, 'tag_id' => '000000000000000000000002']));
        $this->Junction->saveOrFail($this->Junction->newDocument(['bridge_order_id' => 2, 'tag_id' => '000000000000000000000003']));
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        unset($this->Orders, $this->Tags, $this->Junction);

        parent::tearDown();
    }

    /**
     * Tests that BelongsToMany loads all linked targets via the junction.
     *
     * @return void
     */
    public function testLoadViaJunction(): void
    {
        $association = new BelongsToMany('Tags', $this->Orders, [
            'className' => 'BridgeTags',
            'pivot' => BelongsToMany::PIVOT_JUNCTION,
            'junctionCollection' => 'OrdersTags',
            'sourceForeignKey' => 'bridge_order_id',
            'targetForeignKey' => 'tag_id',
        ]);

        $order = new Order(['id' => 1, 'customer_name' => 'alice']);
        $association->load([$order]);

        $this->assertCount(2, $order->tags);
        $this->assertSame(
            ['urgent', 'review'],
            array_map(fn($t) => $t->get('name'), $order->tags),
        );
    }

    /**
     * Tests that BelongsToMany returns an empty list when no links exist.
     *
     * @return void
     */
    public function testLoadViaJunctionNoLinks(): void
    {
        $association = new BelongsToMany('Tags', $this->Orders, [
            'className' => 'BridgeTags',
            'pivot' => BelongsToMany::PIVOT_JUNCTION,
            'junctionCollection' => 'OrdersTags',
            'sourceForeignKey' => 'bridge_order_id',
            'targetForeignKey' => 'tag_id',
        ]);

        $order = new Order(['id' => 99, 'customer_name' => 'nobody']);
        $association->load([$order]);

        $this->assertSame([], $order->tags);
    }

    /**
     * Tests that the array pivot matches targets whose `*_ids` array contains
     * the source primary key.
     *
     * @return void
     */
    public function testLoadViaArrayPivot(): void
    {
        $this->Tags->deleteAll([]);
        $this->Tags->saveOrFail($this->Tags->newDocument(['_id' => '000000000000000000000001', 'name' => 'urgent', 'bridge_order_ids' => [1, 2]]));
        $this->Tags->saveOrFail($this->Tags->newDocument(['_id' => '000000000000000000000002', 'name' => 'review', 'bridge_order_ids' => [1]]));
        $this->Tags->saveOrFail($this->Tags->newDocument(['_id' => '000000000000000000000003', 'name' => 'archived', 'bridge_order_ids' => [2]]));

        $association = new BelongsToMany('Tags', $this->Orders, [
            'className' => 'BridgeTags',
            'pivot' => BelongsToMany::PIVOT_ARRAY,
        ]);

        $order = new Order(['id' => 1, 'customer_name' => 'alice']);
        $association->load([$order]);

        $this->assertCount(2, $order->tags);
        $this->assertSame(
            ['urgent', 'review'],
            array_map(fn($t) => $t->get('name'), $order->tags),
        );
    }

    /**
     * Tests that the default junction name sorts source and target collections.
     *
     * @return void
     */
    public function testDefaultJunctionNameConvention(): void
    {
        $association = new BelongsToMany('Tags', $this->Orders, [
            'pivot' => BelongsToMany::PIVOT_JUNCTION,
        ]);

        $this->assertSame('bridge_orders_tags', $association->getJunctionCollectionName());
        $this->assertSame('bridge_order_id', $association->getSourceForeignKey());
        $this->assertSame('tag_id', $association->getTargetForeignKey());
    }
}

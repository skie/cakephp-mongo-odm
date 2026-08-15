<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Orm\Bridge;

use Cake\ORM\Table;
use Crustum\Mongo\Orm\Bridge\BelongsToMany;
use Crustum\Mongo\Orm\Bridge\DBRef;
use Crustum\Mongo\Orm\Bridge\HasMany;
use Crustum\Mongo\Orm\Bridge\HasOne;
use Crustum\Mongo\Orm\Bridge\MongoCollectionAwareInterface;
use Crustum\Mongo\Test\TestCase\ODM\TestCase;
use TestApp\Model\Entity\File;
use TestApp\Model\Entity\Order;

/**
 * Tests the P5 cascade delete matrix across the bridge association types.
 *
 * @see docs/reference/29-orm-mongo-association-bridge.md §10
 */
class CascadeDeleteTest extends TestCase
{
    /**
     * The Orders source table (bridge-aware fake).
     *
     * @var \Cake\ORM\Table&\Crustum\Mongo\Orm\Bridge\MongoCollectionAwareInterface
     */
    protected Table&MongoCollectionAwareInterface $Orders;

    /**
     * The Mongo target collections.
     *
     * @var array<string, \Crustum\Mongo\ODM\BaseCollection>
     */
    protected array $collections = [];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->Orders = $this->fetchTable('TestApp\Model\Table\BridgeOrdersTable');

        foreach (['BridgePosts', 'BridgeProfiles', 'BridgeTags', 'OrdersTags', 'Files'] as $alias) {
            $collection = $this->getCollectionLocator()->get($alias);
            $collection->deleteAll([]);
            $this->collections[$alias] = $collection;
        }
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        unset($this->Orders);
        $this->collections = [];

        parent::tearDown();
    }

    /**
     * Tests that HasOne deletes the target document when dependent.
     *
     * @return void
     */
    public function testHasOneDeletesTarget(): void
    {
        $this->collections['BridgeProfiles']->saveOrFail(
            $this->collections['BridgeProfiles']->newDocument(['order_id' => 1, 'bio' => 'x']),
        );

        $association = new HasOne('BridgeProfiles', $this->Orders, [
            'foreignKey' => 'order_id',
            'property' => 'profile',
            'dependent' => true,
        ]);

        $order = new Order(['id' => 1]);
        $this->assertTrue($association->cascadeDelete($order));
        $this->assertCount(0, $this->collections['BridgeProfiles']->find()->where(['order_id' => 1])->toArray());
    }

    /**
     * Tests that HasOne leaves the target when not dependent.
     *
     * @return void
     */
    public function testHasOneNoopWithoutDependent(): void
    {
        $this->collections['BridgeProfiles']->saveOrFail(
            $this->collections['BridgeProfiles']->newDocument(['order_id' => 1, 'bio' => 'x']),
        );

        $association = new HasOne('BridgeProfiles', $this->Orders, [
            'foreignKey' => 'order_id',
            'property' => 'profile',
            'dependent' => false,
        ]);

        $order = new Order(['id' => 1]);
        $association->cascadeDelete($order);
        $this->assertCount(1, $this->collections['BridgeProfiles']->find()->where(['order_id' => 1])->toArray());
    }

    /**
     * Tests that HasMany deletes all matching documents when dependent.
     *
     * @return void
     */
    public function testHasManyDeletesTargets(): void
    {
        foreach ([1, 1, 2] as $orderId) {
            $this->collections['BridgePosts']->saveOrFail(
                $this->collections['BridgePosts']->newDocument(['order_id' => $orderId, 'title' => 'p']),
            );
        }

        $association = new HasMany('BridgePosts', $this->Orders, [
            'foreignKey' => 'order_id',
            'property' => 'posts',
            'dependent' => true,
        ]);

        $order = new Order(['id' => 1]);
        $this->assertTrue($association->cascadeDelete($order));
        $this->assertCount(0, $this->collections['BridgePosts']->find()->where(['order_id' => 1])->toArray());
        $this->assertCount(1, $this->collections['BridgePosts']->find()->where(['order_id' => 2])->toArray());
    }

    /**
     * Tests that HasMany nullifies the foreign key when dependent is 'nullify'.
     *
     * @return void
     */
    public function testHasManyNullifies(): void
    {
        $this->collections['BridgePosts']->saveOrFail(
            $this->collections['BridgePosts']->newDocument(['order_id' => 1, 'title' => 'p']),
        );

        $association = new HasMany('BridgePosts', $this->Orders, [
            'foreignKey' => 'order_id',
            'property' => 'posts',
            'dependent' => 'nullify',
        ]);

        $order = new Order(['id' => 1]);
        $association->cascadeDelete($order);

        $docs = $this->collections['BridgePosts']->find()->toArray();
        $this->assertCount(1, $docs);
        $this->assertNull($docs[0]->get('order_id'));
    }

    /**
     * Tests that BelongsToMany junction mode deletes the link rows.
     *
     * @return void
     */
    public function testBelongsToManyDeletesJunctionLinks(): void
    {
        $this->collections['OrdersTags']->saveOrFail(
            $this->collections['OrdersTags']->newDocument(['bridge_order_id' => 1, 'tag_id' => '000000000000000000000001']),
        );

        $association = new BelongsToMany('BridgeTags', $this->Orders, [
            'pivot' => BelongsToMany::PIVOT_JUNCTION,
            'junctionCollection' => 'OrdersTags',
            'dependent' => true,
        ]);

        $order = new Order(['id' => 1]);
        $association->cascadeDelete($order);
        $this->assertCount(0, $this->collections['OrdersTags']->find()->where(['bridge_order_id' => 1])->toArray());
    }

    /**
     * Tests that BelongsToMany array pivot pulls the source id from targets.
     *
     * @return void
     */
    public function testBelongsToManyArrayPivotPullsSourceId(): void
    {
        $this->collections['BridgeTags']->saveOrFail(
            $this->collections['BridgeTags']->newDocument(['_id' => '000000000000000000000001', 'name' => 't', 'bridge_order_ids' => [1, 2]]),
        );

        $association = new BelongsToMany('BridgeTags', $this->Orders, [
            'pivot' => BelongsToMany::PIVOT_ARRAY,
            'dependent' => true,
        ]);

        $order = new Order(['id' => 1]);
        $association->cascadeDelete($order);

        $docs = $this->collections['BridgeTags']->find()->toArray();
        $this->assertSame([2], $docs[0]->get('bridge_order_ids'));
    }

    /**
     * Tests that DBRef cascade delete is a no-op (pointer only).
     *
     * @return void
     */
    public function testDbrefIsNoop(): void
    {
        $this->collections['Files']->saveOrFail(
            $this->collections['Files']->newDocument(['_id' => '000000000000000000000001', 'path' => '/a']),
        );

        $association = new DBRef('Files', $this->Orders, ['dependent' => true]);

        $file = new File(['id' => 1, 'file_ref' => '000000000000000000000001']);
        $this->assertTrue($association->cascadeDelete($file));
        $this->assertCount(1, $this->collections['Files']->find()->toArray());
    }
}

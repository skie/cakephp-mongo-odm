<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Orm\Bridge;

use Cake\ORM\Entity;
use Cake\ORM\Table;
use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\ODM\Document;
use Crustum\Mongo\Orm\Bridge\BelongsTo;
use Crustum\Mongo\Orm\Bridge\HasMany;
use Crustum\Mongo\Orm\Bridge\MongoCollectionAwareInterface;
use Crustum\Mongo\Orm\Bridge\Row\DocumentWrapper;
use Crustum\Mongo\Test\TestCase\ODM\TestCase;
use TestApp\Model\Entity\Order;
use TestApp\Model\Table\BridgeOrdersTable;

/**
 * Tests the P6 DocumentWrapper (ODM Document → ORM Entity adapter) and the
 * `autoWrap` option on bridge associations.
 */
class DocumentWrapperTest extends TestCase
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
        $this->Orders = $this->fetchTable(BridgeOrdersTable::class);
        $this->Posts = $this->getCollectionLocator()->get('BridgePosts');

        $this->Posts->deleteAll([]);
        $this->Posts->saveOrFail($this->Posts->newDocument(['order_id' => 1, 'title' => 'wrapped']));
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
     * Tests that DocumentWrapper exposes the document as a Cake Entity.
     *
     * @return void
     */
    public function testWrapperExposesEntitySurface(): void
    {
        $document = $this->Posts->find()->where(['order_id' => 1])->first();
        $this->assertInstanceOf(Document::class, $document);

        $wrapper = new DocumentWrapper($document);

        $this->assertInstanceOf(Entity::class, $wrapper);
        $this->assertSame('wrapped', $wrapper->get('title'));
        $this->assertSame($document, $wrapper->getDocument());
    }

    /**
     * Tests that a bridge association with autoWrap wraps a single document.
     *
     * @return void
     */
    public function testAutoWrapSingleDocument(): void
    {
        $this->Posts->saveOrFail($this->Posts->newDocument(['_id' => '000000000000000000000099', 'order_id' => 1, 'title' => 'w']));

        $association = new HasMany('Posts', $this->Orders, [
            'className' => 'BridgePosts',
            'foreignKey' => 'order_id',
            'property' => 'posts',
            'autoWrap' => true,
        ]);

        $order = new Order(['id' => 1]);
        $association->load([$order]);

        $this->assertCount(2, $order->posts);
        $this->assertContainsOnlyInstancesOf(DocumentWrapper::class, $order->posts);
        $this->assertSame('wrapped', $order->posts[0]->get('title'));
    }

    /**
     * Tests that a bridge association without autoWrap keeps raw documents.
     *
     * @return void
     */
    public function testNoAutoWrapKeepsRawDocuments(): void
    {
        $this->Posts->saveOrFail($this->Posts->newDocument(['_id' => '000000000000000000000099', 'order_id' => 1, 'title' => 'raw']));

        $association = new BelongsTo('Posts', $this->Orders, [
            'className' => 'BridgePosts',
            'foreignKey' => 'post_id',
            'bindingKey' => '_id',
            'property' => 'post',
            'autoWrap' => false,
        ]);

        $order = new Order(['id' => 1, 'post_id' => '000000000000000000000099']);
        $association->load([$order]);

        $this->assertInstanceOf(Document::class, $order->post);
        $this->assertNotInstanceOf(DocumentWrapper::class, $order->post);
    }
}

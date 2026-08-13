<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\Orm\Bridge;

use Cake\ORM\Table;
use Crustum\Mongo\ODM\Query\SelectQuery;
use Crustum\Mongo\Orm\Bridge\BelongsTo;
use Crustum\Mongo\Orm\Bridge\MongoCollectionAwareInterface;
use Crustum\Mongo\Test\TestCase\ODM\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use TestApp\Model\Entity\Order;

/**
 * Tests the Direction-1 BelongsTo bridge association (SQL row → Mongo doc).
 *
 * The SQL source is a plain Cake entity; the Mongo target is the real
 * `Authors` collection (seeded via Mongo fixtures).
 */
#[CoversClass(BelongsTo::class)]
class BelongsToTest extends TestCase
{
    /**
     * Mongo fixtures for the target collection.
     *
     * @var array<string>
     */
    protected array $fixtures = [
        'plugin.Crustum/Mongo.Authors',
    ];

    /**
     * The Orders source table (bridge-aware fake).
     *
     * @var \Cake\ORM\Table&\Crustum\Mongo\Orm\Bridge\MongoCollectionAwareInterface
     */
    protected Table&MongoCollectionAwareInterface $Orders;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->Orders = $this->fetchTable('TestApp\Model\Table\BridgeOrdersTable');
    }

    /**
     * Tests that BelongsTo loads a single matching Mongo document.
     *
     * @return void
     */
    public function testLoadBelongsTo(): void
    {
        $association = new BelongsTo('Authors', $this->Orders, [
            'property' => 'author',
        ]);

        $order = new Order([
            'id' => 1,
            'customer_name' => 'alice',
            'author_id' => '000000000000000000000002',
        ]);

        $association->load([$order]);

        $this->assertNotNull($order->author);
        $this->assertSame('nate', $order->author->get('name'));
    }

    /**
     * Tests that BelongsTo attaches null when the FK has no match.
     *
     * @return void
     */
    public function testLoadBelongsToMissingMatch(): void
    {
        $association = new BelongsTo('Authors', $this->Orders, [
            'property' => 'author',
        ]);

        $order = new Order([
            'id' => 2,
            'customer_name' => 'bob',
            'author_id' => '000000000000000000000099',
        ]);

        $association->load([$order]);

        $this->assertNull($order->author);
    }

    /**
     * Tests that BelongsTo attaches null when the FK column is empty.
     *
     * @return void
     */
    public function testLoadBelongsToEmptyForeignKey(): void
    {
        $association = new BelongsTo('Authors', $this->Orders, [
            'property' => 'author',
        ]);

        $order = new Order([
            'id' => 3,
            'customer_name' => 'carol',
            'author_id' => null,
        ]);

        $association->load([$order]);

        $this->assertNull($order->author);
    }

    /**
     * Tests that the default foreign key follows the `{name}_id` convention.
     *
     * @return void
     */
    public function testDefaultForeignKeyConvention(): void
    {
        $association = new BelongsTo('Authors', $this->Orders);

        $this->assertSame('author_id', $association->getForeignKey());
        $this->assertSame('_id', $association->getBindingKey());
        $this->assertSame('author', $association->getProperty());
    }

    /**
     * Tests that find() returns an un-executed query against the target.
     *
     * @return void
     */
    public function testFindReturnsLazyQuery(): void
    {
        $association = new BelongsTo('Authors', $this->Orders);

        $query = $association->find();
        $this->assertInstanceOf(SelectQuery::class, $query);
        $this->assertCount(4, $query->all()->toList());
    }

    /**
     * Tests that custom foreign key / property options are honoured.
     *
     * @return void
     */
    public function testCustomForeignKeyAndProperty(): void
    {
        $association = new BelongsTo('Authors', $this->Orders, [
            'foreignKey' => 'author_ref',
            'property' => 'creator',
        ]);

        $this->assertSame('author_ref', $association->getForeignKey());
        $this->assertSame('creator', $association->getProperty());
    }
}

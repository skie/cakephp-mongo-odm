<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM\Behavior;

use Cake\Database\Exception\DatabaseException;
use Cake\Datasource\Exception\RecordNotFoundException;
use Crustum\Mongo\Test\TestCase\ODM\TestCase;

/**
 * Tree behavior test case (Ancestry Array pattern).
 *
 * Mirrors the public surface of `Cake\ORM\Behavior\TreeBehavior` — the
 * `path`/`children`/`treeList` finders, `childCount()`, `getLevel()`,
 * `moveUp()`/`moveDown()`, `removeFromTree()`, `recover()` and the
 * `Collection.beforeSave`/`Collection.beforeDelete` lifecycle — while
 * asserting the ancestry fields (`ancestors`, `depth`, `sort`) instead of the
 * MPTT `lft`/`rght` bookkeeping.
 *
 * @rewritten-from \Cake\Test\TestCase\ORM\Behavior\TreeBehaviorTest
 */
class TreeBehaviorTest extends TestCase
{
    /**
     * fixtures
     *
     * @var array<string>
     */
    protected array $fixtures = [
        'plugin.Crustum/Mongo.MenuLinkTrees',
        'plugin.Crustum/Mongo.NumberTrees',
        'plugin.Crustum/Mongo.NumberTreesArticles',
    ];

    /**
     * @var \Crustum\Mongo\ODM\BaseCollection|\Crustum\Mongo\ODM\Behavior\TreeBehavior
     */
    protected $collection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->collection = $this->getCollectionLocator()->get('NumberTrees');
        $this->collection->addBehavior('Tree', ['level' => 'depth', 'sort' => 'sort']);
    }

    /**
     * Sanity check of the expected initial tree state.
     */
    public function testAssertTreeState(): void
    {
        $flash = $this->collection->get('000000000000000000000008');
        $this->assertSame(
            ['000000000000000000000001', '000000000000000000000006', '000000000000000000000007'],
            $flash->ancestors,
        );
        $this->assertSame(3, $flash->depth);
        $this->assertSame('000000000000000000000007', $flash->parent_id);

        $root = $this->collection->get('000000000000000000000001');
        $this->assertSame([], $root->ancestors);
        $this->assertSame(0, $root->depth);
    }

    /**
     * Tests the find('path') method.
     */
    public function testFindPath(): void
    {
        $nodes = $this->collection->find('path', for: '000000000000000000000009');
        $this->assertSame(
            ['000000000000000000000001', '000000000000000000000006', '000000000000000000000009'],
            $nodes->all()->extract('_id')->toArray(),
        );

        $nodes = $this->collection->find('path', for: '000000000000000000000005');
        $this->assertSame(
            ['000000000000000000000001', '000000000000000000000002', '000000000000000000000005'],
            $nodes->all()->extract('_id')->toArray(),
        );

        $nodes = $this->collection->find('path', for: '000000000000000000000001');
        $this->assertSame(['000000000000000000000001'], $nodes->all()->extract('_id')->toArray());

        // Reparenting must be reflected in the path.
        $newNode = $this->collection->save($this->collection->newDocument(['name' => 'odd one', 'parent_id' => '000000000000000000000001']));
        $node = $this->collection->get('000000000000000000000002');
        $node->parent_id = $newNode->get('_id');

        $this->collection->save($node);

        $nodes = $this->collection->find('path', for: '000000000000000000000004');
        $this->assertSame(
            ['000000000000000000000001', $newNode->get('_id'), '000000000000000000000002', '000000000000000000000004'],
            $nodes->all()->extract('_id')->toArray(),
        );
    }

    /**
     * Tests the childCount() method.
     */
    public function testChildCount(): void
    {
        $behavior = $this->collection->getBehavior('Tree');

        $this->assertSame(2, $behavior->childCount($this->collection->get('000000000000000000000001'), true));
        $this->assertSame(9, $behavior->childCount($this->collection->get('000000000000000000000001'), false));
        $this->assertSame(3, $behavior->childCount($this->collection->get('000000000000000000000002'), false));
        $this->assertSame(4, $behavior->childCount($this->collection->get('000000000000000000000006'), false));
        $this->assertSame(0, $behavior->childCount($this->collection->get('000000000000000000000010'), false));
    }

    /**
     * Tests that childCount works when the node lacks ancestry fields.
     */
    public function testChildCountNoTreeColumns(): void
    {
        $node = $this->collection->get('000000000000000000000006');
        $node->unset('ancestors');
        $this->assertSame(4, $this->collection->getBehavior('Tree')->childCount($node, false));
    }

    /**
     * Tests the find('children') method, scoped and direct.
     */
    public function testFindChildren(): void
    {
        $collection = $this->getCollectionLocator()->get('MenuLinkTrees');
        $collection->addBehavior('Tree', ['scope' => ['menu' => 'main-menu'], 'sort' => 'sort']);

        $nodes = $collection->find('children', for: '000000000000000000000001')->all();
        $this->assertEqualsCanonicalizing(
            ['000000000000000000000002', '000000000000000000000003', '000000000000000000000004', '000000000000000000000005'],
            $nodes->extract('_id')->toArray(),
        );

        $nodes = $collection->find('children', for: '000000000000000000000005')->all();
        $this->assertCount(0, $nodes->extract('_id')->toArray());

        $nodes = $collection->find('children', for: '000000000000000000000001', direct: true)->all();
        $this->assertEqualsCanonicalizing(
            ['000000000000000000000002', '000000000000000000000003'],
            $nodes->extract('_id')->toArray(),
        );
    }

    /**
     * Tests that find('children') throws when the node was not found.
     */
    public function testFindChildrenException(): void
    {
        $this->expectException(RecordNotFoundException::class);
        $collection = $this->getCollectionLocator()->get('MenuLinkTrees');
        $collection->addBehavior('Tree', ['scope' => ['menu' => 'main-menu']]);
        $collection->find('children', for: '000000000000000000000500');
    }

    /**
     * Tests the find('treeList') method.
     */
    public function testFindTreeList(): void
    {
        $collection = $this->getCollectionLocator()->get('MenuLinkTrees');
        $collection->addBehavior('Tree', ['scope' => ['menu' => 'main-menu'], 'sort' => 'sort']);

        $result = $collection->find('treeList')->toArray();
        $expected = [
            '000000000000000000000001' => 'Link 1',
            '000000000000000000000002' => '_Link 2',
            '000000000000000000000003' => '_Link 3',
            '000000000000000000000004' => '__Link 4',
            '000000000000000000000005' => '___Link 5',
            '000000000000000000000006' => 'Link 6',
            '000000000000000000000007' => '_Link 7',
            '000000000000000000000008' => 'Link 8',
        ];
        $this->assertEqualsCanonicalizing($expected, $result);
    }

    /**
     * Tests the find('treeList') method with custom options.
     */
    public function testFindTreeListCustom(): void
    {
        $collection = $this->getCollectionLocator()->get('MenuLinkTrees');
        $collection->addBehavior('Tree', ['scope' => ['menu' => 'main-menu'], 'sort' => 'sort']);

        $result = $collection
            ->find('treeList', keyPath: 'url', valuePath: '_id', spacer: ' ')
            ->toArray();
        $expected = [
            '/link1.html' => '000000000000000000000001',
            'http://example.com' => ' 000000000000000000000002',
            '/what/even-more-links.html' => ' 000000000000000000000003',
            '/lorem/ipsum.html' => '  000000000000000000000004',
            '/what/the.html' => '   000000000000000000000005',
            '/yeah/another-link.html' => '000000000000000000000006',
            'https://cakephp.org' => ' 000000000000000000000007',
            '/page/who-we-are.html' => '000000000000000000000008',
        ];
        $this->assertEqualsCanonicalizing($expected, $result);
    }

    /**
     * Tests the moveUp() method using the sort field.
     */
    public function testMoveUp(): void
    {
        $behavior = $this->collection->getBehavior('Tree');

        // Root group: electronics (sort 0), alien hardware (sort 1).
        $node = $behavior->moveUp($this->collection->get('000000000000000000000011'));
        $this->assertSame(0, $node->sort);
        $this->assertSame(1, $this->collection->get('000000000000000000000001')->sort);

        // First position: does not move.
        $node = $behavior->moveUp($this->collection->get('000000000000000000000011'), 10);
        $this->assertSame(0, $node->sort);

        // Invalid number returns false.
        $this->assertFalse($behavior->moveUp($this->collection->get('000000000000000000000001'), 0));
        $this->assertFalse($behavior->moveUp($this->collection->get('000000000000000000000001'), -10));
    }

    /**
     * Tests moving a node to the first position.
     */
    public function testMoveTop(): void
    {
        $this->collection->getBehavior('Tree')->moveUp($this->collection->get('000000000000000000000011'), true);
        $this->assertSame(0, $this->collection->get('000000000000000000000011')->sort);
        $this->assertSame(1, $this->collection->get('000000000000000000000001')->sort);
    }

    /**
     * Tests the moveDown() method using the sort field.
     */
    public function testMoveDown(): void
    {
        $behavior = $this->collection->getBehavior('Tree');

        $node = $behavior->moveDown($this->collection->get('000000000000000000000001'));
        $this->assertSame(1, $node->sort);
        $this->assertSame(0, $this->collection->get('000000000000000000000011')->sort);

        // Last position: does not move.
        $node = $behavior->moveDown($this->collection->get('000000000000000000000001'), 10);
        $this->assertSame(1, $node->sort);

        // Invalid number returns false.
        $this->assertFalse($behavior->moveDown($this->collection->get('000000000000000000000011'), 0));
    }

    /**
     * Tests moving a node down several positions at once.
     */
    public function testMoveDownMultiplePositions(): void
    {
        // Children of televisions: tube (0), lcd (1), plasma (2).
        $node = $this->collection->getBehavior('Tree')->moveDown($this->collection->get('000000000000000000000003'), 2);
        $this->assertSame(2, $node->sort);
        $this->assertSame(0, $this->collection->get('000000000000000000000004')->sort);
        $this->assertSame(1, $this->collection->get('000000000000000000000005')->sort);
    }

    /**
     * Tests adding a root node (orphan).
     */
    public function testAddOrphan(): void
    {
        $document = $this->collection->newDocument(['name' => 'New Orphan', 'parent_id' => null]);
        $this->assertSame($document, $this->collection->save($document));
        $this->assertSame([], $document->ancestors);
        $this->assertSame(0, $document->depth);
        $this->assertSame(2, $document->sort);
    }

    /**
     * Tests adding a node in the middle of the tree.
     */
    public function testAddMiddle(): void
    {
        $document = $this->collection->save($this->collection->newDocument(['name' => 'laptops', 'parent_id' => '000000000000000000000001']));
        $this->assertSame(['000000000000000000000001'], $document->ancestors);
        $this->assertSame(1, $document->depth);
        $this->assertSame(2, $document->sort);
    }

    /**
     * Tests adding a leaf node.
     */
    public function testAddLeaf(): void
    {
        $document = $this->collection->save($this->collection->newDocument(['name' => 'laptops', 'parent_id' => '000000000000000000000002']));
        $this->assertSame(
            ['000000000000000000000001', '000000000000000000000002'],
            $document->ancestors,
        );
        $this->assertSame(2, $document->depth);
        $this->assertSame(3, $document->sort);
    }

    /**
     * Tests making a node its own parent (existing document).
     */
    public function testReParentSelf(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage("Cannot set a node's parent as itself");
        $document = $this->collection->get('000000000000000000000001');
        $document->parent_id = $document->get('_id');

        $this->collection->save($document);
    }

    /**
     * Tests moving a subtree to a new parent.
     */
    public function testReParentSubTree(): void
    {
        $document = $this->collection->get('000000000000000000000002'); // televisions
        $document->parent_id = '000000000000000000000006'; // portable
        $this->assertSame($document, $this->collection->save($document));

        $this->assertSame(['000000000000000000000001', '000000000000000000000006'], $document->ancestors);
        $this->assertSame(2, $document->depth);

        // Descendants are re-parented too.
        $this->assertSame(
            ['000000000000000000000001', '000000000000000000000006', '000000000000000000000002'],
            $this->collection->get('000000000000000000000003')->ancestors,
        );
        $this->assertSame(3, $this->collection->get('000000000000000000000003')->depth);
        $this->assertSame(
            ['000000000000000000000001', '000000000000000000000006', '000000000000000000000002'],
            $this->collection->get('000000000000000000000005')->ancestors,
        );
    }

    /**
     * Tests moving a subtree to the root.
     */
    public function testRootingSubTree(): void
    {
        $document = $this->collection->get('000000000000000000000002'); // televisions
        $document->parent_id = null;
        $this->assertSame($document, $this->collection->save($document));

        $this->assertSame([], $document->ancestors);
        $this->assertSame(0, $document->depth);

        $this->assertSame(['000000000000000000000002'], $this->collection->get('000000000000000000000003')->ancestors);
        $this->assertSame(1, $this->collection->get('000000000000000000000003')->depth);
        $this->assertSame(['000000000000000000000002'], $this->collection->get('000000000000000000000005')->ancestors);
    }

    /**
     * Tests that creating a cycle throws an exception.
     */
    public function testReparentCycle(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Cannot use node `000000000000000000000005` as parent for entity `000000000000000000000002`.');
        $document = $this->collection->get('000000000000000000000002');
        $document->parent_id = '000000000000000000000005';
         // plasma is inside televisions' subtree
        $this->collection->save($document);
    }

    /**
     * Tests deleting a leaf node.
     */
    public function testDeleteLeaf(): void
    {
        $document = $this->collection->get('000000000000000000000004');
        $this->assertTrue($this->collection->delete($document));

        $this->assertNull($this->collection->find()->where(['name' => 'lcd'])->first());
        // Siblings survive.
        $this->assertNotNull($this->collection->get('000000000000000000000003'));
    }

    /**
     * Tests deleting a whole subtree.
     */
    public function testDeleteSubTree(): void
    {
        $document = $this->collection->get('000000000000000000000006'); // portable
        $this->assertTrue($this->collection->delete($document));

        $this->assertSame(0, $this->collection->find()->where(['name' => 'mp3'])->count());
        $this->assertSame(0, $this->collection->find()->where(['name' => 'radios'])->count());
        // The rest of the tree stays.
        $this->assertNotNull($this->collection->get('000000000000000000000001'));
    }

    /**
     * Tests deleting a subtree in a scoped tree.
     */
    public function testDeleteSubTreeScopedTree(): void
    {
        $collection = $this->getCollectionLocator()->get('MenuLinkTrees');
        $collection->addBehavior('Tree', ['scope' => ['menu' => 'main-menu'], 'sort' => 'sort']);

        $document = $collection->get('000000000000000000000003');
        $this->assertTrue($collection->delete($document));

        // Link 4 and Link 5 are gone, the categories tree is untouched.
        $this->assertSame(0, $collection->find()->where(['title' => 'Link 4'])->count());
        $this->assertSame(0, $collection->find()->where(['title' => 'Link 5'])->count());
        $this->assertSame(10, $collection->find()->where(['menu' => 'categories'])->count());
    }

    /**
     * Tests deleting a root node.
     */
    public function testDeleteRoot(): void
    {
        $document = $this->collection->get('000000000000000000000001');
        $this->assertTrue($this->collection->delete($document));

        $this->assertSame(1, $this->collection->find()->count());
        $this->assertNotNull($this->collection->get('000000000000000000000011'));
    }

    /**
     * Tests removeFromTree: the node becomes a root and its children move up
     * one level.
     */
    public function testRemoveFromTree(): void
    {
        $node = $this->collection->get('000000000000000000000002'); // televisions
        $node = $this->collection->getBehavior('Tree')->removeFromTree($node);

        $this->assertSame([], $node->ancestors);
        $this->assertSame(0, $node->depth);
        $this->assertNull($node->parent_id);

        // Direct children are re-parented to the grandparent.
        $tube = $this->collection->get('000000000000000000000003');
        $this->assertSame('000000000000000000000001', $tube->parent_id);
        $this->assertSame(['000000000000000000000001'], $tube->ancestors);
        $this->assertSame(1, $tube->depth);
    }

    /**
     * Tests the recover() method rebuilding ancestry fields from parent refs.
     */
    public function testRecover(): void
    {
        $this->collection->updateAll(['ancestors' => [], 'depth' => 0], []);

        $this->collection->getBehavior('Tree')->recover();

        $flash = $this->collection->get('000000000000000000000008');
        $this->assertSame(
            ['000000000000000000000001', '000000000000000000000006', '000000000000000000000007'],
            $flash->ancestors,
        );
        $this->assertSame(3, $flash->depth);

        $root = $this->collection->get('000000000000000000000001');
        $this->assertSame([], $root->ancestors);
        $this->assertSame(0, $root->depth);
    }

    /**
     * Tests recover() in a scoped tree.
     */
    public function testRecoverScoped(): void
    {
        $collection = $this->getCollectionLocator()->get('MenuLinkTrees');
        $collection->addBehavior('Tree', ['scope' => ['menu' => 'main-menu'], 'sort' => 'sort']);

        $collection->updateAll(['ancestors' => []], ['menu' => 'main-menu']);
        $collection->getBehavior('Tree')->recover();

        $link5 = $collection->get('000000000000000000000005');
        $this->assertSame(
            ['000000000000000000000001', '000000000000000000000003', '000000000000000000000004'],
            $link5->ancestors,
        );

        // The categories scope is untouched by the scoped recover.
        $electronics = $collection->find()->where(['menu' => 'categories'])->first();
        $this->assertSame([], $electronics->ancestors);
    }

    /**
     * Tests getLevel().
     */
    public function testGetLevel(): void
    {
        $behavior = $this->collection->getBehavior('Tree');
        $this->assertSame(3, $behavior->getLevel('000000000000000000000008'));
        $this->assertSame(0, $behavior->getLevel('000000000000000000000001'));
        $this->assertSame(2, $behavior->getLevel($this->collection->get('000000000000000000000010')));
        $this->assertFalse($behavior->getLevel('000000000000000000000999'));
    }
}

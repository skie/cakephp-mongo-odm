<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM\Association;

use Cake\Datasource\ConnectionManager;
use Crustum\Mongo\ODM\Association\BelongsToMany;
use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\ODM\Document;
use Crustum\Mongo\Test\TestCase\ODM\TestCase;
use Mockery;

/**
 * Tests BelongsToManySaveAssociatedOnlyEntitiesAppendTest class
 *
 * @ported-from \Cake\Test\TestCase\ORM\Association\BelongsToManySaveAssociatedOnlyEntitiesAppendTest
 */
class BelongsToManySaveAssociatedOnlyEntitiesAppendTest extends TestCase
{
    /**
     * @var \Crustum\Mongo\ODM\BaseCollection
     */
    protected $tag;

    /**
     * @var \Crustum\Mongo\ODM\BaseCollection
     */
    protected $article;

    /**
     * Set up
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->tag = new BaseCollection(['alias' => 'Tags', 'collection' => 'tags']);
        $this->tag->setSchemaFromArray([
            '_id' => ['type' => 'integer'],
            'name' => ['type' => 'string'],
            '_constraints' => [
                'primary' => ['type' => 'primary', 'columns' => ['_id']],
            ],
        ]);
        $this->article = new BaseCollection(['alias' => 'Articles', 'collection' => 'articles']);
        $this->article->setSchemaFromArray([
            '_id' => ['type' => 'integer'],
            'name' => ['type' => 'string'],
            '_constraints' => [
                'primary' => ['type' => 'primary', 'columns' => ['_id']],
            ],
        ]);
    }

    /**
     * Test that saveAssociated() ignores non entity values.
     */
    public function testSaveAssociatedOnlyEntitiesAppend(): void
    {
        $connection = ConnectionManager::get('test_mongo');
        /** @var \Cake\Test\TestCase\ORM\Association\MockedCollection&\Mockery\MockInterface $collection */
        $target = new MockedCollection(['collection' => 'tags', 'connection' => $connection]);
        $target->setPrimaryKey('_id');

        $collection = Mockery::mock($target)->makePartial();

        $config = [
            'target' => $collection,
            'saveStrategy' => BelongsToMany::SAVE_APPEND,
        ];

        $document = new Document([
            '_id' => '000000000000000000000001',
            'title' => 'First Post',
            'tags' => [
                ['tag' => 'nope'],
                new Document(['tag' => 'cakephp']),
            ],
        ]);

        $collection->shouldReceive('saveAssociated')->never();

        $association = new BelongsToMany('Tags', $this->article, $config);
        $association->saveAssociated($document);
    }
}

// phpcs:disable
class MockedCollection extends BaseCollection
{
    public function saveAssociated() {}

    public function schema() {}
}

// phpcs:enable

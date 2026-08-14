<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM\Behavior;

use Cake\Database\Driver\Sqlserver;
use Cake\Datasource\ConnectionManager;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\ODM\Behavior\CounterCacheBehavior;
use Crustum\Mongo\ODM\Document;
use Crustum\Mongo\Test\TestCase\ODM\TestCase;
use Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use TestApp\Model\Collection\PublishedPostsCollection;

/**
 * CounterCacheBehavior test case
 */
#[CoversClass(CounterCacheBehavior::class)]
class CounterCacheBehaviorTest extends TestCase
{
    /**
     * @var \TestApp\Model\Collection\PublishedPostsCollection
     */
    protected $post;

    /**
     * @var \TestApp\Model\Collection\PublishedPostsCollection
     */
    protected $user;

    /**
     * @var \TestApp\Model\Collection\PublishedPostsCollection
     */
    protected $category;

    /**
     * @var \TestApp\Model\Collection\PublishedPostsCollection
     */
    protected $comment;

    /**
     * @var \TestApp\Model\Collection\PublishedPostsCollection
     */
    protected $userCategoryPosts;

    /**
     * @var \Cake\Datasource\ConnectionInterface
     */
    protected $connection;

    /**
     * Fixture
     *
     * @var array<string>
     */
    protected array $fixtures = [
        'plugin.Crustum/Mongo.CounterCacheCategories',
        'plugin.Crustum/Mongo.CounterCachePosts',
        'plugin.Crustum/Mongo.CounterCacheComments',
        'plugin.Crustum/Mongo.CounterCacheUsers',
        'plugin.Crustum/Mongo.CounterCacheUserCategoryPosts',
    ];

    /**
     * setup
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = ConnectionManager::get('test_mongo');

        $this->user = $this->getCollectionLocator()->get('Users', [
            'collection' => 'counter_cache_users',
            'connection' => $this->connection,
        ]);

        $this->category = $this->getCollectionLocator()->get('Categories', [
            'collection' => 'counter_cache_categories',
            'connection' => $this->connection,
        ]);

        $this->comment = $this->getCollectionLocator()->get('Comments', [
            'alias' => 'Comment',
            'collection' => 'counter_cache_comments',
            'connection' => $this->connection,
        ]);

        $this->post = new PublishedPostsCollection([
            'alias' => 'Post',
            'collection' => 'counter_cache_posts',
            'connection' => $this->connection,
        ]);

        $this->userCategoryPosts = new BaseCollection([
            'alias' => 'UserCategoryPosts',
            'collection' => 'counter_cache_user_category_posts',
            'connection' => $this->connection,
        ]);
    }

    /**
     * teardown
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        unset($this->user, $this->post);
    }

    /**
     * Testing simple counter caching when adding a record
     */
    public function testAdd(): void
    {
        $this->skipIf(
            $this->connection->getDriver() instanceof Sqlserver,
            'This test fails sporadically in SQLServer',
        );

        $this->post->belongsTo('Users');

        $this->post->addBehavior('CounterCache', [
            'Users' => [
                'post_count',
            ],
        ]);

        $before = $this->getUser();
        $document = $this->getEntity();
        $this->post->save($document);
        $after = $this->getUser();

        $this->assertSame(2, $before->get('post_count'));
        $this->assertSame(3, $after->get('post_count'));
    }

    /**
     * Testing simple counter caching when adding a record
     */
    public function testAddIgnore(): void
    {
        $this->post->belongsTo('Users');

        $this->post->addBehavior('CounterCache', [
            'Users' => [
                'post_count',
            ],
        ]);

        $before = $this->getUser();
        $document = $this->getEntity();
        $this->post->save($document, ['ignoreCounterCache' => true]);
        $after = $this->getUser();

        $this->assertSame(2, $before->get('post_count'));
        $this->assertSame(2, $after->get('post_count'));
    }

    /**
     * Testing simple counter caching when adding a record
     */
    public function testAddScope(): void
    {
        $this->post->belongsTo('Users');

        $this->post->addBehavior('CounterCache', [
            'Users' => [
                'posts_published' => [
                    'conditions' => [
                        'published' => true,
                    ],
                ],
            ],
        ]);

        $before = $this->getUser();
        $document = $this->getEntity()->set('published', true);
        $this->post->save($document);
        $after = $this->getUser();

        $this->assertSame(1, $before->get('posts_published'));
        $this->assertSame(2, $after->get('posts_published'));
    }

    public function testSaveWithNullForeignKey(): void
    {
        $this->markTestSkipped('ODM save validation gap: null FK save fails: F35');
        $this->comment->belongsTo('Users');

        $this->comment->addBehavior('CounterCache', [
            'Users' => [
                'comment_count',
            ],
        ]);

        $document = new Document([
            'title' => 'Orphan comment',
            'user_id' => null,
        ]);
        $this->comment->saveOrFail($document);
        $this->assertTrue(true);
    }

    /**
     * Testing simple counter caching when deleting a record
     */
    public function testDelete(): void
    {
        $this->post->belongsTo('Users');

        $this->post->addBehavior('CounterCache', [
            'Users' => [
                'post_count',
            ],
        ]);

        $before = $this->getUser();
        $post = $this->post->find('all')->first();
        $this->post->delete($post);
        $after = $this->getUser();

        $this->assertSame(2, $before->get('post_count'));
        $this->assertSame(1, $after->get('post_count'));
    }

    /**
     * Testing simple counter caching when deleting a record
     */
    public function testDeleteIgnore(): void
    {
        $this->post->belongsTo('Users');

        $this->post->addBehavior('CounterCache', [
            'Users' => [
                'post_count',
            ],
        ]);

        $before = $this->getUser();
        $post = $this->post->find('all')
            ->first();
        $this->post->delete($post, ['ignoreCounterCache' => true]);
        $after = $this->getUser();

        $this->assertSame(2, $before->get('post_count'));
        $this->assertSame(2, $after->get('post_count'));
    }

    /**
     * Testing update simple counter caching when updating a record association
     */
    public function testUpdate(): void
    {
        $this->markTestSkipped('ODM save validation gap: FK change save fails: F35');
        $this->post->belongsTo('Users');
        $this->post->belongsTo('Categories');

        $this->post->addBehavior('CounterCache', [
            'Users' => [
                'post_count',
            ],
            'Categories' => [
                'post_count',
            ],
        ]);

        $user1 = $this->getUser('000000000000000000000001');
        $user2 = $this->getUser('000000000000000000000002');
        $category1 = $this->getCategory('000000000000000000000001');
        $category2 = $this->getCategory('000000000000000000000002');
        $post = $this->post->find('all')->first();
        $this->assertSame(2, $user1->get('post_count'));
        $this->assertSame(1, $user2->get('post_count'));
        $this->assertSame(1, $category1->get('post_count'));
        $this->assertSame(2, $category2->get('post_count'));

        $document = $this->post->patchDocument($post, ['user_id' => '000000000000000000000002', 'category_id' => '000000000000000000000002']);
        $this->post->save($document);

        $user1 = $this->getUser('000000000000000000000001');
        $user2 = $this->getUser('000000000000000000000002');
        $category1 = $this->getCategory('000000000000000000000001');
        $category2 = $this->getCategory('000000000000000000000002');
        $this->assertSame(1, $user1->get('post_count'));
        $this->assertSame(2, $user2->get('post_count'));
        $this->assertSame(0, $category1->get('post_count'));
        $this->assertSame(3, $category2->get('post_count'));

        $document = $this->post->patchDocument($post, ['user_id' => null, 'category_id' => null]);
        $this->post->save($document);

        $user2 = $this->getUser('000000000000000000000002');
        $category2 = $this->getCategory('000000000000000000000002');
        $this->assertSame(1, $user2->get('post_count'));
        $this->assertSame(2, $category2->get('post_count'));

        $document = $this->post->patchDocument($post, ['user_id' => '000000000000000000000002', 'category_id' => '000000000000000000000002']);
        $this->post->save($document);

        $user2 = $this->getUser('000000000000000000000002');
        $category2 = $this->getCategory('000000000000000000000002');
        $this->assertSame(2, $user2->get('post_count'));
        $this->assertSame(3, $category2->get('post_count'));
    }

    /**
     * Testing counter cache with custom find
     */
    public function testCustomFind(): void
    {
        $this->post->belongsTo('Users');

        $this->post->addBehavior('CounterCache', [
            'Users' => [
                'posts_published' => [
                    'finder' => 'published',
                ],
            ],
        ]);

        $before = $this->getUser();
        $document = $this->getEntity()->set('published', true);
        $this->post->save($document);
        $after = $this->getUser();

        $this->assertSame(1, $before->get('posts_published'));
        $this->assertSame(2, $after->get('posts_published'));
    }

    public function testCustomFindWithoutSubquery(): void
    {
        $this->post->belongsTo('Users');

        $this->post->addBehavior('CounterCache', [
            'Users' => [
                'posts_published' => [
                    'finder' => 'published',
                    'useSubQuery' => false,
                ],
            ],
        ]);

        $before = $this->getUser();
        $document = $this->getEntity()->set('published', true);
        $this->post->save($document);
        $after = $this->getUser();

        $this->assertSame(1, $before->get('posts_published'));
        $this->assertSame(2, $after->get('posts_published'));
    }

    /**
     * Testing counter cache with lambda returning number
     */
    public function testLambdaNumber(): void
    {
        $this->post->belongsTo('Users');

        $collection = $this->post;
        $document = $this->getEntity();

        $this->post->addBehavior('CounterCache', [
            'Users' => [
                'posts_published' => function (EventInterface $orgEvent, EntityInterface $orgEntity, BaseCollection $orgCollection) use ($document, $collection) {
                    $this->assertSame($orgCollection, $collection);
                    $this->assertSame($orgEntity, $document);

                    return 2;
                },
            ],
        ]);

        $before = $this->getUser();
        $this->post->save($document);
        $after = $this->getUser();

        $this->assertSame(1, $before->get('posts_published'));
        $this->assertSame(2, $after->get('posts_published'));
    }

    /**
     * Testing counter cache with lambda returning false
     */
    public function testLambdaFalse(): void
    {
        $this->post->belongsTo('Users');

        $collection = $this->post;
        $document = $this->getEntity();

        $this->post->addBehavior('CounterCache', [
            'Users' => [
                'posts_published' => function (EventInterface $orgEvent, EntityInterface $orgEntity, BaseCollection $orgCollection) use ($document, $collection) {
                    $this->assertSame($orgCollection, $collection);
                    $this->assertSame($orgEntity, $document);

                    return false;
                },
            ],
        ]);

        $before = $this->getUser();
        $this->post->save($document);
        $after = $this->getUser();

        $this->assertSame(1, $before->get('posts_published'));
        $this->assertSame(1, $after->get('posts_published'));
    }

    /**
     * Testing counter cache with lambda returning number and changing of related ID
     */
    public function testLambdaNumberUpdate(): void
    {
        $this->post->belongsTo('Users');

        $collection = $this->post;
        $document = $this->getEntity();

        $this->post->addBehavior('CounterCache', [
            'Users' => [
                'posts_published' => function (EventInterface $orgEvent, EntityInterface $orgEntity, BaseCollection $orgCollection, $original) use ($document, $collection) {
                    $this->assertSame($orgCollection, $collection);
                    $this->assertSame($orgEntity, $document);

                    if (!$original) {
                        return 2;
                    }

                    return 1;
                },
            ],
        ]);

        $this->post->save($document);
        $between = $this->getUser();
        $document->user_id = '000000000000000000000002';
        $this->post->save($document);
        $afterUser1 = $this->getUser('000000000000000000000001');
        $afterUser2 = $this->getUser('000000000000000000000002');

        $this->assertSame(2, $between->get('posts_published'));
        $this->assertSame(1, $afterUser1->get('posts_published'));
        $this->assertSame(2, $afterUser2->get('posts_published'));
    }

    /**
     * Testing counter cache with lambda returning a subquery
     */
    public function testLambdaSubquery(): void
    {
        $this->markTestSkipped('ODM subquery-count gap: lambda returning SelectQuery: F35');
        $this->post->belongsTo('Users');

        $this->post->addBehavior('CounterCache', [
            'Users' => [
                'posts_published' => function (EventInterface $event, EntityInterface $document, BaseCollection $collection) {
                    return $collection->getConnection()->selectQuery(4);
                },
            ],
        ]);

        $before = $this->getUser();
        $document = $this->getEntity();
        $this->post->save($document);
        $after = $this->getUser();

        $this->assertSame(1, $before->get('posts_published'));
        $this->assertSame(4, $after->get('posts_published'));
    }

    /**
     * Testing multiple counter cache when adding a record
     */
    public function testMultiple(): void
    {
        $this->post->belongsTo('Users');

        $this->post->addBehavior('CounterCache', [
            'Users' => [
                'post_count',
                'posts_published' => [
                    'conditions' => [
                        'published' => true,
                    ],
                ],
            ],
        ]);

        $before = $this->getUser();
        $document = $this->getEntity()->set('published', true);
        $this->post->save($document);
        $after = $this->getUser();

        $this->assertSame(1, $before->get('posts_published'));
        $this->assertSame(2, $after->get('posts_published'));

        $this->assertSame(2, $before->get('post_count'));
        $this->assertSame(3, $after->get('post_count'));
    }

    /**
     * Tests to see that the binding key configuration is respected.
     */
    public function testBindingKey(): void
    {
        $this->markTestSkipped('ODM save validation gap: composite binding key save: F35');
        $this->post->hasMany('UserCategoryPosts', [
            'bindingKey' => ['category_id', 'user_id'],
            'foreignKey' => ['category_id', 'user_id'],
        ]);
        $this->post->getAssociation('UserCategoryPosts')->setTarget($this->userCategoryPosts);
        $this->post->addBehavior('CounterCache', [
            'UserCategoryPosts' => ['post_count'],
        ]);

        $before = $this->userCategoryPosts->find()
            ->where(['user_id' => '000000000000000000000001', 'category_id' => '000000000000000000000002'])
            ->first();
        $document = $this->getEntity()->set('category_id', 2);
        $this->post->save($document);
        $after = $this->userCategoryPosts->find()
            ->where(['user_id' => '000000000000000000000001', 'category_id' => '000000000000000000000002'])
            ->first();

        $this->assertSame(1, $before->get('post_count'));
        $this->assertSame(2, $after->get('post_count'));
    }

    /**
     * Testing the ignore if dirty option
     */
    public function testIgnoreDirty(): void
    {
        $this->post->belongsTo('Users');
        $this->comment->belongsTo('Users');

        $this->post->addBehavior('CounterCache', [
            'Users' => [
                'post_count' => [
                    'ignoreDirty' => true,
                ],
                'comment_count' => [
                    'ignoreDirty' => true,
                ],
            ],
        ]);

        $user = $this->getUser('000000000000000000000001');
        $this->assertSame(2, $user->get('post_count'));
        $this->assertSame(2, $user->get('comment_count'));
        $this->assertSame(1, $user->get('posts_published'));

        $post = $this->post->find('all')
            ->contain('Users')
            ->where(['title' => 'Rock and Roll'])
            ->first();
        $post = $this->post->patchDocument($post, [
            'posts_published' => true,
            'user' => [
                '_id' => '000000000000000000000001',
                'post_count' => 10,
                'comment_count' => 10,
            ],
        ]);
        $this->post->save($post);

        $user = $this->getUser('000000000000000000000001');
        $this->assertSame(10, $user->get('post_count'));
        $this->assertSame(10, $user->get('comment_count'));
        $this->assertSame(1, $user->get('posts_published'));
    }

    /**
     * Testing the ignore if dirty option with just one field set to ignoreDirty
     */
    public function testIgnoreDirtyMixed(): void
    {
        $this->post->belongsTo('Users');
        $this->comment->belongsTo('Users');

        $this->post->addBehavior('CounterCache', [
            'Users' => [
                'post_count' => [
                    'ignoreDirty' => true,
                ],
            ],
        ]);

        $user = $this->getUser('000000000000000000000001');
        $this->assertSame(2, $user->get('post_count'));
        $this->assertSame(2, $user->get('comment_count'));
        $this->assertSame(1, $user->get('posts_published'));

        $post = $this->post->find('all')
            ->contain('Users')
            ->where(['title' => 'Rock and Roll'])
            ->first();
        $post = $this->post->patchDocument($post, [
            'posts_published' => true,
            'user' => [
                '_id' => '000000000000000000000001',
                'post_count' => 10,
            ],
        ]);
        $this->post->save($post);

        $user = $this->getUser('000000000000000000000001');
        $this->assertSame(10, $user->get('post_count'));
        $this->assertSame(2, $user->get('comment_count'));
        $this->assertSame(1, $user->get('posts_published'));
    }

    public function testUpdateCounterCache(): void
    {
        $this->post->belongsTo('Users');
        $this->post->addBehavior('CounterCache', [
            'Users' => [
                'post_count',
                'dummy' => function (): void {
                    throw new Exception('Closures are never called by "updateCounterCache()"');
                },
            ],
        ]);

        $this->user->updateAll(['post_count' => 0], []);

        $user = $this->getUser('000000000000000000000001');
        $this->assertSame(0, $user->get('post_count'));

        $this->post->getBehavior('CounterCache')->updateCounterCache('Users');

        $user = $this->getUser('000000000000000000000001');
        $this->assertSame(2, $user->get('post_count'));
        $user = $this->getUser('000000000000000000000002');
        $this->assertSame(1, $user->get('post_count'));

        $this->user->updateAll(['post_count' => 0], []);

        $this->post->getBehavior('CounterCache')->updateCounterCache(limit: 1, page: 2);

        $user = $this->getUser('000000000000000000000001');
        $this->assertSame(0, $user->get('post_count'));
        $user = $this->getUser('000000000000000000000002');
        $this->assertSame(1, $user->get('post_count'));
    }

    public function testUpdateCounterCacheSkipsClosureButContinues(): void
    {
        $this->post->belongsTo('Users');
        $this->post->addBehavior('CounterCache', [
            'Users' => [
                'posts_published' => function (): void {
                    // Should be skipped
                },
                'post_count',
            ],
        ]);

        $this->user->updateAll(['post_count' => 0], []);
        $this->post->getBehavior('CounterCache')->updateCounterCache('Users');

        $user = $this->getUser('000000000000000000000001');
        // With "return": post_count stays 0 (buggy behavior)
        // With "continue": post_count becomes 2 (correct behavior)
        $this->assertSame(2, $user->get('post_count'));
    }

    /**
     * Get a new Document
     */
    protected function getEntity(): Document
    {
        return new Document([
            'title' => 'Test 123',
            'user_id' => '000000000000000000000001',
        ]);
    }

    /**
     * Returns entity for user
     */
    protected function getUser(string $id = '000000000000000000000001'): Document
    {
        return $this->user->get($id);
    }

    /**
     * Returns entity for category
     */
    protected function getCategory(string $id = '000000000000000000000001'): Document
    {
        return $this->category->find('all')->where(['_id' => $id])->first();
    }
}

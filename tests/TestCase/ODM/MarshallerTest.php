<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM;

use Cake\Database\Expression\IdentifierExpression;
use Cake\Event\EventInterface;
use Cake\I18n\DateTime;
use Cake\Validation\Validator;
use Crustum\Mongo\ODM\Association;
use Crustum\Mongo\ODM\Document;
use Crustum\Mongo\ODM\Marshaller;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use TestApp\Model\Document\OpenArticleEntity;
use TestApp\Model\Document\OpenTag;
use TestApp\Model\Document\ProtectedArticle;

/**
 * Marshaller test case
 */
#[CoversClass(Marshaller::class)]
class MarshallerTest extends TestCase
{
    protected array $fixtures = [
        'plugin.Crustum/Mongo.Articles',
        'plugin.Crustum/Mongo.Tags',
        'plugin.Crustum/Mongo.ArticlesTags',
        'plugin.Crustum/Mongo.Comments',
        'plugin.Crustum/Mongo.SpecialTags',
        'plugin.Crustum/Mongo.Users',
    ];

    /**
     * @var \Crustum\Mongo\ODM\BaseCollection
     */
    protected $articles;

    /**
     * @var \Crustum\Mongo\ODM\BaseCollection
     */
    protected $comments;

    /**
     * @var \Crustum\Mongo\ODM\BaseCollection
     */
    protected $users;

    /**
     * @var \Crustum\Mongo\ODM\BaseCollection
     */
    protected $tags;

    /**
     * @var \Crustum\Mongo\ODM\BaseCollection
     */
    protected $articleTags;

    /**
     * setup
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->articles = $this->getCollectionLocator()->get('Articles');
        $this->articles->belongsTo('Users', [
            'foreignKey' => 'author_id',
        ]);
        $this->articles->hasMany('Comments');
        $this->articles->belongsToMany('Tags');

        $this->comments = $this->getCollectionLocator()->get('Comments');
        $this->users = $this->getCollectionLocator()->get('Users');
        $this->tags = $this->getCollectionLocator()->get('Tags');
        $this->articleTags = $this->getCollectionLocator()->get('ArticlesTags');

        $this->comments->belongsTo('Articles');
        $this->comments->belongsTo('Users');

        $this->articles->setDocumentClass(OpenArticleEntity::class);
        $this->comments->setDocumentClass(OpenArticleEntity::class);
        $this->users->setDocumentClass(OpenArticleEntity::class);
        $this->tags->setDocumentClass(OpenArticleEntity::class);
        $this->articleTags->setDocumentClass(OpenArticleEntity::class);
    }

    /**
     * Teardown
     */
    protected function tearDown(): void
    {
        parent::tearDown();
        unset($this->articles, $this->comments, $this->users, $this->tags);
    }

    /**
     * Test one() in a simple use.
     */
    public function testOneSimple(): void
    {
        $data = [
            'title' => 'My title',
            'body' => 'My content',
            'author_id' => 1,
            'not_in_schema' => true,
        ];
        $marshall = new Marshaller($this->articles);
        $result = $marshall->one($data, []);

        $this->assertInstanceOf(Document::class, $result);
        $this->assertEquals($data, $result->toArray());
        $this->assertTrue($result->isDirty(), 'Should be a dirty entity.');
        $this->assertTrue($result->isNew(), 'Should be new');
        $this->assertSame('Articles', $result->getSource());
    }

    /**
     * Test that marshalling an entity with numeric key in data array
     */
    public function testOneWithNumericField(): void
    {
        $data = [
            'sample',
            'username' => 'test',
            'password' => 'secret',
            1,
        ];
        $marshall = new Marshaller($this->articles);
        $result = $marshall->one($data, []);
        $this->assertSame($data[0], $result->get('0'));
        $this->assertSame($data[1], $result->get('1'));
    }

    /**
     * Test that marshalling an entity with '' for pk values results
     * in no pk value being set.
     */
    public function testOneEmptyStringPrimaryKey(): void
    {
        $data = [
            '_id' => '',
            'username' => 'superuser',
            'password' => 'root',
            'created' => new DateTime('2013-10-10 00:00'),
            'updated' => new DateTime('2013-10-10 00:00'),
        ];
        $marshall = new Marshaller($this->articles);
        $result = $marshall->one($data, []);

        $this->assertFalse($result->isDirty('_id'));
        $this->assertNull($result->getId());
    }

    /**
     * Test marshalling datetime/date field.
     */
    public function testOneWithDatetimeField(): void
    {
        $data = [
            'comment' => 'My Comment text',
            'created' => [
                'year' => '2014',
                'month' => '2',
                'day' => 14,
            ],
        ];
        $marshall = new Marshaller($this->comments);
        $result = $marshall->one($data, []);

        $this->assertEquals(new DateTime('2014-02-14 00:00:00'), $result->created);

        $data['created'] = [
            'year' => '2014',
            'month' => '2',
            'day' => 14,
            'hour' => 9,
            'minute' => 25,
            'meridian' => 'pm',
        ];
        $result = $marshall->one($data, []);
        $this->assertEquals(new DateTime('2014-02-14 21:25:00'), $result->created);

        $data['created'] = [
            'year' => '2014',
            'month' => '2',
            'day' => 14,
            'hour' => 9,
            'minute' => 25,
        ];
        $result = $marshall->one($data, []);
        $this->assertEquals(new DateTime('2014-02-14 09:25:00'), $result->created);

        $data['created'] = '2014-02-14 09:25:00';
        $result = $marshall->one($data, []);
        $this->assertEquals(new DateTime('2014-02-14 09:25:00'), $result->created);

        $data['created'] = 1392387900;
        $result = $marshall->one($data, []);
        $this->assertSame($data['created'], $result->created->getTimestamp());
    }

    public function testOneWithFieldMatchingTableAlias(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $articles->getSchema()->addColumn('Articles', ['type' => 'string']);

        $data = ['Articles' => 'a title', 'title' => 'First post', 'body' => 'Content here', 'author_id' => 1];
        $marshall = new Marshaller($articles);
        $result = $marshall->one($data);

        $this->assertEquals($data['Articles'], $result->Articles);
    }

    public function testOneWithGeospatialFields(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $articles->getSchema()
            ->addColumn('geo_line', ['type' => 'linestring'])
            ->addColumn('geo_geometry', ['type' => 'geometry'])
            ->addColumn('geo_point', ['type' => 'point'])
            ->addColumn('geo_polygon', ['type' => 'polygon']);

        $data = [
            'geo_line' => 'LINESTRING(0 0,1 1)',
            'geo_geometry' => 'GEOMETRY(15, 25)',
            'geo_point' => 'POINT(10, 15)',
            'geo_polygon' => 'POLYGON(0 0,6 6,6 12,12 0)',
        ];
        $marshall = new Marshaller($articles);
        $result = $marshall->one($data);

        $this->assertEquals($data['geo_line'], $result->geo_line);
        $this->assertEquals($data['geo_geometry'], $result->geo_geometry);
        $this->assertEquals($data['geo_point'], $result->geo_point);
        $this->assertEquals($data['geo_polygon'], $result->geo_polygon);
    }

    /**
     * Ensure that marshalling casts reasonably.
     */
    public function testOneOnlyCastMatchingData(): void
    {

        $data = [
            'title' => 'My title',
            'body' => 'My content',
            'author_id' => 'derp',
            'created' => 'fale',
        ];
        $this->articles->setDocumentClass(OpenArticleEntity::class);
        $marshall = new Marshaller($this->articles);
        $result = $marshall->one($data, []);

        $this->assertSame($data['title'], $result->title);
        $this->assertNull($result->author_id, 'No cast on bad data.');
        $this->assertSame($data['created'], $result->created, 'No cast on bad data.');
    }

    /**
     * Test one() follows mass-assignment rules.
     */
    public function testOnePatchableProperties(): void
    {
        $data = [
            'title' => 'My title',
            'body' => 'My content',
            'author_id' => 1,
            'not_in_schema' => true,
        ];
        $this->articles->setDocumentClass(ProtectedArticle::class);
        $marshall = new Marshaller($this->articles);
        $result = $marshall->one($data, []);

        $this->assertInstanceOf(ProtectedArticle::class, $result);
        $this->assertNull($result->author_id);
        $this->assertNull($result->not_in_schema);
    }

    /**
     * Test one() supports patchableFields option
     */
    public function testOnePatchableFieldsOption(): void
    {
        $data = [
            'title' => 'My title',
            'body' => 'My content',
            'author_id' => '000000000000000000000001',
            'not_in_schema' => true,
        ];
        $this->articles->setDocumentClass(ProtectedArticle::class);

        $marshall = new Marshaller($this->articles);

        $result = $marshall->one($data, ['patchableFields' => ['body' => false]]);
        $this->assertNull($result->body);

        $result = $marshall->one($data, ['patchableFields' => ['author_id' => true]]);
        $this->assertSame($data['author_id'], $result->author_id);
        $this->assertNull($result->not_in_schema);

        $result = $marshall->one($data, ['patchableFields' => ['*' => true]]);
        $this->assertSame($data['author_id'], $result->author_id);
        $this->assertTrue($result->not_in_schema);
    }

    /**
     * Test one() with an invalid association
     */
    public function testOneInvalidAssociation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot marshal data for `Derp` association. It is not associated with `Articles`.');
        $data = [
            'title' => 'My title',
            'body' => 'My content',
            'derp' => [
                '_id' => '000000000000000000000001',
                'username' => 'mark',
            ],
        ];
        $marshall = new Marshaller($this->articles);
        $marshall->one($data, [
            'associated' => ['Derp'],
        ]);
    }

    /**
     * Test that one() correctly handles an association beforeMarshal
     * making the association empty.
     */
    public function testOneAssociationBeforeMarshalMutation(): void
    {
        $users = $this->getCollectionLocator()->get('Users');
        $articles = $this->getCollectionLocator()->get('Articles');

        $users->hasOne('Articles', [
            'foreignKey' => 'author_id',
        ]);
        $articles->getEventManager()->on('Collection.beforeMarshal', function ($event, $data, $options): void {
            // Blank the association, so it doesn't become dirty.
            unset($data['not_a_real_field']);
        });

        $data = [
            'username' => 'Jen',
            'article' => [
                'not_a_real_field' => 'whatever',
            ],
        ];
        $marshall = new Marshaller($users);
        $document = $marshall->one($data, ['associated' => ['Articles']]);
        $this->assertTrue($document->isDirty('username'));
        $this->assertFalse($document->isDirty('article'));

        // Ensure consistency with merge()
        $document = new Document([
            'username' => 'Jenny',
        ]);
        // Make the entity think it is new.
        $document->setAccess('*', true);
        $document->clean();
        $document = $marshall->merge($document, $data, ['associated' => ['Articles']]);
        $this->assertTrue($document->isDirty('username'));
        $this->assertFalse($document->isDirty('article'));
    }

    /**
     * Test one() supports patchableFields option for associations
     */
    public function testOnePatchableFieldsOptionForAssociations(): void
    {
        $data = [
            'title' => 'My title',
            'body' => 'My content',
            'user' => [
                '_id' => '000000000000000000000001',
                'username' => 'mark',
            ],
        ];
        $this->articles->setDocumentClass(ProtectedArticle::class);
        $this->users->setDocumentClass(ProtectedArticle::class);

        $marshall = new Marshaller($this->articles);

        $result = $marshall->one($data, [
            'associated' => [
                'Users' => ['patchableFields' => ['_id' => true]],
            ],
            'patchableFields' => ['body' => false, 'user' => true],
        ]);
        $this->assertNull($result->body);
        $this->assertNull($result->user->username);
        $this->assertSame('000000000000000000000001', $result->user->getId());
    }

    /**
     * test one() with a wrapping model name.
     */
    public function testOneWithAdditionalName(): void
    {
        $data = [
            'title' => 'Original Title',
            'Articles' => [
                'title' => 'My title',
                'body' => 'My content',
                'author_id' => 1,
                'not_in_schema' => true,
                'user' => [
                    'username' => 'mark',
                ],
            ],
        ];
        $marshall = new Marshaller($this->articles);
        $result = $marshall->one($data, ['associated' => ['Users']]);

        $this->assertInstanceOf(Document::class, $result);
        $this->assertTrue($result->isDirty(), 'Should be a dirty entity.');
        $this->assertTrue($result->isNew(), 'Should be new');
        $this->assertFalse($result->has('Articles'), 'No prefixed field.');
        $this->assertSame($data['title'], $result->title, 'Data from prefix should be merged.');
        $this->assertSame($data['Articles']['user']['username'], $result->user->username);
    }

    /**
     * test one() with association data.
     */
    public function testOneAssociationsSingle(): void
    {
        $data = [
            'title' => 'My title',
            'body' => 'My content',
            'author_id' => '000000000000000000000001',
            'comments' => [
                ['comment' => 'First post', 'user_id' => 2],
                ['comment' => 'Second post', 'user_id' => 2],
            ],
            'user' => [
                'username' => 'mark',
                'password' => 'secret',
            ],
        ];
        $marshall = new Marshaller($this->articles);
        $result = $marshall->one($data, ['associated' => ['Users']]);

        $this->assertSame($data['title'], $result->title);
        $this->assertSame($data['body'], $result->body);
        $this->assertSame($data['author_id'], $result->author_id);

        $this->assertIsArray($result->comments);
        $this->assertEquals($data['comments'], $result->comments);
        $this->assertTrue($result->isDirty('comments'));

        $this->assertInstanceOf(Document::class, $result->user);
        $this->assertTrue($result->isDirty('user'));
        $this->assertSame($data['user']['username'], $result->user->username);
        $this->assertSame($data['user']['password'], $result->user->password);
    }

    /**
     * test one() with association data.
     */
    public function testOneAssociationsMany(): void
    {
        $data = [
            'title' => 'My title',
            'body' => 'My content',
            'author_id' => '000000000000000000000001',
            'comments' => [
                ['comment' => 'First post', 'user_id' => 2],
                ['comment' => 'Second post', 'user_id' => 2],
            ],
            'user' => [
                'username' => 'mark',
                'password' => 'secret',
            ],
        ];
        $marshall = new Marshaller($this->articles);
        $result = $marshall->one($data, ['associated' => ['Comments']]);

        $this->assertSame($data['title'], $result->title);
        $this->assertSame($data['body'], $result->body);
        $this->assertSame($data['author_id'], $result->author_id);

        $this->assertIsArray($result->comments);
        $this->assertCount(2, $result->comments);
        $this->assertInstanceOf(Document::class, $result->comments[0]);
        $this->assertInstanceOf(Document::class, $result->comments[1]);
        $this->assertSame($data['comments'][0]['comment'], $result->comments[0]->comment);

        $this->assertIsArray($result->user);
        $this->assertEquals($data['user'], $result->user);
    }

    /**
     * Test building the _joinData entity for belongstomany associations.
     */
    public function testOneBelongsToManyJoinData(): void
    {
        $data = [
            'title' => 'My title',
            'body' => 'My content',
            'author_id' => 1,
            'tags' => [
                ['tag' => 'news', '_joinData' => ['active' => 1]],
                ['tag' => 'cakephp', '_joinData' => ['active' => 0]],
            ],
        ];
        $marshall = new Marshaller($this->articles);
        $result = $marshall->one($data, [
            'associated' => ['Tags'],
        ]);

        $this->assertSame($data['title'], $result->title);
        $this->assertSame($data['body'], $result->body);

        $this->assertIsArray($result->tags);
        $this->assertInstanceOf(Document::class, $result->tags[0]);
        $this->assertSame($data['tags'][0]['tag'], $result->tags[0]->tag);

        $this->assertInstanceOf(
            Document::class,
            $result->tags[0]->_joinData,
            '_joinData should be an entity.',
        );
        $this->assertSame(
            $data['tags'][0]['_joinData']['active'],
            $result->tags[0]->_joinData->active,
            '_joinData should be an entity.',
        );
    }

    /**
     * Test that the onlyIds option restricts to only accepting ids for belongs to many associations.
     */
    public function testOneBelongsToManyOnlyIdsRejectArray(): void
    {
        $data = [
            'title' => 'My title',
            'body' => 'My content',
            'author_id' => 1,
            'tags' => [
                ['tag' => 'news'],
                ['tag' => 'cakephp'],
            ],
        ];
        $marshall = new Marshaller($this->articles);
        $result = $marshall->one($data, [
            'associated' => ['Tags' => ['onlyIds' => true]],
        ]);
        $this->assertEmpty($result->tags, 'Only ids should be marshalled.');
    }

    /**
     * Test that the onlyIds option restricts to only accepting ids for belongs to many associations.
     */
    public function testOneBelongsToManyOnlyIdsWithIds(): void
    {
        $data = [
            'title' => 'My title',
            'body' => 'My content',
            'author_id' => '000000000000000000000001',
            'tags' => [
                '_ids' => ['000000000000000000000001', '000000000000000000000002'],
                ['tag' => 'news'],
            ],
        ];
        $marshall = new Marshaller($this->articles);
        $result = $marshall->one($data, [
            'associated' => ['Tags' => ['onlyIds' => true]],
        ]);
        $this->assertCount(2, $result->tags, 'Ids should be marshalled.');
    }

    /**
     * Test marshalling nested associations on the _joinData structure.
     */
    public function testOneBelongsToManyJoinDataAssociated(): void
    {
        $data = [
            'title' => 'My title',
            'body' => 'My content',
            'author_id' => 1,
            'tags' => [
                [
                    'tag' => 'news',
                    '_joinData' => [
                        'active' => 1,
                        'user' => ['username' => 'Bill'],
                    ],
                ],
                [
                    'tag' => 'cakephp',
                    '_joinData' => [
                        'active' => 0,
                        'user' => ['username' => 'Mark'],
                    ],
                ],
            ],
        ];

        $articlesTags = $this->getCollectionLocator()->get('ArticlesTags');
        $articlesTags->belongsTo('Users');

        $marshall = new Marshaller($this->articles);
        $result = $marshall->one($data, ['associated' => ['Tags._joinData.Users']]);
        $this->assertInstanceOf(
            Document::class,
            $result->tags[0]->_joinData->user,
            'joinData should contain a user entity.',
        );
        $this->assertSame('Bill', $result->tags[0]->_joinData->user->username);
        $this->assertInstanceOf(
            Document::class,
            $result->tags[1]->_joinData->user,
            'joinData should contain a user entity.',
        );
        $this->assertSame('Mark', $result->tags[1]->_joinData->user->username);

        $data = [
            'title' => 'My title',
            'body' => 'My content',
            'author_id' => 1,
            'tags' => [
                [
                    'tag' => 'news',
                    'junction' => [
                        'active' => 1,
                        'user' => ['username' => 'Bill'],
                    ],
                ],
                [
                    'tag' => 'cakephp',
                    'junction' => [
                        'active' => 0,
                        'user' => ['username' => 'Mark'],
                    ],
                ],
            ],
        ];

        $this->articles->Tags->setJunctionProperty('junction');

        $marshall = new Marshaller($this->articles);
        $result = $marshall->one($data, ['associated' => ['Tags.junction.Users']]);
        $this->assertInstanceOf(
            Document::class,
            $result->tags[0]->junction->user,
            'junction should contain a user entity.',
        );
    }

    /**
     * Test one() with with id and _joinData.
     */
    public function testOneBelongsToManyJoinDataAssociatedWithIds(): void
    {
        $data = [
            'title' => 'My title',
            'body' => 'My content',
            'author_id' => 1,
            'tags' => [
                3 => [
                    '_id' => '000000000000000000000001',
                    '_joinData' => [
                        'active' => 1,
                        'user' => ['username' => 'MyLux'],
                    ],
                ],
                5 => [
                    '_id' => '000000000000000000000002',
                    '_joinData' => [
                        'active' => 0,
                        'user' => ['username' => 'IronFall'],
                    ],
                ],
            ],
        ];

        $articlesTags = $this->getCollectionLocator()->get('ArticlesTags');
        $tags = $this->getCollectionLocator()->get('Tags');
        $t1 = $tags->find('all')->where(['_id' => '000000000000000000000001'])->first();
        $t2 = $tags->find('all')->where(['_id' => '000000000000000000000002'])->first();
        $articlesTags->belongsTo('Users');

        $marshall = new Marshaller($this->articles);
        $result = $marshall->one($data, ['associated' => ['Tags._joinData.Users']]);
        $this->assertInstanceOf(
            Document::class,
            $result->tags[0],
        );
        $this->assertInstanceOf(
            Document::class,
            $result->tags[1],
        );

        $this->assertInstanceOf(
            Document::class,
            $result->tags[0]->_joinData->user,
        );

        $this->assertInstanceOf(
            Document::class,
            $result->tags[1]->_joinData->user,
        );
        $this->assertFalse($result->tags[0]->isNew(), 'Should not be new, as id is in db.');
        $this->assertFalse($result->tags[1]->isNew(), 'Should not be new, as id is in db.');
        $this->assertEquals($t1->tag, $result->tags[0]->tag);
        $this->assertEquals($t2->tag, $result->tags[1]->tag);
        $this->assertSame($data['tags'][3]['_joinData']['user']['username'], $result->tags[0]->_joinData->user->username);
        $this->assertSame($data['tags'][5]['_joinData']['user']['username'], $result->tags[1]->_joinData->user->username);
    }

    /**
     * Test belongsToMany association with mixed data and _joinData
     */
    public function testOneBelongsToManyWithMixedJoinData(): void
    {
        $data = [
            'title' => 'My title',
            'body' => 'My content',
            'author_id' => 1,
            'tags' => [
                [
                    '_id' => '000000000000000000000001',
                    '_joinData' => [
                        'active' => 0,
                    ],
                ],
                [
                    'name' => 'tag5',
                    '_joinData' => [
                        'active' => 1,
                    ],
                ],
            ],
        ];
        $marshall = new Marshaller($this->articles);

        $result = $marshall->one($data, ['associated' => ['Tags._joinData']]);

        $this->assertSame($data['tags'][0]['_id'], $result->tags[0]->getId());
        $this->assertSame($data['tags'][1]['name'], $result->tags[1]->name);
        $this->assertSame(0, $result->tags[0]->_joinData->active);
        $this->assertSame(1, $result->tags[1]->_joinData->active);
    }

    public function testOneBelongsToManyWithNestedAssociations(): void
    {
        $this->tags->belongsToMany('Articles');
        $data = [
            'name' => 'new tag',
            'articles' => [
                // This nested article exists, and we want to update it.
                [
                    '_id' => '000000000000000000000001',
                    'title' => 'New tagged article',
                    'body' => 'New tagged article',
                    'user' => [
                        '_id' => '000000000000000000000001',
                        'username' => 'newuser',
                    ],
                    'comments' => [
                        ['comment' => 'New comment', 'user_id' => 1],
                        ['comment' => 'Second comment', 'user_id' => 1],
                    ],
                ],
            ],
        ];
        $marshaller = new Marshaller($this->tags);
        $tag = $marshaller->one($data, ['associated' => ['Articles.Users', 'Articles.Comments']]);

        $this->assertNotEmpty($tag->articles);
        $this->assertCount(1, $tag->articles);
        $this->assertTrue($tag->isDirty('articles'), 'Updated prop should be dirty');
        $this->assertInstanceOf(Document::class, $tag->articles[0]);
        $this->assertSame('New tagged article', $tag->articles[0]->title);
        $this->assertFalse($tag->articles[0]->isNew());

        $this->assertNotEmpty($tag->articles[0]->user);
        $this->assertInstanceOf(Document::class, $tag->articles[0]->user);
        $this->assertTrue($tag->articles[0]->isDirty('user'), 'Updated prop should be dirty');
        $this->assertSame('newuser', $tag->articles[0]->user->username);
        $this->assertTrue($tag->articles[0]->user->isNew());

        $this->assertNotEmpty($tag->articles[0]->comments);
        $this->assertCount(2, $tag->articles[0]->comments);
        $this->assertTrue($tag->articles[0]->isDirty('comments'), 'Updated prop should be dirty');
        $this->assertInstanceOf(Document::class, $tag->articles[0]->comments[0]);
        $this->assertTrue($tag->articles[0]->comments[0]->isNew());
        $this->assertTrue($tag->articles[0]->comments[1]->isNew());
    }

    /**
     * Same test as @see testOneBelongsToManyWithNestedAssociations
     * just without using dot notation in the marshalling process
     *
     * @return void
     */
    public function testOneBelongsToManyWithNestedAssociationsWithoutDotNotation(): void
    {
        $this->tags->belongsToMany('Articles');
        $data = [
            'name' => 'new tag',
            'articles' => [
                // This nested article exists, and we want to update it.
                [
                    '_id' => '000000000000000000000001',
                    'title' => 'New tagged article',
                    'body' => 'New tagged article',
                    'user' => [
                        '_id' => '000000000000000000000001',
                        'username' => 'newuser',
                    ],
                    'comments' => [
                        ['comment' => 'New comment', 'user_id' => 1],
                        ['comment' => 'Second comment', 'user_id' => 1],
                    ],
                ],
            ],
        ];
        $marshaller = new Marshaller($this->tags);
        $tag = $marshaller->one($data, ['associated' => ['Articles' => ['associated' => ['Users', 'Comments']]]]);

        $this->assertNotEmpty($tag->articles);
        $this->assertCount(1, $tag->articles);
        $this->assertTrue($tag->isDirty('articles'), 'Updated prop should be dirty');
        $this->assertInstanceOf(Document::class, $tag->articles[0]);
        $this->assertSame('New tagged article', $tag->articles[0]->title);
        $this->assertFalse($tag->articles[0]->isNew());

        $this->assertNotEmpty($tag->articles[0]->user);
        $this->assertInstanceOf(Document::class, $tag->articles[0]->user);
        $this->assertTrue($tag->articles[0]->isDirty('user'), 'Updated prop should be dirty');
        $this->assertSame('newuser', $tag->articles[0]->user->username);
        $this->assertTrue($tag->articles[0]->user->isNew());

        $this->assertNotEmpty($tag->articles[0]->comments);
        $this->assertCount(2, $tag->articles[0]->comments);
        $this->assertTrue($tag->articles[0]->isDirty('comments'), 'Updated prop should be dirty');
        $this->assertInstanceOf(Document::class, $tag->articles[0]->comments[0]);
        $this->assertTrue($tag->articles[0]->comments[0]->isNew());
        $this->assertTrue($tag->articles[0]->comments[1]->isNew());

        $tag = $marshaller->one($data, ['associated' => ['Articles' => ['Users', 'Comments']]]);

        $this->assertInstanceOf(Document::class, $tag->articles[0]->user);
        $this->assertSame('newuser', $tag->articles[0]->user->username);
        $this->assertCount(2, $tag->articles[0]->comments);
    }

    /**
     * Test marshalling contain-style nested associations mixed with options.
     */
    public function testOneContainStyleNestedAssociationsWithOptions(): void
    {
        $data = [
            'title' => 'My title',
            'body' => 'My content',
            'comments' => [
                [
                    'comment' => 'First comment',
                    'article_id' => 1,
                    'user' => [
                        'username' => 'mark',
                    ],
                ],
            ],
        ];
        $marshaller = new Marshaller($this->articles);
        $article = $marshaller->one($data, [
            'associated' => [
                'Comments' => [
                    'fields' => ['comment', 'user'],
                    'Users',
                ],
            ],
        ]);

        $this->assertCount(1, $article->comments);
        $this->assertSame('First comment', $article->comments[0]->comment);
        $this->assertNull($article->comments[0]->article_id);
        $this->assertInstanceOf(Document::class, $article->comments[0]->user);
        $this->assertSame('mark', $article->comments[0]->user->username);
    }

    /**
     * Test belongsToMany association with mixed data and _joinData
     */
    public function testBelongsToManyAddingNewExisting(): void
    {
        $this->tags->setDocumentClass(OpenTag::class);
        $data = [
            'title' => 'My title',
            'body' => 'My content',
            'author_id' => 1,
            'tags' => [
                [
                    '_id' => '000000000000000000000001',
                    '_joinData' => [
                        'active' => 0,
                    ],
                ],
            ],
        ];
        $marshall = new Marshaller($this->articles);
        $result = $marshall->one($data, ['associated' => ['Tags._joinData']]);
        $data = [
            'title' => 'New Title',
            'tags' => [
                [
                    '_id' => '000000000000000000000001',
                    '_joinData' => [
                        'active' => 0,
                    ],
                ],
                [
                    '_id' => '000000000000000000000002',
                    '_joinData' => [
                        'active' => 1,
                    ],
                ],
            ],
        ];
        $result = $marshall->merge($result, $data, ['associated' => ['Tags._joinData']]);

        $this->assertSame($data['title'], $result->title);
        $this->assertSame($data['tags'][0]['_id'], $result->tags[0]->getId());
        $this->assertSame($data['tags'][1]['_id'], $result->tags[1]->getId());
        $this->assertNotEmpty($result->tags[0]->_joinData);
        $this->assertNotEmpty($result->tags[1]->_joinData);
        $this->assertTrue($result->isDirty('tags'), 'Modified prop should be dirty');
        $this->assertSame(0, $result->tags[0]->_joinData->active);
        $this->assertSame(1, $result->tags[1]->_joinData->active);

        $document = new Document();
        $document->requireFieldPresence(true);
        // MissingPropertyException should not be thrown for `tags` field
        $marshall->merge($document, $data, ['associated' => ['Tags._joinData']]);

        $inner = new Document(['_id' => '000000000000000000000001']);
        $inner->requireFieldPresence(true);

        $document = new Document([
            'tags' => [
                $inner,
            ],
        ]);
        $document->requireFieldPresence(true);
        // MissingPropertyException should not be thrown for `_joinData` field
        $marshall->merge($document, $data, ['associated' => ['Tags._joinData']]);
    }

    /**
     * Test belongsToMany association with mixed data and _joinData
     */
    public function testBelongsToManyWithMixedJoinDataOutOfOrder(): void
    {
        $data = [
            'title' => 'My title',
            'body' => 'My content',
            'author_id' => 1,
            'tags' => [
                [
                    'name' => 'tag5',
                    '_joinData' => [
                        'active' => 1,
                    ],
                ],
                [
                    '_id' => '000000000000000000000001',
                    '_joinData' => [
                        'active' => 0,
                    ],
                ],
                [
                    'name' => 'tag3',
                    '_joinData' => [
                        'active' => 1,
                    ],
                ],
            ],
        ];
        $marshall = new Marshaller($this->articles);
        $result = $marshall->one($data, ['associated' => ['Tags._joinData']]);

        $this->assertSame($data['tags'][0]['name'], $result->tags[0]->name);
        $this->assertSame($data['tags'][1]['_id'], $result->tags[1]->getId());
        $this->assertSame($data['tags'][2]['name'], $result->tags[2]->name);

        $this->assertSame(1, $result->tags[0]->_joinData->active);
        $this->assertSame(0, $result->tags[1]->_joinData->active);
        $this->assertSame(1, $result->tags[2]->_joinData->active);
    }

    /**
     * Test belongsToMany association with scalars
     */
    public function testBelongsToManyInvalidData(): void
    {
        $data = [
            'title' => 'My title',
            'body' => 'My content',
            'author_id' => 1,
            'tags' => [
                '_id' => '000000000000000000000001',
            ],
        ];

        $article = $this->articles->newDocument($data, [
            'associated' => ['Tags'],
        ]);
        $this->assertEmpty($article->tags, 'No entity should be created');

        $data['tags'] = 1;
        $article = $this->articles->newDocument($data, [
            'associated' => ['Tags'],
        ]);
        $this->assertEmpty($article->tags, 'No entity should be created');
    }

    /**
     * Test belongsToMany association with mixed data array
     */
    public function testBelongsToManyWithMixedData(): void
    {
        $data = [
            'title' => 'My title',
            'body' => 'My content',
            'author_id' => 1,
            'tags' => [
                [
                    'name' => 'tag4',
                ],
                [
                    'name' => 'tag5',
                ],
                [
                    '_id' => '000000000000000000000001',
                ],
            ],
        ];

        $tags = $this->getCollectionLocator()->get('Tags');

        $marshaller = new Marshaller($this->articles);
        $article = $marshaller->one($data, ['associated' => ['Tags']]);

        $this->assertSame($data['tags'][0]['name'], $article->tags[0]->name);
        $this->assertSame($data['tags'][1]['name'], $article->tags[1]->name);
        $this->assertEquals($article->tags[2], $tags->get(1));

        $this->assertTrue($article->tags[0]->isNew());
        $this->assertTrue($article->tags[1]->isNew());
        $this->assertFalse($article->tags[2]->isNew());

        $tagCount = $tags->find()->count();
        $this->articles->save($article);

        $this->assertSame($tagCount + 2, $tags->find()->count());
    }

    /**
     * Test belongsToMany association with the ForceNewTarget to force saving
     * new records on the target tables with BTM relationships when the primaryKey(s)
     * of the target table is specified.
     */
    public function testBelongsToManyWithForceNew(): void
    {
        $data = [
            'title' => 'Fourth Article',
            'body' => 'Fourth Article Body',
            'author_id' => 1,
            'tags' => [
                [
                    '_id' => '000000000000000000000003',
                ],
                [
                    '_id' => '000000000000000000000004',
                    'name' => 'tag4',
                ],
            ],
        ];

        $marshaller = new Marshaller($this->articles);
        $article = $marshaller->one($data, [
            'associated' => ['Tags'],
            'forceNew' => true,
        ]);

        $this->assertFalse($article->tags[0]->isNew(), 'The tag should not be new');
        $this->assertTrue($article->tags[1]->isNew(), 'The tag should be new');
        $this->assertSame('tag4', $article->tags[1]->name, 'Property should match request data.');
    }

    /**
     * Test HasMany association with _ids attribute
     */
    public function testOneHasManyWithIds(): void
    {
        $data = [
            'title' => 'article',
            'body' => 'some content',
            'comments' => [
                '_ids' => [1, 2],
            ],
        ];

        $marshaller = new Marshaller($this->articles);
        $article = $marshaller->one($data, ['associated' => ['Comments']]);

        $this->assertEquals($article->comments[0], $this->comments->get(1));
        $this->assertEquals($article->comments[1], $this->comments->get(2));
    }

    /**
     * Test that the onlyIds option restricts to only accepting ids for hasmany associations.
     */
    public function testOneHasManyOnlyIdsRejectArray(): void
    {
        $data = [
            'title' => 'article',
            'body' => 'some content',
            'comments' => [
                ['comment' => 'first comment'],
                ['comment' => 'second comment'],
            ],
        ];

        $marshaller = new Marshaller($this->articles);
        $article = $marshaller->one($data, [
            'associated' => ['Comments' => ['onlyIds' => true]],
        ]);
        $this->assertEmpty($article->comments);
    }

    /**
     * Test that the onlyIds option restricts to only accepting ids for hasmany associations.
     */
    public function testOneHasManyOnlyIdsWithIds(): void
    {
        $data = [
            'title' => 'article',
            'body' => 'some content',
            'comments' => [
                '_ids' => ['000000000000000000000001', '000000000000000000000002'],
                ['comment' => 'first comment'],
            ],
        ];

        $marshaller = new Marshaller($this->articles);
        $article = $marshaller->one($data, [
            'associated' => ['Comments' => ['onlyIds' => true]],
        ]);
        $this->assertCount(2, $article->comments);
    }

    /**
     * Test HasMany association with invalid data
     */
    public function testOneHasManyInvalidData(): void
    {
        $data = [
            'title' => 'new title',
            'body' => 'some content',
            'comments' => [
                '_id' => '000000000000000000000001',
            ],
        ];

        $marshaller = new Marshaller($this->articles);
        $article = $marshaller->one($data, ['associated' => ['Comments']]);
        $this->assertEmpty($article->comments);

        $data['comments'] = 1;
        $article = $marshaller->one($data, ['associated' => ['Comments']]);
        $this->assertEmpty($article->comments);
    }

    /**
     * Test one() with deeper associations.
     */
    public function testOneDeepAssociations(): void
    {
        $data = [
            'comment' => 'First post',
            'user_id' => 2,
            'article' => [
                'title' => 'Article title',
                'body' => 'Article body',
                'user' => [
                    'username' => 'mark',
                    'password' => 'secret',
                ],
            ],
        ];
        $marshall = new Marshaller($this->comments);
        $result = $marshall->one($data, ['associated' => ['Articles.Users']]);

        $this->assertSame(
            $data['article']['title'],
            $result->article->title,
        );
        $this->assertSame(
            $data['article']['user']['username'],
            $result->article->user->username,
        );
    }

    /**
     * Test many() with a simple set of data.
     */
    public function testManySimple(): void
    {
        $data = [
            ['comment' => 'First post', 'user_id' => 2],
            ['comment' => 'Second post', 'user_id' => 2],
        ];
        $marshall = new Marshaller($this->comments);
        $result = $marshall->many($data);

        $this->assertCount(2, $result);
        $this->assertInstanceOf(Document::class, $result[0]);
        $this->assertInstanceOf(Document::class, $result[1]);
        $this->assertSame($data[0]['comment'], $result[0]->comment);
        $this->assertSame($data[1]['comment'], $result[1]->comment);
    }

    /**
     * Test many() with some invalid data
     */
    public function testManyInvalidData(): void
    {
        $data = [
            ['_id' => '000000000000000000000002', 'comment' => 'Changed 2', 'user_id' => 2],
            ['_id' => '000000000000000000000001', 'comment' => 'Changed 1', 'user_id' => 1],
            '_csrfToken' => 'abc123',
        ];
        $marshall = new Marshaller($this->comments);
        $result = $marshall->many($data);

        $this->assertCount(2, $result);
    }

    /**
     * test many() with nested associations.
     */
    public function testManyAssociations(): void
    {
        $data = [
            [
                'comment' => 'First post',
                'user_id' => 2,
                'user' => [
                    'username' => 'mark',
                ],
            ],
            [
                'comment' => 'Second post',
                'user_id' => 2,
                'user' => [
                    'username' => 'jose',
                ],
            ],
        ];
        $marshall = new Marshaller($this->comments);
        $result = $marshall->many($data, ['associated' => ['Users']]);

        $this->assertCount(2, $result);
        $this->assertInstanceOf(Document::class, $result[0]);
        $this->assertInstanceOf(Document::class, $result[1]);
        $this->assertSame(
            $data[0]['user']['username'],
            $result[0]->user->username,
        );
        $this->assertSame(
            $data[1]['user']['username'],
            $result[1]->user->username,
        );
    }

    /**
     * Test if exception is raised when called with [associated => NonExistentAssociation]
     * Previously such association were simply ignored
     */
    public function testManyInvalidAssociation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $data = [
            [
                'comment' => 'First post',
                'user_id' => 2,
                'user' => [
                    'username' => 'mark',
                ],
            ],
            [
                'comment' => 'Second post',
                'user_id' => 2,
                'user' => [
                    'username' => 'jose',
                ],
            ],
        ];
        $marshall = new Marshaller($this->comments);
        $marshall->many($data, ['associated' => ['Users', 'People']]);
    }

    /**
     * Test generating a list of entities from a list of ids.
     */
    public function testOneGenerateBelongsToManyEntitiesFromIds(): void
    {
        $data = [
            'title' => 'Haz tags',
            'body' => 'Some content here',
            'tags' => ['_ids' => ''],
        ];
        $marshall = new Marshaller($this->articles);
        $result = $marshall->one($data, ['associated' => ['Tags']]);
        $this->assertCount(0, $result->tags);

        $data = [
            'title' => 'Haz tags',
            'body' => 'Some content here',
            'tags' => ['_ids' => false],
        ];
        $result = $marshall->one($data, ['associated' => ['Tags']]);
        $this->assertCount(0, $result->tags);

        $data = [
            'title' => 'Haz tags',
            'body' => 'Some content here',
            'tags' => ['_ids' => null],
        ];
        $result = $marshall->one($data, ['associated' => ['Tags']]);
        $this->assertCount(0, $result->tags);

        $data = [
            'title' => 'Haz tags',
            'body' => 'Some content here',
            'tags' => ['_ids' => []],
        ];
        $result = $marshall->one($data, ['associated' => ['Tags']]);
        $this->assertCount(0, $result->tags);

        $data = [
            'title' => 'Haz tags',
            'body' => 'Some content here',
            'tags' => ['_ids' => [1, 2, 3]],
        ];
        $marshall = new Marshaller($this->articles);
        $result = $marshall->one($data, ['associated' => ['Tags']]);

        $this->assertCount(3, $result->tags);
        $this->assertInstanceOf(Document::class, $result->tags[0]);
        $this->assertInstanceOf(Document::class, $result->tags[1]);
        $this->assertInstanceOf(Document::class, $result->tags[2]);
    }

    /**
     * Test merge() in a simple use.
     */
    public function testMergeSimple(): void
    {
        $data = [
            'title' => 'My title',
            'author_id' => 1,
            'not_in_schema' => true,
        ];
        $marshall = new Marshaller($this->articles);
        $document = new Document([
            'title' => 'Foo',
            'body' => 'My Content',
        ]);
        $document->setAccess('*', true);
        $document->setNew(false);
        $document->clean();

        $result = $marshall->merge($document, $data, []);

        $this->assertSame($document, $result);
        $this->assertEquals($data + ['body' => 'My Content'], $result->toArray());
        $this->assertTrue($result->isDirty(), 'Should be a dirty entity.');
        $this->assertFalse($result->isNew(), 'Should not change the entity state');

        $document = new Document();
        $document->requireFieldPresence(true);
        // MissingPropertyException should not be thrown
        $marshall->merge($document, $data, []);
    }

    /**
     * Test merge() with patchableFields options
     */
    public function testMergePatchableFields(): void
    {
        $data = [
            'title' => 'My title',
            'body' => 'New content',
            'author_id' => 1,
            'not_in_schema' => true,
        ];
        $marshall = new Marshaller($this->articles);
        $document = new Document([
            'title' => 'Foo',
            'body' => 'My Content',
        ]);
        $document->setAccess('*', false);
        $document->setNew(false);
        $document->clean();

        $result = $marshall->merge($document, $data, ['patchableFields' => ['body' => true]]);

        $this->assertSame($document, $result);
        $this->assertEquals(['title' => 'Foo', 'body' => 'New content'], $result->toArray());
        $this->assertTrue($document->isAccessible('body'));
    }

    /**
     * Provides empty values.
     *
     * @return array
     */
    public static function emptyProvider(): array
    {
        return [
            [0],
            ['0'],
        ];
    }

    /**
     * Test merging empty values into an entity.
     *
     * @param mixed $value
     */
    #[DataProvider('emptyProvider')]
    public function testMergeFalseyValues(int|string $value): void
    {

        $marshall = new Marshaller($this->articles);
        $document = new Document();
        $document->setAccess('*', true);
        $document->clean();

        $document = $marshall->merge($document, ['author_id' => $value]);
        $this->assertTrue($document->isDirty('author_id'), 'Field should be dirty');
        // ODM author_id is an ObjectId: int 0 marshals to '0', an invalid
        // ObjectId string ('0') marshals to null.
        $this->assertSame($value === 0 ? '0' : null, $document->get('author_id'), 'Value should be zero');
    }

    /**
     * Test merge() doesn't dirty values that were null and are null again.
     */
    public function testMergeUnchangedNullValue(): void
    {
        $data = [
            'title' => 'My title',
            'author_id' => 1,
            'body' => null,
        ];
        $marshall = new Marshaller($this->articles);
        $document = new Document([
            'title' => 'Foo',
            'body' => null,
        ]);
        $document->setAccess('*', true);
        $document->setNew(false);
        $document->clean();

        $marshall->merge($document, $data, []);

        $this->assertFalse($document->isDirty('body'), 'unchanged null should not be dirty');
    }

    /**
     * Test merge() doesn't dirty objects which are equal.
     */
    public function testMergeWithSameObjectValue(): void
    {
        $created = new DateTime('2020-10-29');
        $document = new Document([
            'comment' => 'foo',
            'created' => $created,
        ]);
        $document->setAccess('*', true);
        $document->setNew(false);
        $document->clean();

        $data = [
            'comment' => 'bar',
            'created' => clone $created,
        ];
        $marshall = new Marshaller($this->comments);
        $marshall->merge($document, $data);

        $this->assertFalse($document->isDirty('created'));
    }

    /**
     * Tests that merge respects the entity patchable methods
     */
    public function testMergeWhitelist(): void
    {
        $data = [
            'title' => 'My title',
            'author_id' => 1,
            'not_in_schema' => true,
        ];
        $marshall = new Marshaller($this->articles);
        $document = new Document([
            'title' => 'Foo',
            'body' => 'My Content',
        ]);
        $document->setAccess('*', false);
        $document->setAccess('author_id', true);
        $document->setNew(false);
        $document->clean();

        $result = $marshall->merge($document, $data, []);

        $expected = [
            'title' => 'Foo',
            'body' => 'My Content',
            'author_id' => 1,
        ];
        $this->assertEquals($expected, $result->toArray());
    }

    /**
     * Test merge() with an invalid association
     */
    public function testMergeInvalidAssociation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot marshal data for `Derp` association. It is not associated with `Articles`.');
        $data = [
            'title' => 'My title',
            'body' => 'My content',
            'derp' => [
                '_id' => '000000000000000000000001',
                'username' => 'mark',
            ],
        ];
        $article = new Document([
           'title' => 'title for post',
           'body' => 'body',
        ]);
        $marshall = new Marshaller($this->articles);
        $marshall->merge($article, $data, [
            'associated' => ['Derp'],
        ]);
    }

    /**
     * Test merge when fields contains an association.
     */
    public function testMergeWithSingleAssociationAndFields(): void
    {
        $user = new Document([
           'username' => 'user',
        ]);
        $article = new Document([
           'title' => 'title for post',
           'body' => 'body',
           'user' => $user,
        ]);

        $user->setAccess('*', true);
        $article->setAccess('*', true);

        $data = [
            'title' => 'Chelsea',
            'user' => [
                'username' => 'dee',
            ],
        ];

        $marshall = new Marshaller($this->articles);
        $marshall->merge($article, $data, [
            'fields' => ['title', 'user'],
            'associated' => ['Users' => []],
        ]);
        $this->assertSame($user, $article->user);
        $this->assertTrue($article->isDirty('user'));
    }

    /**
     * Tests that fields with the same value are not marked as dirty
     */
    public function testMergeDirty(): void
    {
        $marshall = new Marshaller($this->articles);
        $document = new Document([
            'title' => 'Foo',
            'author_id' => '000000000000000000000001',
        ]);
        $data = [
            'title' => 'Foo',
            'author_id' => '000000000000000000000001',
            'crazy' => true,
        ];
        $document->setAccess('*', true);
        $document->clean();

        $result = $marshall->merge($document, $data, []);

        $expected = [
            'title' => 'Foo',
            'author_id' => '000000000000000000000001',
            'crazy' => true,
        ];
        $this->assertEquals($expected, $result->toArray());
        $this->assertFalse($document->isDirty('title'));
        $this->assertFalse($document->isDirty('author_id'));
        $this->assertTrue($document->isDirty('crazy'));

        // https://github.com/cakephp/cakephp/issues/18346
        $document = new class ([
            'title' => 'Foo',
            'author_id' => 1,
        ], ['useSetters' => false]) extends Document {
            protected function _setTitle(string $name): string
            {
                return 'The ' . $name;
            }
        };
        $document->clean();

        $this->assertSame('Foo', $document->title);
        $marshall->merge($document, ['title' => 'Foo', 'author_id' => 2]);
        $this->assertSame('Foo', $document->title, 'Setter should not be called as the value is unchanged');
        $this->assertFalse($document->isDirty('title'));
        $this->assertTrue($document->isDirty('author_id'));
        $this->assertSame('2', $document->author_id);
    }

    /**
     * Tests merging data into an associated entity
     */
    public function testMergeWithSingleAssociation(): void
    {
        $user = new Document([
            'username' => 'mark',
            'password' => 'secret',
        ]);
        $document = new Document([
            'title' => 'My Title',
            'user' => $user,
        ]);
        $user->setAccess('*', true);
        $document->setAccess('*', true);
        $document->clean();

        $data = [
            'body' => 'My Content',
            'user' => [
                'password' => 'not a secret',
            ],
        ];
        $marshall = new Marshaller($this->articles);
        $marshall->merge($document, $data, ['associated' => ['Users']]);

        $this->assertTrue($document->isDirty('user'), 'association should be dirty');
        $this->assertTrue($document->isDirty('body'), 'body should be dirty');
        $this->assertSame('My Content', $document->body);
        $this->assertSame($user, $document->user);
        $this->assertSame('mark', $document->user->username);
        $this->assertSame('not a secret', $document->user->password);
    }

    /**
     * Tests that merging a belongsToMany `_ids` list loads the referenced
     * documents instead of creating bogus integer-keyed documents.
     */
    public function testMergeBelongsToManyIds(): void
    {
        $tags = $this->tags->find()->limit(2)->toArray();
        $this->assertNotEmpty($tags);

        $document = new Document([
            'title' => 'My Title',
            'tags' => [],
        ]);
        $document->setAccess('*', true);
        $document->clean();

        $data = [
            'tags' => [
                '_ids' => [$tags[0]->getId(), $tags[1]->getId()],
            ],
        ];

        $marshall = new Marshaller($this->articles);
        $marshall->merge($document, $data, ['associated' => ['Tags']]);

        $this->assertCount(2, $document->tags, 'Ids should be resolved to documents.');
        foreach ($document->tags as $tag) {
            $this->assertInstanceOf(Document::class, $tag, 'Tag should be a Document, not raw id.');
            $this->assertNotNull($tag->getId());
        }

        $this->assertSame($tags[0]->getId(), $document->tags[0]->getId());
    }

    /**
     * Tests that new associated entities can be created when merging data into
     * a parent entity
     */
    public function testMergeCreateAssociation(): void
    {
        $document = new Document([
            'title' => 'My Title',
        ]);
        $document->setAccess('*', true);
        $document->clean();

        $data = [
            'body' => 'My Content',
            'user' => [
                'username' => 'mark',
                'password' => 'not a secret',
            ],
        ];
        $marshall = new Marshaller($this->articles);
        $marshall->merge($document, $data, ['associated' => ['Users']]);

        $this->assertSame('My Content', $document->body);
        $this->assertInstanceOf(Document::class, $document->user);
        $this->assertSame('mark', $document->user->username);
        $this->assertSame('not a secret', $document->user->password);
        $this->assertTrue($document->isDirty('user'));
        $this->assertTrue($document->isDirty('body'));
        $this->assertTrue($document->user->isNew());
    }

    /**
     * Test merge when an association has been replaced with null
     */
    public function testMergeAssociationNullOut(): void
    {
        $user = new Document([
            '_id' => '000000000000000000000001',
            'username' => 'user',
        ]);
        $article = new Document([
           'title' => 'title for post',
           'user_id' => 1,
           'user' => $user,
        ]);

        $user->setAccess('*', true);
        $article->setAccess('*', true);

        $data = [
            'title' => 'Chelsea',
            'user_id' => '',
            'user' => '',
        ];

        $marshall = new Marshaller($this->articles);
        $marshall->merge($article, $data, [
            'associated' => ['Users'],
        ]);
        $this->assertNull($article->user);
        $this->assertSame('', $article->user_id);
        $this->assertTrue($article->isDirty('user'));
    }

    /**
     * Tests merging one to many associations
     */
    public function testMergeMultipleAssociations(): void
    {
        $user = new Document(['username' => 'mark', 'password' => 'secret']);
        $comment1 = new Document(['_id' => '000000000000000000000001', 'comment' => 'A comment']);
        $comment2 = new Document(['_id' => '000000000000000000000002', 'comment' => 'Another comment']);
        $document = new Document([
            'title' => 'My Title',
            'user' => $user,
            'comments' => [$comment1, $comment2],
        ]);

        $user->setAccess('*', true);
        $comment1->setAccess('*', true);
        $comment2->setAccess('*', true);
        $document->setAccess('*', true);
        $document->clean();

        $data = [
            'title' => 'Another title',
            'user' => ['password' => 'not so secret'],
            'comments' => [
                ['comment' => 'Extra comment 1'],
                ['_id' => '000000000000000000000002', 'comment' => 'Altered comment 2'],
                ['_id' => '000000000000000000000001', 'comment' => 'Altered comment 1'],
                ['_id' => '000000000000000000000003', 'comment' => 'Extra comment 3'],
                ['_id' => '000000000000000000000004', 'comment' => 'Extra comment 4'],
                ['comment' => 'Extra comment 2'],
            ],
        ];
        $marshall = new Marshaller($this->articles);

        $result = $marshall->merge($document, $data, ['associated' => ['Users', 'Comments']]);
        $this->assertSame($document, $result);
        $this->assertSame($user, $result->user);
        $this->assertTrue($result->isDirty('user'), 'association should be dirty');
        $this->assertSame('not so secret', $document->user->password);

        $this->assertTrue($result->isDirty('comments'));
        $this->assertSame($comment1, $document->comments[0]);
        $this->assertSame($comment2, $document->comments[1]);
        $this->assertSame('Altered comment 1', $document->comments[0]->comment);
        $this->assertSame('Altered comment 2', $document->comments[1]->comment);

        $thirdComment = $this->articles->Comments
            ->find()
            ->where(['_id' => '000000000000000000000003'])
            ->enableHydration(false)
            ->first();

        $this->assertEquals(
            ['comment' => 'Extra comment 3'] + $thirdComment,
            $document->comments[2]->toArray(),
        );

        $forthComment = $this->articles->Comments
            ->find()
            ->where(['_id' => '000000000000000000000004'])
            ->enableHydration(false)
            ->first();

        $this->assertEquals(
            ['comment' => 'Extra comment 4'] + $forthComment,
            $document->comments[3]->toArray(),
        );

        $this->assertEquals(
            ['comment' => 'Extra comment 1'],
            $document->comments[4]->toArray(),
        );
        $this->assertEquals(
            ['comment' => 'Extra comment 2'],
            $document->comments[5]->toArray(),
        );
    }

    /**
     * Tests that merging data to a hasMany association with _ids works.
     */
    public function testMergeHasManyEntitiesFromIds(): void
    {
        $document = $this->articles->get(1, ...['contain' => ['Comments']]);
        $this->assertNotEmpty($document->comments);

        $marshall = new Marshaller($this->articles);
        $data = ['comments' => ['_ids' => [1, 2, 3]]];
        $result = $marshall->merge($document, $data, ['associated' => ['Comments']]);

        $this->assertCount(3, $result->comments);
        $this->assertTrue($result->isDirty('comments'), 'Updated prop should be dirty');
        $this->assertInstanceOf(Document::class, $result->comments[0]);
        $this->assertSame(1, $result->comments[0]->getId());
        $this->assertInstanceOf(Document::class, $result->comments[1]);
        $this->assertSame(2, $result->comments[1]->getId());
        $this->assertInstanceOf(Document::class, $result->comments[2]);
        $this->assertSame(3, $result->comments[2]->getId());
    }

    /**
     * Tests that merging data to a hasMany association using onlyIds restricts operations.
     */
    public function testMergeHasManyEntitiesFromIdsOnlyIds(): void
    {
        $document = $this->articles->get(1, ...['contain' => ['Comments']]);
        $this->assertNotEmpty($document->comments);

        $marshall = new Marshaller($this->articles);
        $data = [
            'comments' => [
                '_ids' => [1],
                [
                    'comment' => 'Nope',
                ],
            ],
        ];
        $result = $marshall->merge($document, $data, ['associated' => ['Comments' => ['onlyIds' => true]]]);

        $this->assertCount(1, $result->comments);
        $this->assertTrue($result->isDirty('comments'), 'Updated prop should be dirty');
        $this->assertInstanceOf(Document::class, $result->comments[0]);
        $this->assertNotEquals('Nope', $result->comments[0]->comment);
    }

    /**
     * Tests that merging data to an entity containing belongsToMany and _ids
     * will just overwrite the data
     */
    public function testMergeBelongsToManyEntitiesFromIds(): void
    {
        $document = new Document([
            'title' => 'Haz tags',
            'body' => 'Some content here',
            'tags' => [
                new Document(['_id' => '000000000000000000000001', 'name' => 'Cake']),
                new Document(['_id' => '000000000000000000000002', 'name' => 'PHP']),
            ],
        ]);

        $data = [
            'title' => 'Haz moar tags',
            'tags' => ['_ids' => [1, 2, 3]],
        ];
        $document->setAccess('*', true);
        $document->clean();

        $marshall = new Marshaller($this->articles);
        $result = $marshall->merge($document, $data, ['associated' => ['Tags']]);

        $this->assertCount(3, $result->tags);
        $this->assertTrue($result->isDirty('tags'), 'Updated prop should be dirty');
        $this->assertInstanceOf(Document::class, $result->tags[0]);
        $this->assertInstanceOf(Document::class, $result->tags[1]);
        $this->assertInstanceOf(Document::class, $result->tags[2]);
    }

    /**
     * Tests that merging data to an entity containing belongsToMany and _ids
     * will not generate conflicting queries when associations are automatically selected
     */
    public function testMergeFromIdsWithAutoAssociation(): void
    {
        $document = new Document([
            'title' => 'Haz tags',
            'body' => 'Some content here',
            'tags' => [
                new Document(['_id' => '000000000000000000000001', 'name' => 'Cake']),
                new Document(['_id' => '000000000000000000000002', 'name' => 'PHP']),
            ],
        ]);

        $data = [
            'title' => 'Haz moar tags',
            'tags' => ['_ids' => [1, 2, 3]],
        ];
        $document->setAccess('*', true);
        $document->clean();

        // Adding a forced join to have another table with the same column names
        $this->articles->Tags->getEventManager()->on('Collection.beforeFind', function ($e, $query): void {
            $left = new IdentifierExpression('Tags._id');
            $right = new IdentifierExpression('a._id');
            $query->leftJoin(['a' => 'tags'], $query->expr()->eq($left, $right));
        });

        $marshall = new Marshaller($this->articles);
        $result = $marshall->merge($document, $data, ['associated' => ['Tags']]);

        $this->assertCount(3, $result->tags);
        $this->assertTrue($result->isDirty('tags'));
    }

    /**
     * Tests that merging data to an entity containing belongsToMany and _ids
     * with additional association conditions works.
     */
    public function testMergeBelongsToManyFromIdsWithConditions(): void
    {
        $this->articles->associations()->get('Tags')->setConditions([
            'conditions' => ['ArticleTags.article_id' => 1],
        ]);

        $document = new Document([
            'title' => 'No tags',
            'body' => 'Some content here',
            'tags' => [],
        ]);

        $data = [
            'title' => 'Haz moar tags',
            'tags' => ['_ids' => [1, 2, 3]],
        ];
        $document->setAccess('*', true);
        $document->clean();

        $marshall = new Marshaller($this->articles);
        $result = $marshall->merge($document, $data, ['associated' => ['Tags']]);

        $this->assertCount(3, $result->tags);
        $this->assertTrue($result->isDirty('tags'));
        $this->assertInstanceOf(Document::class, $result->tags[0]);
        $this->assertInstanceOf(Document::class, $result->tags[1]);
        $this->assertInstanceOf(Document::class, $result->tags[2]);
    }

    /**
     * Tests that merging data to an entity containing belongsToMany as an array
     * with additional association conditions works.
     */
    public function testMergeBelongsToManyFromArrayWithConditions(): void
    {
        $this->articles->associations()->get('Tags')->setConditions([
            'conditions' => ['ArticleTags.article_id' => 1],
        ]);

        $this->articles->Tags->getEventManager()
            ->on('Collection.beforeFind', function (EventInterface $event, $query) use (&$called): void {
                $called = true;

                $query->where(['Tags.id >=' => 1]);
            });

        $document = new Document([
            'title' => 'No tags',
            'body' => 'Some content here',
            'tags' => [],
        ]);

        $data = [
            'title' => 'Haz moar tags',
            'tags' => [
                ['_id' => '000000000000000000000001'],
                ['_id' => '000000000000000000000002'],
            ],
        ];
        $document->setAccess('*', true);
        $marshall = new Marshaller($this->articles);
        $result = $marshall->merge($document, $data, ['associated' => ['Tags']]);

        $this->assertCount(2, $result->tags);
        $this->assertInstanceOf(Document::class, $result->tags[0]);
        $this->assertInstanceOf(Document::class, $result->tags[1]);
        $this->assertTrue($called);
    }

    /**
     * Tests that merging data to an entity containing belongsToMany and _ids
     * will ignore empty values.
     */
    public function testMergeBelongsToManyEntitiesFromIdsEmptyValue(): void
    {
        $document = new Document([
            'title' => 'Haz tags',
            'body' => 'Some content here',
            'tags' => [
                new Document(['_id' => '000000000000000000000001', 'name' => 'Cake']),
                new Document(['_id' => '000000000000000000000002', 'name' => 'PHP']),
            ],
        ]);

        $data = [
            'title' => 'Haz moar tags',
            'tags' => ['_ids' => ''],
        ];
        $document->setAccess('*', true);
        $marshall = new Marshaller($this->articles);
        $result = $marshall->merge($document, $data, ['associated' => ['Tags']]);
        $this->assertCount(0, $result->tags);

        $data = [
            'title' => 'Haz moar tags',
            'tags' => ['_ids' => false],
        ];
        $result = $marshall->merge($document, $data, ['associated' => ['Tags']]);
        $this->assertCount(0, $result->tags);

        $data = [
            'title' => 'Haz moar tags',
            'tags' => ['_ids' => null],
        ];
        $result = $marshall->merge($document, $data, ['associated' => ['Tags']]);
        $this->assertCount(0, $result->tags);
        $this->assertTrue($result->isDirty('tags'));
    }

    /**
     * Test that the ids option restricts to only accepting ids for belongs to many associations.
     */
    public function testMergeBelongsToManyOnlyIdsRejectArray(): void
    {
        $document = new Document([
            'title' => 'Haz tags',
            'body' => 'Some content here',
            'tags' => [
                new Document(['_id' => '000000000000000000000001', 'name' => 'Cake']),
                new Document(['_id' => '000000000000000000000002', 'name' => 'PHP']),
            ],
        ]);

        $data = [
            'title' => 'Haz moar tags',
            'tags' => [
                ['name' => 'new'],
                ['name' => 'awesome'],
            ],
        ];
        $document->setAccess('*', true);
        $marshall = new Marshaller($this->articles);
        $result = $marshall->merge($document, $data, [
            'associated' => ['Tags' => ['onlyIds' => true]],
        ]);
        $this->assertCount(0, $result->tags);
        $this->assertTrue($result->isDirty('tags'));
    }

    /**
     * Test that the ids option restricts to only accepting ids for belongs to many associations.
     */
    public function testMergeBelongsToManyOnlyIdsWithIds(): void
    {
        $document = new Document([
            'title' => 'Haz tags',
            'body' => 'Some content here',
            'tags' => [
                new Document(['_id' => '000000000000000000000001', 'name' => 'Cake']),
                new Document(['_id' => '000000000000000000000002', 'name' => 'PHP']),
            ],
        ]);

        $data = [
            'title' => 'Haz moar tags',
            'tags' => [
                '_ids' => [3],
            ],
        ];
        $document->setAccess('*', true);
        $marshall = new Marshaller($this->articles);
        $result = $marshall->merge($document, $data, [
            'associated' => ['Tags' => ['ids' => true]],
        ]);
        $this->assertCount(1, $result->tags);
        $this->assertSame('tag3', $result->tags[0]->name);
        $this->assertTrue($result->isDirty('tags'));
    }

    /**
     * Test that invalid _joinData (scalar data) is not marshalled.
     */
    public function testMergeBelongsToManyJoinDataScalar(): void
    {
        $this->getCollectionLocator()->clear();
        $articles = $this->getCollectionLocator()->get('Articles');
        $articles->belongsToMany('Tags', [
            'through' => 'SpecialTags',
        ]);

        $document = $articles->get(1, ...['contain' => 'Tags']);
        $data = [
            'title' => 'Haz data',
            'tags' => [
                ['_id' => '000000000000000000000003', 'tag' => 'Cake', '_joinData' => 'Invalid'],
            ],
        ];
        $marshall = new Marshaller($articles);
        $marshall->merge($document, $data, ['associated' => 'Tags._joinData']);

        $articles->save($document, ['associated' => ['Tags._joinData']]);
        $this->assertFalse($document->tags[0]->isDirty('_joinData'));
        $this->assertEmpty($document->tags[0]->_joinData);
    }

    /**
     * Test merging the _joinData entity for belongstomany associations when * is not
     * patchable.
     */
    public function testMergeBelongsToManyJoinDataNotPatchable(): void
    {
        $this->getCollectionLocator()->clear();
        $articles = $this->getCollectionLocator()->get('Articles');
        $articles->belongsToMany('Tags', [
            'through' => 'SpecialTags',
        ]);

        $document = $articles->get(1, ...['contain' => 'Tags']);
        // Make only specific fields patchable, but not _joinData.
        $document->tags[0]->setAccess('*', false);
        $document->tags[0]->setAccess(['article_id', 'tag_id'], true);

        $data = [
            'title' => 'Haz data',
            'tags' => [
                ['_id' => '000000000000000000000003', 'tag' => 'Cake', '_joinData' => ['highlighted' => '1', 'author_id' => '99']],
            ],
        ];
        $marshall = new Marshaller($articles);
        $result = $marshall->merge($document, $data, ['associated' => 'Tags._joinData']);

        $this->assertTrue($document->isDirty('tags'), 'Association data changed');
        $this->assertTrue($document->tags[0]->isDirty('_joinData'));
        $this->assertTrue($result->tags[0]->_joinData->isDirty('author_id'), 'Field not modified');
        $this->assertTrue($result->tags[0]->_joinData->isDirty('highlighted'), 'Field not modified');
        $this->assertSame(99, $result->tags[0]->_joinData->author_id);
        $this->assertTrue($result->tags[0]->_joinData->highlighted);
    }

    /**
     * Test that _joinData is marshalled consistently with both
     * new and existing records
     */
    public function testMergeBelongsToManyHandleJoinDataConsistently(): void
    {
        $this->getCollectionLocator()->clear();
        $articles = $this->getCollectionLocator()->get('Articles');
        $articles->belongsToMany('Tags', [
            'through' => 'SpecialTags',
        ]);

        $document = $articles->get(1);
        $data = [
            'title' => 'Haz data',
            'tags' => [
                ['_id' => '000000000000000000000003', 'tag' => 'Cake', '_joinData' => ['highlighted' => true]],
            ],
        ];
        $marshall = new Marshaller($articles);
        $result = $marshall->merge($document, $data, ['associated' => 'Tags']);

        $this->assertTrue($document->isDirty('tags'));
        $this->assertInstanceOf(Document::class, $result->tags[0]->_joinData);
        $this->assertTrue($result->tags[0]->_joinData->highlighted);

        // Also ensure merge() overwrites existing data.
        $document = $articles->get(1, ...['contain' => 'Tags']);
        $data = [
            'title' => 'Haz data',
            'tags' => [
                ['_id' => '000000000000000000000003', 'tag' => 'Cake', '_joinData' => ['highlighted' => true]],
            ],
        ];
        $marshall = new Marshaller($articles);
        $result = $marshall->merge($document, $data, ['associated' => 'Tags']);

        $this->assertTrue($document->isDirty('tags'), 'association data changed');
        $this->assertInstanceOf(Document::class, $result->tags[0]->_joinData);
        $this->assertTrue($result->tags[0]->_joinData->highlighted);
    }

    /**
     * Test merging belongsToMany data doesn't create 'new' entities.
     */
    public function testMergeBelongsToManyJoinDataAssociatedWithIds(): void
    {
        $data = [
            'title' => 'My title',
            'tags' => [
                [
                    '_id' => '000000000000000000000001',
                    '_joinData' => [
                        'active' => 1,
                        'user' => ['username' => 'MyLux'],
                    ],
                ],
                [
                    '_id' => '000000000000000000000002',
                    '_joinData' => [
                        'active' => 0,
                        'user' => ['username' => 'IronFall'],
                    ],
                ],
            ],
        ];
        $articlesTags = $this->getCollectionLocator()->get('ArticlesTags');
        $articlesTags->belongsTo('Users');

        $marshall = new Marshaller($this->articles);
        $article = $this->articles->get(1, ...['associated' => 'Tags']);
        $result = $marshall->merge($article, $data, ['associated' => ['Tags._joinData.Users']]);

        $this->assertTrue($result->isDirty('tags'));
        $this->assertInstanceOf(Document::class, $result->tags[0]);
        $this->assertInstanceOf(Document::class, $result->tags[1]);
        $this->assertInstanceOf(Document::class, $result->tags[0]->_joinData->user);

        $this->assertInstanceOf(Document::class, $result->tags[1]->_joinData->user);
        $this->assertFalse($result->tags[0]->isNew(), 'Should not be new, as id is in db.');
        $this->assertFalse($result->tags[1]->isNew(), 'Should not be new, as id is in db.');
        $this->assertSame(1, $result->tags[0]->getId());
        $this->assertSame(2, $result->tags[1]->getId());

        $this->assertSame(1, $result->tags[0]->_joinData->active);
        $this->assertSame(0, $result->tags[1]->_joinData->active);

        $this->assertSame(
            $data['tags'][0]['_joinData']['user']['username'],
            $result->tags[0]->_joinData->user->username,
        );
        $this->assertSame(
            $data['tags'][1]['_joinData']['user']['username'],
            $result->tags[1]->_joinData->user->username,
        );
    }

    /**
     * Same test as @see testMergeBelongsToManyJoinDataAssociatedWithIds
     * just without using dot notation in the marshalling process
     */
    public function testMergeBelongsToManyJoinDataAssociatedWithIdsWithoutDotNotation(): void
    {
        $data = [
            'title' => 'My title',
            'tags' => [
                [
                    '_id' => '000000000000000000000001',
                    '_joinData' => [
                        'active' => 1,
                        'user' => ['username' => 'MyLux'],
                    ],
                ],
                [
                    '_id' => '000000000000000000000002',
                    '_joinData' => [
                        'active' => 0,
                        'user' => ['username' => 'IronFall'],
                    ],
                ],
            ],
        ];
        $articlesTags = $this->getCollectionLocator()->get('ArticlesTags');
        $articlesTags->belongsTo('Users');

        $marshall = new Marshaller($this->articles);
        $article = $this->articles->get(1, ...['associated' => 'Tags']);
        $result = $marshall->merge($article, $data, ['associated' => [
            'Tags' => [
                'associated' => [
                    '_joinData' => [
                        'associated' => ['Users'],
                    ],
                ],
            ],
        ]]);

        $this->assertTrue($result->isDirty('tags'));
        $this->assertInstanceOf(Document::class, $result->tags[0]);
        $this->assertInstanceOf(Document::class, $result->tags[1]);
        $this->assertInstanceOf(Document::class, $result->tags[0]->_joinData->user);

        $this->assertInstanceOf(Document::class, $result->tags[1]->_joinData->user);
        $this->assertFalse($result->tags[0]->isNew(), 'Should not be new, as id is in db.');
        $this->assertFalse($result->tags[1]->isNew(), 'Should not be new, as id is in db.');
        $this->assertSame(1, $result->tags[0]->getId());
        $this->assertSame(2, $result->tags[1]->getId());

        $this->assertSame(1, $result->tags[0]->_joinData->active);
        $this->assertSame(0, $result->tags[1]->_joinData->active);

        $this->assertSame(
            $data['tags'][0]['_joinData']['user']['username'],
            $result->tags[0]->_joinData->user->username,
        );
        $this->assertSame(
            $data['tags'][1]['_joinData']['user']['username'],
            $result->tags[1]->_joinData->user->username,
        );

        $article = $this->articles->get(1, ...['associated' => 'Tags']);
        $result = $marshall->merge($article, $data, ['associated' => [
            'Tags' => [
                '_joinData' => ['Users'],
            ],
        ]]);

        $this->assertInstanceOf(Document::class, $result->tags[0]->_joinData->user);
        $this->assertSame(
            $data['tags'][0]['_joinData']['user']['username'],
            $result->tags[0]->_joinData->user->username,
        );
    }

    /**
     * Test merging the _joinData entity for belongstomany associations.
     */
    public function testMergeBelongsToManyJoinData(): void
    {
        $initData = [
            'title' => 'My title',
            'body' => 'My content',
            'author_id' => 1,
            'tags' => [
                [
                    '_id' => '000000000000000000000001',
                    'tag' => 'news',
                    '_joinData' => [
                        'active' => 0,
                    ],
                ],
                [
                    '_id' => '000000000000000000000002',
                    'tag' => 'cakephp',
                    '_joinData' => [
                        'active' => 0,
                    ],
                ],
            ],
        ];

        $options = ['associated' => ['Tags._joinData']];
        $marshall = new Marshaller($this->articles);
        $document = $marshall->one($initData, $options);
        $document->setAccess('*', true);

        $data = [
            'title' => 'Haz data',
            'tags' => [
                ['_id' => '000000000000000000000001', 'tag' => 'Cake', '_joinData' => ['foo' => 'bar']],
                ['tag' => 'new tag', '_joinData' => ['active' => 1, 'foo' => 'baz']],
            ],
        ];
        $tag1 = $document->tags[0];

        $result = $marshall->merge($document, $data, $options);

        $this->assertSame($data['title'], $result->title);
        $this->assertSame('My content', $result->body);
        $this->assertTrue($result->isDirty('tags'));
        $this->assertSame($tag1, $document->tags[0]);
        $this->assertSame($tag1->_joinData, $document->tags[0]->_joinData);
        $this->assertSame(
            ['active' => 0, 'foo' => 'bar'],
            $document->tags[0]->_joinData->toArray(),
        );
        $this->assertSame(
            ['active' => 1, 'foo' => 'baz'],
            $document->tags[1]->_joinData->toArray(),
        );
        $this->assertSame('new tag', $document->tags[1]->tag);
        $this->assertTrue($document->tags[0]->isDirty('_joinData'));
        $this->assertTrue($document->tags[1]->isDirty('_joinData'));

        // With custom junction property
        $this->articles->Tags->setJunctionProperty('_junction');
        $initData['tags'][0]['_junction'] = $initData['tags'][0]['_joinData'];
        $initData['tags'][1]['_junction'] = $initData['tags'][1]['_joinData'];
        unset($initData['tags'][0]['_joinData'], $initData['tags'][1]['_joinData']);

        $options = ['associated' => ['Tags._junction']];
        $marshall = new Marshaller($this->articles);
        $document = $marshall->one($initData, $options);
        $document->setAccess('*', true);

        $data = [
            'title' => 'Haz data 2',
            'tags' => [
                ['_id' => '000000000000000000000001', 'tag' => 'Cake 2', '_junction' => ['foo' => 'bar 2']],
                ['tag' => 'new tag 2', '_junction' => ['active' => 1, 'foo' => 'baz 2']],
            ],
        ];
        $tag1 = $document->tags[0];

        $result = $marshall->merge($document, $data, $options);

        $this->assertSame($data['title'], $result->title);
        $this->assertSame('My content', $result->body);
        $this->assertTrue($result->isDirty('tags'));
        $this->assertSame($tag1, $document->tags[0]);
        $this->assertSame($tag1->_junction, $document->tags[0]->_junction);
        $this->assertSame(
            ['active' => 0, 'foo' => 'bar 2'],
            $document->tags[0]->_junction->toArray(),
        );
        $this->assertSame(
            ['active' => 1, 'foo' => 'baz 2'],
            $document->tags[1]->_junction->toArray(),
        );
        $this->assertSame('new tag 2', $document->tags[1]->tag);
        $this->assertTrue($document->tags[0]->isDirty('_junction'));
        $this->assertTrue($document->tags[1]->isDirty('_junction'));
    }

    /**
     * Test merging associations inside _joinData
     */
    public function testMergeJoinDataAssociations(): void
    {
        $data = [
            'title' => 'My title',
            'body' => 'My content',
            'author_id' => 1,
            'tags' => [
                [
                    '_id' => '000000000000000000000001',
                    'tag' => 'news',
                    '_joinData' => [
                        'active' => 0,
                        'user' => ['username' => 'Bill'],
                    ],
                ],
                [
                    '_id' => '000000000000000000000002',
                    'tag' => 'cakephp',
                    '_joinData' => [
                        'active' => 0,
                    ],
                ],
            ],
        ];

        $articlesTags = $this->getCollectionLocator()->get('ArticlesTags');
        $articlesTags->belongsTo('Users');

        $options = ['associated' => ['Tags._joinData.Users']];
        $marshall = new Marshaller($this->articles);
        $document = $marshall->one($data, $options);
        $document->setAccess('*', true);

        $data = [
            'title' => 'Haz data',
            'tags' => [
                [
                    '_id' => '000000000000000000000001',
                    'tag' => 'news',
                    '_joinData' => [
                        'foo' => 'bar',
                        'user' => ['password' => 'secret'],
                    ],
                ],
                [
                    '_id' => '000000000000000000000002',
                    '_joinData' => [
                        'active' => 1,
                        'foo' => 'baz',
                        'user' => ['username' => 'ber'],
                    ],
                ],
            ],
        ];
        $tag1 = $document->tags[0];
        $result = $marshall->merge($document, $data, $options);

        $this->assertSame($data['title'], $result->title);
        $this->assertSame('My content', $result->body);
        $this->assertTrue($document->isDirty('tags'));
        $this->assertSame($tag1, $document->tags[0]);

        $this->assertTrue($tag1->isDirty('_joinData'));
        $this->assertSame($tag1->_joinData, $document->tags[0]->_joinData);
        $this->assertSame('Bill', $document->tags[0]->_joinData->user->username);
        $this->assertSame('secret', $document->tags[0]->_joinData->user->password);
        $this->assertSame('ber', $document->tags[1]->_joinData->user->username);
    }

    /**
     * Tests that merging belongsToMany association doesn't erase _joinData
     * on existing objects.
     */
    public function testMergeBelongsToManyIdsRetainJoinData(): void
    {
        $document = $this->articles->get(1, ...['contain' => ['Tags']]);
        $document->setAccess('*', true);

        $original = $document->tags[0]->_joinData;

        $this->assertInstanceOf(Document::class, $document->tags[0]->_joinData);

        $data = [
            'title' => 'Haz moar tags',
            'tags' => [
                ['_id' => '000000000000000000000001'],
            ],
        ];
        $marshall = new Marshaller($this->articles);
        $result = $marshall->merge($document, $data, ['associated' => ['Tags']]);

        $this->assertCount(1, $result->tags);
        $this->assertTrue($result->isDirty('tags'));
        $this->assertInstanceOf(Document::class, $result->tags[0]);
        $this->assertInstanceOf(Document::class, $result->tags[0]->_joinData);
        $this->assertSame($original, $result->tags[0]->_joinData, 'Should be same object');
    }

    /**
     * Test mergeMany() with a simple set of data.
     */
    public function testMergeManySimple(): void
    {
        $entities = [
            new OpenArticleEntity(['_id' => '000000000000000000000001', 'comment' => 'First post', 'user_id' => '000000000000000000000002']),
            new OpenArticleEntity(['_id' => '000000000000000000000002', 'comment' => 'Second post', 'user_id' => '000000000000000000000002']),
        ];
        $entities[0]->clean();
        $entities[1]->clean();

        $data = [
            ['_id' => '000000000000000000000002', 'comment' => 'Changed 2', 'user_id' => '000000000000000000000002'],
            ['_id' => '000000000000000000000001', 'comment' => 'Changed 1', 'user_id' => '000000000000000000000001'],
        ];
        $marshall = new Marshaller($this->comments);
        $result = $marshall->mergeMany($entities, $data);

        $this->assertSame($entities[0], $result[0]);
        $this->assertSame($entities[1], $result[1]);
        $this->assertSame('Changed 1', $result[0]->comment);
        $this->assertSame('000000000000000000000001', $result[0]->user_id);
        $this->assertSame('Changed 2', $result[1]->comment);
        $this->assertTrue($result[0]->isDirty('user_id'));
        $this->assertFalse($result[1]->isDirty('user_id'));
    }

    /**
     * Test mergeMany() with some invalid data
     */
    public function testMergeManyInvalidData(): void
    {
        $entities = [
            new OpenArticleEntity(['_id' => '000000000000000000000001', 'comment' => 'First post', 'user_id' => 2]),
            new OpenArticleEntity(['_id' => '000000000000000000000002', 'comment' => 'Second post', 'user_id' => 2]),
        ];
        $entities[0]->clean();
        $entities[1]->clean();

        $data = [
            ['_id' => '000000000000000000000002', 'comment' => 'Changed 2', 'user_id' => 2],
            ['_id' => '000000000000000000000001', 'comment' => 'Changed 1', 'user_id' => 1],
            '_csrfToken' => 'abc123',
        ];
        $marshall = new Marshaller($this->comments);
        $result = $marshall->mergeMany($entities, $data);

        $this->assertSame($entities[0], $result[0]);
        $this->assertSame($entities[1], $result[1]);
    }

    /**
     * Tests that only records found in the data array are returned, those that cannot
     * be matched are discarded
     */
    public function testMergeManyWithAppend(): void
    {
        $entities = [
            new OpenArticleEntity(['comment' => 'First post', 'user_id' => 2]),
            new OpenArticleEntity(['_id' => '000000000000000000000002', 'comment' => 'Second post', 'user_id' => 2]),
        ];
        $entities[0]->clean();
        $entities[1]->clean();

        $data = [
            ['_id' => '000000000000000000000002', 'comment' => 'Changed 2', 'user_id' => 2],
            ['_id' => '000000000000000000000001', 'comment' => 'Comment 1', 'user_id' => 1],
        ];
        $marshall = new Marshaller($this->comments);
        $result = $marshall->mergeMany($entities, $data);

        $this->assertCount(2, $result);
        $this->assertNotSame($entities[0], $result[0]);
        $this->assertSame($entities[1], $result[0]);
        $this->assertSame('Changed 2', $result[0]->comment);

        $this->assertSame('Comment 1', $result[1]->comment);
    }

    /**
     * Test that mergeMany() handles composite key associations properly.
     *
     * The articles_tags table has a composite primary key, and should be
     * handled correctly.
     */
    public function testMergeManyCompositeKey(): void
    {
        $articlesTags = $this->getCollectionLocator()->get('ArticlesTags');

        $entities = [
            new OpenArticleEntity(['article_id' => 1, 'tag_id' => 2]),
            new OpenArticleEntity(['article_id' => 1, 'tag_id' => 1]),
        ];
        $entities[0]->clean();
        $entities[1]->clean();

        $data = [
            ['article_id' => 1, 'tag_id' => 1],
            ['article_id' => 1, 'tag_id' => 2],
        ];
        $marshall = new Marshaller($articlesTags);
        $result = $marshall->mergeMany($entities, $data);

        $this->assertCount(2, $result, 'Should have two records');
        $this->assertSame($entities[0], $result[0], 'Should retain object');
        $this->assertSame($entities[1], $result[1], 'Should retain object');
    }

    /**
     * Test mergeMany() with forced contain to ensure aliases are used in queries.
     */
    public function testMergeManyExistingQueryAliases(): void
    {
        $entities = [
            new OpenArticleEntity(['_id' => '000000000000000000000001', 'comment' => 'First post', 'user_id' => 2], ['markClean' => true]),
        ];

        $data = [
            ['_id' => '000000000000000000000001', 'comment' => 'Changed 1', 'user_id' => 1],
            ['_id' => '000000000000000000000002', 'comment' => 'Changed 2', 'user_id' => 2],
        ];
        $this->comments->getEventManager()->on('Collection.beforeFind', function (EventInterface $event, $query): void {
            $query->contain(['Articles']);
        });
        $marshall = new Marshaller($this->comments);
        $result = $marshall->mergeMany($entities, $data);

        $this->assertSame($entities[0], $result[0]);
    }

    /**
     * Test mergeMany() when the exist check returns nothing.
     */
    public function testMergeManyExistQueryFails(): void
    {
        $entities = [
            new Document(['_id' => '000000000000000000000001', 'comment' => 'First post', 'user_id' => 2]),
            new Document(['_id' => '000000000000000000000002', 'comment' => 'Second post', 'user_id' => 2]),
        ];
        $entities[0]->clean();
        $entities[1]->clean();

        $data = [
            ['_id' => '000000000000000000000002', 'comment' => 'Changed 2', 'user_id' => 2],
            ['_id' => '000000000000000000000001', 'comment' => 'Changed 1', 'user_id' => 1],
            ['_id' => '000000000000000000000003', 'comment' => 'New 1'],
        ];
        $comments = $this->getCollectionLocator()->get('GreedyComments', [
            'className' => GreedyCommentsTable::class,
        ]);
        $marshall = new Marshaller($comments);
        $result = $marshall->mergeMany($entities, $data);

        $this->assertCount(3, $result);
        $this->assertSame('Changed 1', $result[0]->comment);
        $this->assertSame(1, $result[0]->user_id);
        $this->assertSame('Changed 2', $result[1]->comment);
        $this->assertSame('New 1', $result[2]->comment);
    }

    /**
     * Tests merge with data types that need to be marshalled
     */
    public function testMergeComplexType(): void
    {
        $document = new Document(
            ['comment' => 'My Comment text'],
            ['markNew' => false, 'markClean' => true],
        );
        $data = [
            'created' => [
                'year' => '2014',
                'month' => '2',
                'day' => 14,
            ],
        ];
        $marshall = new Marshaller($this->comments);
        $marshall->merge($document, $data);
        $this->assertInstanceOf(DateTime::class, $document->created);
        $this->assertSame('2014-02-14', $document->created->format('Y-m-d'));
    }

    /**
     * Tests that it is possible to pass a fields option to the marshaller
     */
    public function testOneWithFields(): void
    {
        $data = [
            'title' => 'My title',
            'body' => 'My content',
            'author_id' => null,
        ];
        $marshall = new Marshaller($this->articles);
        $result = $marshall->one($data, ['fields' => ['title', 'author_id']]);

        $this->assertInstanceOf(Document::class, $result);
        unset($data['body']);
        $this->assertEquals($data, $result->toArray());
    }

    /**
     * Test one() with strictFields option
     */
    public function testOneWithStrictFields(): void
    {
        // Add validation rules
        $this->articles->getValidator()
            ->requirePresence('title')
            ->notEmptyString('title');

        $data = [
            'title' => '',
            'body' => 'My content',
            'author_id' => 'invalid',
        ];
        $marshall = new Marshaller($this->articles);

        // Without strictFields, all fields are validated
        $result = $marshall->one($data, ['fields' => ['body']]);
        $this->assertInstanceOf(Document::class, $result);
        $this->assertEquals(['body' => 'My content'], $result->toArray());
        // We have validation errors for title even though it wasn't in fields
        $this->assertNotEmpty($result->getErrors());

        // With strictFields, only the specified fields are validated
        $result = $marshall->one($data, ['fields' => ['body'], 'strictFields' => true]);
        $this->assertInstanceOf(Document::class, $result);
        $this->assertEquals(['body' => 'My content'], $result->toArray());
        // No validation errors as we only validate the fields list
        $this->assertEmpty($result->getErrors());
    }

    /**
     * Test one() with translations
     */
    public function testOneWithTranslations(): void
    {

        $this->articles->addBehavior('Translate', [
            'fields' => ['title', 'body'],
        ]);

        $data = [
            'author_id' => 1,
            '_translations' => [
                'en' => [
                    'title' => 'English Title',
                    'body' => 'English Content',
                ],
                'es' => [
                    'title' => 'Titulo EspaГ±ol',
                    'body' => 'Contenido EspaГ±ol',
                ],
            ],
            'user' => [
                '_id' => '000000000000000000000001',
                'username' => 'mark',
            ],
        ];

        $marshall = new Marshaller($this->articles);
        $result = $marshall->one($data, ['associated' => ['Users']]);
        $this->assertEmpty($result->getErrors());
        $this->assertSame('1', $result->author_id);
        $this->assertInstanceOf(OpenArticleEntity::class, $result->user);
        $this->assertSame('mark', $result->user->username);

        $translations = $result->get('_translations');
        $this->assertCount(2, $translations);
        $this->assertInstanceOf(OpenArticleEntity::class, $translations['en']);
        $this->assertInstanceOf(OpenArticleEntity::class, $translations['es']);
        $this->assertEquals($data['_translations']['en'], $translations['en']->toArray());
    }

    /**
     * Tests that it is possible to pass a fields option to the merge method
     */
    public function testMergeWithFields(): void
    {
        $data = [
            'title' => 'My title',
            'body' => null,
            'author_id' => 1,
        ];
        $marshall = new Marshaller($this->articles);
        $document = new Document([
            'title' => 'Foo',
            'body' => 'My content',
            'author_id' => 2,
        ]);
        $document->setAccess('*', false);
        $document->setNew(false);
        $document->clean();

        $result = $marshall->merge($document, $data, ['fields' => ['title', 'body']]);

        $expected = [
            'title' => 'My title',
            'body' => null,
            'author_id' => 2,
        ];

        $this->assertSame($document, $result);
        $this->assertEquals($expected, $result->toArray());
        $this->assertFalse($document->isAccessible('*'));
    }

    /**
     * Tests that it is possible to pass a strict fields option to the merge method
     */
    public function testMergeWithFieldsStrict(): void
    {
        $this->articles->getValidator()
            ->requirePresence('title')
            ->notEmptyString('title');

        $data = [
            'title' => null,
            'body' => 'My body',
            'author_id' => 1,
        ];
        $marshall = new Marshaller($this->articles);

        $document = new Document([
            'title' => 'Foo',
            'body' => 'My content',
            'author_id' => 2,
        ]);

        $document->setAccess('*', false);
        $document->setNew(false);
        $document->clean();

        $result = $marshall->merge($document, $data, ['fields' => ['body']]);

        $expected = [
            'title' => 'Foo',
            'body' => 'My body',
            'author_id' => 2,
        ];

        $this->assertSame($document, $result);
        $this->assertEquals($expected, $result->toArray());
        $this->assertFalse($document->isAccessible('*'));
        // We have validation errors though
        $this->assertNotEmpty($document->getErrors());

        $document = new Document([
            'title' => 'Foo',
            'body' => 'My content',
            'author_id' => 2,
        ]);

        $document->setAccess('*', false);
        $document->setNew(false);
        $document->clean();

        $result = $marshall->merge($document, $data, ['fields' => ['body'], 'strictFields' => true]);

        $this->assertSame($document, $result);
        $this->assertEquals($expected, $result->toArray());
        $this->assertFalse($document->isAccessible('*'));
        // We only validate fields list now
        $this->assertEmpty($document->getErrors());
    }

    /**
     * Test that many() also receives a fields option
     */
    public function testManyFields(): void
    {
        $data = [
            ['comment' => 'First post', 'user_id' => 2, 'foo' => 'bar'],
            ['comment' => 'Second post', 'user_id' => 2, 'foo' => 'bar'],
        ];
        $marshall = new Marshaller($this->comments);
        $result = $marshall->many($data, ['fields' => ['comment', 'user_id']]);

        $this->assertCount(2, $result);
        unset($data[0]['foo'], $data[1]['foo']);
        $this->assertEquals($data[0], $result[0]->toArray());
        $this->assertEquals($data[1], $result[1]->toArray());
    }

    /**
     * Test that many() also receives a fields option
     */
    public function testMergeManyFields(): void
    {
        $entities = [
            new OpenArticleEntity(['_id' => '000000000000000000000001', 'comment' => 'First post', 'user_id' => 2]),
            new OpenArticleEntity(['_id' => '000000000000000000000002', 'comment' => 'Second post', 'user_id' => 2]),
        ];
        $entities[0]->clean();
        $entities[1]->clean();

        $data = [
            ['_id' => '000000000000000000000002', 'comment' => 'Changed 2', 'user_id' => 10],
            ['_id' => '000000000000000000000001', 'comment' => 'Changed 1', 'user_id' => 20],
        ];
        $marshall = new Marshaller($this->comments);
        $result = $marshall->mergeMany($entities, $data, ['fields' => ['_id', 'comment']]);

        $this->assertSame($entities[0], $result[0]);
        $this->assertSame($entities[1], $result[1]);

        $expected = ['_id' => '000000000000000000000002', 'comment' => 'Changed 2', 'user_id' => 2];
        $this->assertEquals($expected, $entities[1]->toArray());

        $expected = ['_id' => '000000000000000000000001', 'comment' => 'Changed 1', 'user_id' => 2];
        $this->assertEquals($expected, $entities[0]->toArray());
    }

    /**
     * test marshalling association data while passing a fields
     */
    public function testAssociationsFields(): void
    {
        $data = [
            'title' => 'My title',
            'body' => 'My content',
            'author_id' => 1,
            'user' => [
                'username' => 'mark',
                'password' => 'secret',
                'foo' => 'bar',
            ],
        ];
        $marshall = new Marshaller($this->articles);
        $result = $marshall->one($data, [
            'fields' => ['title', 'body', 'user'],
            'associated' => [
                'Users' => ['fields' => ['username', 'foo']],
            ],
        ]);

        $this->assertSame($data['title'], $result->title);
        $this->assertSame($data['body'], $result->body);
        $this->assertNull($result->author_id);

        $this->assertInstanceOf(Document::class, $result->user);
        $this->assertSame($data['user']['username'], $result->user->username);
        $this->assertNull($result->user->password);
    }

    /**
     * Tests merging associated data with a fields
     */
    public function testMergeAssociationWithfields(): void
    {
        $user = new Document([
            'username' => 'mark',
            'password' => 'secret',
        ]);
        $document = new Document([
            'tile' => 'My Title',
            'user' => $user,
        ]);
        $user->setAccess('*', true);
        $document->setAccess('*', true);

        $data = [
            'body' => 'My Content',
            'something' => 'else',
            'user' => [
                'password' => 'not a secret',
                'extra' => 'data',
            ],
        ];
        $marshall = new Marshaller($this->articles);
        $marshall->merge($document, $data, [
            'fields' => ['something'],
            'associated' => ['Users' => ['fields' => ['extra']]],
        ]);
        $this->assertNull($document->body);
        $this->assertSame('else', $document->something);
        $this->assertSame($user, $document->user);
        $this->assertSame('mark', $document->user->username);
        $this->assertSame('secret', $document->user->password);
        $this->assertSame('data', $document->user->extra);
        $this->assertTrue($document->isDirty('user'));
    }

    /**
     * Test marshalling nested associations on the _joinData structure
     * while having a fields
     */
    public function testJoinDataWhiteList(): void
    {
        $data = [
            'title' => 'My title',
            'body' => 'My content',
            'author_id' => 1,
            'tags' => [
                [
                    'tag' => 'news',
                    '_joinData' => [
                        'active' => 1,
                        'crazy' => 'data',
                        'user' => ['username' => 'Bill'],
                    ],
                ],
                [
                    'tag' => 'cakephp',
                    '_joinData' => [
                        'active' => 0,
                        'crazy' => 'stuff',
                        'user' => ['username' => 'Mark'],
                    ],
                ],
            ],
        ];

        $articlesTags = $this->getCollectionLocator()->get('ArticlesTags');
        $articlesTags->belongsTo('Users');

        $marshall = new Marshaller($this->articles);
        $result = $marshall->one($data, [
            'associated' => [
                'Tags._joinData' => ['fields' => ['active', 'user']],
                'Tags._joinData.Users',
            ],
        ]);
        $this->assertInstanceOf(
            Document::class,
            $result->tags[0]->_joinData->user,
            'joinData should contain a user entity.',
        );
        $this->assertSame('Bill', $result->tags[0]->_joinData->user->username);
        $this->assertInstanceOf(
            Document::class,
            $result->tags[1]->_joinData->user,
            'joinData should contain a user entity.',
        );
        $this->assertSame('Mark', $result->tags[1]->_joinData->user->username);

        $this->assertNull($result->tags[0]->_joinData->crazy);
        $this->assertNull($result->tags[1]->_joinData->crazy);
    }

    /**
     * Test merging the _joinData entity for belongstomany associations
     * while passing a whitelist
     */
    public function testMergeJoinDataWithFields(): void
    {
        $data = [
            'title' => 'My title',
            'body' => 'My content',
            'author_id' => 1,
            'tags' => [
                [
                    '_id' => '000000000000000000000001',
                    'tag' => 'news',
                    '_joinData' => [
                        'active' => 0,
                    ],
                ],
                [
                    '_id' => '000000000000000000000002',
                    'tag' => 'cakephp',
                    '_joinData' => [
                        'active' => 0,
                    ],
                ],
            ],
        ];

        $options = ['associated' => ['Tags' => ['associated' => ['_joinData']]]];
        $marshall = new Marshaller($this->articles);
        $document = $marshall->one($data, $options);
        $document->setAccess('*', true);

        $data = [
            'title' => 'Haz data',
            'tags' => [
                ['_id' => '000000000000000000000001', 'tag' => 'Cake', '_joinData' => ['foo' => 'bar', 'crazy' => 'something']],
                ['tag' => 'new tag', '_joinData' => ['active' => 1, 'foo' => 'baz']],
            ],
        ];

        $tag1 = $document->tags[0];
        $result = $marshall->merge($document, $data, [
            'associated' => ['Tags._joinData' => ['fields' => ['foo']]],
        ]);
        $this->assertSame($data['title'], $result->title);
        $this->assertSame('My content', $result->body);
        $this->assertSame($tag1, $document->tags[0]);
        $this->assertSame($tag1->_joinData, $document->tags[0]->_joinData);
        $this->assertSame(
            ['active' => 0, 'foo' => 'bar'],
            $document->tags[0]->_joinData->toArray(),
        );
        $this->assertSame(
            ['foo' => 'baz'],
            $document->tags[1]->_joinData->toArray(),
        );
        $this->assertSame('new tag', $document->tags[1]->tag);
        $this->assertTrue($document->tags[0]->isDirty('_joinData'));
        $this->assertTrue($document->tags[1]->isDirty('_joinData'));
    }

    /**
     * Tests marshalling with validation errors
     */
    public function testValidationFail(): void
    {
        $data = [
            'title' => 'Thing',
            'body' => 'hey',
        ];

        $this->articles->getValidator()->requirePresence('thing');
        $marshall = new Marshaller($this->articles);
        $document = $marshall->one($data);
        $this->assertNotEmpty($document->getError('thing'));
    }

    /**
     * Tests that associations are validated and custom validators can be used
     */
    public function testValidateWithAssociationsAndCustomValidator(): void
    {
        $data = [
            'title' => 'foo',
            'body' => 'bar',
            'user' => [
                'name' => 'Susan',
            ],
            'comments' => [
                [
                    'comment' => 'foo',
                ],
            ],
        ];
        $validator = new Validator()->add('body', 'numeric', ['rule' => 'numeric']);
        $this->articles->setValidator('custom', $validator);

        $validator2 = new Validator()->requirePresence('thing');
        $this->articles->Users->setValidator('customThing', $validator2);

        $this->articles->Comments->setValidator('default', $validator2);

        $document = new Marshaller($this->articles)->one($data, [
            'validate' => 'custom',
            'associated' => ['Users', 'Comments'],
        ]);
        $this->assertNotEmpty($document->getError('body'), 'custom was not used');
        $this->assertNull($document->body);
        $this->assertEmpty($document->user->getError('thing'));
        $this->assertNotEmpty($document->comments[0]->getError('thing'));

        $document = new Marshaller($this->articles)->one($data, [
            'validate' => 'custom',
            'associated' => ['Users' => ['validate' => 'customThing'], 'Comments'],
        ]);
        $this->assertNotEmpty($document->getError('body'));
        $this->assertNull($document->body);
        $this->assertNotEmpty($document->user->getError('thing'), 'customThing was not used');
        $this->assertNotEmpty($document->comments[0]->getError('thing'));
    }

    /**
     * Tests that validation can be bypassed
     */
    public function testSkipValidation(): void
    {
        $data = [
            'title' => 'foo',
            'body' => 'bar',
            'user' => [
                'name' => 'Susan',
            ],
        ];
        $validator = new Validator()->requirePresence('thing');
        $this->articles->setValidator('default', $validator);
        $this->articles->Users->setValidator('default', $validator);

        $document = new Marshaller($this->articles)->one($data, [
            'validate' => false,
            'associated' => ['Users'],
        ]);
        $this->assertEmpty($document->getError('thing'));
        $this->assertNotEmpty($document->user->getError('thing'));

        $document = new Marshaller($this->articles)->one($data, [
            'associated' => ['Users' => ['validate' => false]],
        ]);
        $this->assertNotEmpty($document->getError('thing'));
        $this->assertEmpty($document->user->getError('thing'));
    }

    /**
     * Tests that invalid property is being filled when data cannot be patched into an entity.
     */
    public function testValidationWithInvalidFilled(): void
    {
        $data = [
            'title' => 'foo',
            'number' => 'bar',
        ];
        $this->articles->setValidator(
            'custom',
            new Validator()->add('number', 'numeric', ['rule' => 'numeric']),
        );
        $marshall = new Marshaller($this->articles);
        $document = $marshall->one($data, ['validate' => 'custom']);
        $this->assertNotEmpty($document->getError('number'));
        $this->assertNull($document->number);
        $this->assertSame(['number' => 'bar'], $document->getInvalid());
    }

    /**
     * Test merge with validation error
     */
    public function testMergeWithValidation(): void
    {
        $data = [
            'title' => 'My title',
            'author_id' => 'foo',
        ];
        $marshall = new Marshaller($this->articles);
        $document = new Document([
            '_id' => '000000000000000000000001',
            'title' => 'Foo',
            'body' => 'My Content',
            'author_id' => 1,
        ]);
        $this->assertEmpty($document->getInvalid());

        $document->setAccess('*', true);
        $document->setNew(false);
        $document->clean();

        $this->articles->getValidator()
            ->requirePresence('thing', 'update')
            ->requirePresence('_id', 'update')
            ->add('author_id', 'numeric', ['rule' => 'numeric'])
            ->add('_id', 'numeric', ['rule' => 'numeric', 'on' => 'update']);

        $expected = clone $document;
        $result = $marshall->merge($expected, $data, []);

        $this->assertSame($expected, $result);
        $this->assertSame(1, $result->author_id);
        $this->assertNotEmpty($result->getError('thing'));
        $this->assertEmpty($result->getError('_id'));

        $this->articles->getValidator()->requirePresence('thing', 'create');
        $result = $marshall->merge($document, $data, []);

        $this->assertEmpty($result->getError('thing'));
        $this->assertSame(['author_id' => 'foo'], $result->getInvalid());
    }

    /**
     * Test merge with validation and create or update validation rules
     */
    public function testMergeWithCreate(): void
    {
        $data = [
            'title' => 'My title',
            'author_id' => 'foo',
        ];
        $marshall = new Marshaller($this->articles);
        $document = new Document([
            'title' => 'Foo',
            'body' => 'My Content',
            'author_id' => 1,
        ]);
        $document->setAccess('*', true);
        $document->setNew(true);
        $document->clean();

        $this->articles->getValidator()
            ->requirePresence('thing', 'update')
            ->add('author_id', 'numeric', ['rule' => 'numeric', 'on' => 'update']);

        $expected = clone $document;
        $result = $marshall->merge($expected, $data, []);

        $this->assertEmpty($result->getError('author_id'));
        $this->assertEmpty($result->getError('thing'));

        $document->clean();
        $document->setNew(false);

        $result = $marshall->merge($document, $data, []);
        $this->assertNotEmpty($result->getError('author_id'));
        $this->assertNotEmpty($result->getError('thing'));
    }

    /**
     * Test merge() with translate behavior integration
     */
    public function testMergeWithTranslations(): void
    {

        $this->articles->addBehavior('Translate', [
            'fields' => ['title', 'body'],
        ]);

        $data = [
            'author_id' => 1,
            '_translations' => [
                'en' => [
                    'title' => 'English Title',
                    'body' => 'English Content',
                ],
                'es' => [
                    'title' => 'Titulo EspaГ±ol',
                    'body' => 'Contenido EspaГ±ol',
                ],
            ],
        ];

        $marshall = new Marshaller($this->articles);
        $document = $this->articles->newEmptyDocument();
        $result = $marshall->merge($document, $data, []);

        $this->assertSame($document, $result);
        $this->assertEmpty($result->getErrors());
        $this->assertTrue($result->isDirty('_translations'));

        $translations = $result->get('_translations');
        $this->assertCount(2, $translations);
        $this->assertInstanceOf(OpenArticleEntity::class, $translations['en']);
        $this->assertInstanceOf(OpenArticleEntity::class, $translations['es']);

        /** @var \Cake\Datasource\EntityInterface $translation */
        $translation = $translations['en'];
        $this->assertEquals($data['_translations']['en'], $translation->toArray());
    }

    /**
     * Test Model.beforeMarshal event.
     */
    public function testBeforeMarshalEvent(): void
    {
        $data = [
            'title' => 'My title',
            'body' => 'My content',
            'user' => [
                'name' => 'Robert',
                'username' => 'rob',
            ],
        ];

        $marshall = new Marshaller($this->articles);

        $this->articles->getEventManager()->on(
            'Collection.beforeMarshal',
            function ($e, $data, $options): void {
                $this->assertArrayHasKey('validate', $options);
                $data['title'] = 'Modified title';
                $data['user']['username'] = 'robert';

                $options['associated'] = ['Users'];
            },
        );

        $document = $marshall->one($data);

        $this->assertSame('Modified title', $document->title);
        $this->assertSame('My content', $document->body);
        $this->assertSame('Robert', $document->user->name);
        $this->assertSame('robert', $document->user->username);
    }

    /**
     * Test Model.beforeMarshal event on associated tables.
     */
    public function testBeforeMarshalEventOnAssociations(): void
    {
        $data = [
            'title' => 'My title',
            'body' => 'My content',
            'author_id' => 1,
            'user' => [
                'username' => 'mark',
                'password' => 'secret',
            ],
            'comments' => [
                ['comment' => 'First post', 'user_id' => 2],
                ['comment' => 'Second post', 'user_id' => 2],
            ],
            'tags' => [
                ['tag' => 'news', '_joinData' => ['active' => 1]],
                ['tag' => 'cakephp', '_joinData' => ['active' => 0]],
            ],
        ];

        $marshall = new Marshaller($this->articles);

        // Assert event options are correct
        $this->articles->Users->getEventManager()->on(
            'Collection.beforeMarshal',
            function ($e, $data, $options): void {
                $this->assertArrayHasKey('validate', $options);
                $this->assertTrue($options['validate']);

                $this->assertArrayHasKey('associated', $options);
                $this->assertSame([], $options['associated']);

                $this->assertArrayHasKey('association', $options);
                $this->assertInstanceOf(Association::class, $options['association']);
            },
        );

        $this->articles->Users->getEventManager()->on(
            'Collection.beforeMarshal',
            function ($e, array $data, $options): void {
                $data['secret'] = 'h45h3d';
            },
        );

        $this->articles->Comments->getEventManager()->on(
            'Collection.beforeMarshal',
            function ($e, array $data): void {
                $data['comment'] .= ' (modified)';
            },
        );

        $this->articles->Tags->getEventManager()->on(
            'Collection.beforeMarshal',
            function ($e, array $data): void {
                $data['tag'] .= ' (modified)';
            },
        );

        $this->articles->Tags->junction()->getEventManager()->on(
            'Collection.beforeMarshal',
            function ($e, array $data): void {
                $data['modified_by'] = 1;
            },
        );

        $document = $marshall->one($data, [
            'associated' => ['Users', 'Comments', 'Tags'],
        ]);

        $this->assertSame('h45h3d', $document->user->secret);
        $this->assertSame('First post (modified)', $document->comments[0]->comment);
        $this->assertSame('Second post (modified)', $document->comments[1]->comment);
        $this->assertSame('news (modified)', $document->tags[0]->tag);
        $this->assertSame('cakephp (modified)', $document->tags[1]->tag);
        $this->assertSame(1, $document->tags[0]->_joinData->modified_by);
        $this->assertSame(1, $document->tags[1]->_joinData->modified_by);
    }

    /**
     * Test Model.afterMarshal event.
     */
    public function testAfterMarshalEvent(): void
    {
        $data = [
            'title' => 'original title',
            'body' => 'original content',
            'user' => [
                'name' => 'Robert',
                'username' => 'rob',
            ],
        ];

        $marshall = new Marshaller($this->articles);

        $this->articles->getEventManager()->on(
            'Collection.afterMarshal',
            function ($e, $document, $data, $options): void {
                $this->assertInstanceOf(Document::class, $document);
                $this->assertArrayHasKey('validate', $options);
                $this->assertFalse($options['isMerge']);

                $data['title'] = 'Modified title';
                $data['user']['username'] = 'robert';
                $options['associated'] = ['Users'];

                $document->body = 'Modified body';
            },
        );

        $document = $marshall->one($data);

        $this->assertSame('original title', $document->title, '$data is immutable');
        $this->assertSame('Modified body', $document->body);
        // both $options and $data are unchangeable
        $this->assertIsArray($document->user, '$options[\'associated\'] is ignored');
        $this->assertSame('Robert', $document->user['name']);
        $this->assertSame('rob', $document->user['username']);
    }

    /**
     * Test Model.afterMarshal event on patchEntity.
     * when $options['fields'] is set and is empty
     */
    public function testAfterMarshalEventOnPatchEntity(): void
    {
        $data = [
            'title' => 'original title',
            'body' => 'original content',
            'user' => [
                'name' => 'Robert',
                'username' => 'rob',
            ],
        ];

        $marshall = new Marshaller($this->articles);

        $this->articles->getEventManager()->on(
            'Collection.afterMarshal',
            function ($e, $document, $data, $options): void {
                $this->assertInstanceOf(Document::class, $document);
                $this->assertArrayHasKey('validate', $options);
                $this->assertTrue($options['isMerge']);

                $data['title'] = 'Modified title';
                $data['user']['username'] = 'robert';
                $options['associated'] = ['Users'];

                $document->body = 'options[fields] is empty';
                if (isset($options['fields'])) {
                    $document->body = 'options[fields] is set';
                }
            },
        );

        //test when $options['fields'] is empty
        $document = $this->articles->newEmptyDocument();
        $marshall->merge($document, $data, []);

        $this->assertSame('original title', $document->title, '$data is immutable');
        $this->assertSame('options[fields] is empty', $document->body);
        // both $options and $data are unchangeable
        $this->assertIsArray($document->user, '$options[\'associated\'] is ignored');
        $this->assertSame('Robert', $document->user['name']);
        $this->assertSame('rob', $document->user['username']);

        //test when $options['fields'] is set
        $document = $this->articles->newEmptyDocument();
        $marshall->merge($document, $data, ['fields' => ['title', 'body']]);

        $this->assertSame('original title', $document->title, '$data is immutable');
        $this->assertSame('options[fields] is set', $document->body);
    }

    /**
     * Tests that patching an association resulting in no changes, will
     * not mark the parent entity as dirty
     */
    public function testAssociationNoChanges(): void
    {

        $options = ['markClean' => true, 'isNew' => false];
        $document = new Document([
            'title' => 'My Title',
            'user' => new Document([
                'username' => 'mark',
                'password' => 'not a secret',
            ], $options),
        ], $options);

        $data = [
            'body' => 'My Content',
            'user' => [
                'username' => 'mark',
                'password' => 'not a secret',
            ],
        ];
        $marshall = new Marshaller($this->articles);
        $marshall->merge($document, $data, ['associated' => ['Users']]);
        $this->assertSame('My Content', $document->body);
        $this->assertInstanceOf(Document::class, $document->user);
        $this->assertSame('mark', $document->user->username);
        $this->assertSame('not a secret', $document->user->password);
        $this->assertFalse($document->isDirty('user'));
        $this->assertTrue($document->user->isNew());
    }

    /**
     * Tests that ID values are being bound with the correct type when loading associated records.
     */
    public function testInvalidTypesWhenLoadingAssociatedByIds(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot convert value `foobar` of type `string` to int');

        $data = [
            'title' => 'article',
            'body' => 'some content',
            'comments' => [
                '_ids' => ['foobar'],
            ],
        ];

        $marshaller = new Marshaller($this->articles);
        $marshaller->one($data, ['associated' => ['Comments']]);
    }

    /**
     * Tests that composite ID values are being bound with the correct type when loading associated records.
     */
    public function testInvalidTypesWhenLoadingAssociatedByCompositeIds(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot convert value `foo` of type `string` to int');

        $data = [
            'title' => 'article',
            'body' => 'some content',
            'comments' => [
                '_ids' => [['foo', 'bar']],
            ],
        ];

        $this->articles->Comments->setPrimaryKey(['_id', 'article_id']);

        $marshaller = new Marshaller($this->articles);
        $marshaller->one($data, ['associated' => ['Comments']]);
    }
}

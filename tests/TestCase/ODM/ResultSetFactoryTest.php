<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM;

use Cake\Database\StatementInterface;
use Cake\Datasource\ResultSetInterface;
use Cake\Log\Log;
use Cake\ORM\DtoMapper;
use Crustum\Mongo\Database\Log\QueryLogger;
use Crustum\Mongo\ODM\ResultSetFactory;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use TestApp\Dto\ArticleArrayDto;
use TestApp\Dto\ArticleDto;
use TestApp\Dto\AuthorArrayDto;
use TestApp\Dto\AuthorDto;
use TestApp\Dto\CommentDto;
use TestApp\Dto\SimpleArticleDto;
use TestApp\Model\Document\ProtectedArticle;

/**
 * ResultSetFactory test case.
 *
 * @ported-from \Cake\Test\TestCase\ORM\ResultSetFactoryTest
 */
#[CoversClass(ResultSetFactory::class)]
class ResultSetFactoryTest extends TestCase
{
    /**
     * @var array<string>
     */
    protected array $fixtures = ['plugin.Crustum/Mongo.Articles', 'plugin.Crustum/Mongo.Authors', 'plugin.Crustum/Mongo.Comments'];

    /**
     * @var \Crustum\Mongo\ODM\BaseCollection
     */
    protected $collection;

    /**
     * @var array
     */
    protected $fixtureData;

    /**
     * @var \Cake\Datasource\ConnectionInterface
     */
    protected $connection;

    /**
     * @var \Cake\ORM\ResultSetFactory
     */
    protected $factory;

    /**
     * setup
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = $this->collection?->getConnection() ?? $this->getCollectionLocator()->get('Articles')->getConnection();
        $this->collection = $this->getCollectionLocator()->get('Articles');
        $this->factory = new ResultSetFactory();

        $this->fixtureData = [
            ['_id' => '000000000000000000000001', 'author_id' => '000000000000000000000001', 'title' => 'First Article', 'body' => 'First Article Body', 'published' => 'Y'],
            ['_id' => '000000000000000000000002', 'author_id' => '000000000000000000000003', 'title' => 'Second Article', 'body' => 'Second Article Body', 'published' => 'Y'],
            ['_id' => '000000000000000000000003', 'author_id' => '000000000000000000000001', 'title' => 'Third Article', 'body' => 'Third Article Body', 'published' => 'Y'],
        ];
    }

    public function testSetResultSetClass(): void
    {
        $mock = Mockery::mock(ResultSetInterface::class);

        $this->factory->setResultSetClass($mock::class);
        $this->assertSame($mock::class, $this->factory->getResultSetClass());
    }

    /**
     * Tests __debugInfo
     */
    public function testDebugInfo(): void
    {
        $query = $this->collection->find('all');
        $results = $query->all();
        $expected = [
            'count' => 3,
            'items' => $results->toArray(),
        ];
        $this->assertSame($expected, $results->__debugInfo());
    }

    /**
     * Test that eagerLoader leaves empty associations unpopulated.
     */
    public function testBelongsToEagerLoaderLeavesEmptyAssociation(): void
    {
        $comments = $this->getCollectionLocator()->get('Comments');
        $comments->belongsTo('Articles');

        // Clear the articles table so we can trigger an empty belongsTo
        $this->collection->deleteAll([]);

        $comment = $comments->find()->where(['Comments._id' => '000000000000000000000001'])
            ->contain(['Articles'])
            ->hydrate(false)
            ->first();
        $this->assertSame('000000000000000000000001', $comment['_id']);
        $this->assertNotEmpty($comment['comment']);
        $this->assertNull($comment['article']);

        $comment = $comments->get('000000000000000000000001', ...['contain' => ['Articles']]);
        $this->assertNull($comment->article);
        $this->assertSame('000000000000000000000001', $comment->getId());
        $this->assertNotEmpty($comment->comment);
    }

    /**
     * Contained belongsto rows keep `_id` even when the document class omits
     * it from `$_accessible` (baked documents do this for the primary key).
     */
    public function testContainedAssociationHydratesInaccessiblePrimaryKey(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $articles->setDocumentClass(ProtectedArticle::class);

        $comments = $this->getCollectionLocator()->get('Comments');
        $comments->belongsTo('Articles');

        $comment = $comments->find()
            ->contain(['Articles'])
            ->where(['Comments._id' => '000000000000000000000001'])
            ->first();

        $this->assertNotNull($comment->article);
        $this->assertInstanceOf(ProtectedArticle::class, $comment->article);
        $this->assertSame('000000000000000000000001', $comment->article->getId());
        $this->assertNotEmpty($comment->article->title);
        $this->assertSame('000000000000000000000001', $comment->article_id);
    }

    /**
     * Test showing associated record is preserved when selecting only field with
     * null value if auto fields is disabled.
     */
    public function testBelongsToEagerLoaderWithAutoFieldsFalse(): void
    {
        $authors = $this->getCollectionLocator()->get('Authors');

        $author = $authors->newDocument(['name' => null]);
        $authors->save($author);

        $articles = $this->getCollectionLocator()->get('Articles');
        $articles->belongsTo('Authors');

        $article = $articles->newDocument([
            'author_id' => $author->getId(),
            'title' => 'article with author with null name',
        ]);
        $articles->save($article);

        $result = $articles->find()
            ->select(['Articles._id', 'Articles.title', 'Authors.name'])
            ->contain(['Authors'])
            ->where(['Articles._id' => $article->getId()])
            ->disableAutoFields()
            ->hydrate(false)
            ->first();

        $this->assertNotNull($result['author']);
    }

    /**
     * Test that eagerLoader leaves empty associations unpopulated.
     */
    public function testHasOneEagerLoaderLeavesEmptyAssociation(): void
    {
        $this->collection->hasOne('Comments');

        // Clear the comments table so we can trigger an empty hasOne.
        $comments = $this->getCollectionLocator()->get('Comments');
        $comments->deleteAll([]);

        $article = $this->collection->get('000000000000000000000001', ...['contain' => ['Comments']]);
        $this->assertNull($article->comment);
        $this->assertSame('000000000000000000000001', $article->getId());
        $this->assertNotEmpty($article->title);

        $article = $this->collection->find()->where(['Articles._id' => '000000000000000000000001'])
            ->contain(['Comments'])
            ->hydrate(false)
            ->first();
        $this->assertNull($article['comment']);
        $this->assertSame('000000000000000000000001', $article['_id']);
        $this->assertNotEmpty($article['title']);
    }

    /**
     * Test that fetching rows does not fail when no fields were selected
     * on the default alias.
     */
    public function testFetchMissingDefaultAlias(): void
    {
        $this->markTestSkipped('SQL select-clause aliasing - not applicable to Mongo (F19).');
        $comments = $this->getCollectionLocator()->get('Comments');
        $query = $comments->find()->select(['Other__field' => 'test']);
        $query->disableAutoFields();

        $row = ['Other__field' => 'test'];
        $statement = Mockery::mock(StatementInterface::class);
        $statement->shouldReceive('fetchAll')
            ->andReturn([$row]);

        $results = $this->factory->createResultSet($statement->fetchAll(), $query);
        $this->assertNotEmpty($results);
    }

    /**
     * Test that associations have source() correctly set.
     */
    public function testSourceOnContainAssociations(): void
    {
        $this->loadPlugins(['TestPlugin']);
        $comments = $this->getCollectionLocator()->get('TestPlugin.Comments');
        $comments->belongsTo('Authors', [
            'className' => 'TestPlugin.Authors',
            'foreignKey' => 'user_id',
        ]);
        $result = $comments->find()->contain(['Authors'])->first();
        $this->assertSame('TestPlugin.Comments', $result->getSource());
        $this->assertSame('TestPlugin.Authors', $result->author->getSource());

        $result = $comments->find()->matching('Authors', fn($q) => $q->where(['Authors._id' => '000000000000000000000001']))->first();
        $this->assertSame('TestPlugin.Comments', $result->getSource());
        $this->assertSame('TestPlugin.Authors', $result->_matchingData['Authors']->getSource());
        $this->clearPlugins();
    }

    /**
     * @see https://github.com/cakephp/cakephp/issues/14676
     */
    public function testQueryLoggingForSelectsWithZeroRows(): void
    {
        Log::setConfig('queries', ['className' => 'Array']);

        $logger = new QueryLogger();
        $this->connection->getDriver()->setLogger($logger);

        $messages = Log::engine('queries')->read();
        $this->assertCount(0, $messages);

        $results = $this->collection->find('all')
            ->where(['_id' => '000000000000000000000000'])
            ->all();

        $this->assertCount(0, $results);

        $messages = Log::engine('queries')->read();
        $this->assertNotEmpty($messages, 'The query should have been logged.');
        $message = (string)array_pop($messages);
        $this->assertMatchesRegularExpression('/"operation":\s*"find"/', $message);
        $this->assertMatchesRegularExpression('/"collection":\s*"articles"/', $message);

        Log::reset();
    }

    /**
     * Test projectAs() returns DTOs instead of entities.
     */
    public function testProjectAsSimpleDto(): void
    {
        DtoMapper::clearCache();

        $result = $this->collection->find()
            ->where(['_id' => '000000000000000000000001'])
            ->projectAs(SimpleArticleDto::class)
            ->first();

        $this->assertInstanceOf(SimpleArticleDto::class, $result);
        $this->assertSame('000000000000000000000001', $result->_id);
        $this->assertSame('First Article', $result->title);
        $this->assertSame('First Article Body', $result->body);
    }

    /**
     * Test projectAs() with multiple results.
     */
    public function testProjectAsMultipleResults(): void
    {
        DtoMapper::clearCache();

        $results = $this->collection->find()
            ->projectAs(SimpleArticleDto::class)
            ->toArray();

        $this->assertCount(3, $results);
        foreach ($results as $result) {
            $this->assertInstanceOf(SimpleArticleDto::class, $result);
        }
    }

    /**
     * Test projectAs() with BelongsTo association.
     */
    public function testProjectAsWithBelongsTo(): void
    {
        DtoMapper::clearCache();

        $articles = $this->getCollectionLocator()->get('Articles');
        $articles->belongsTo('Authors');

        $result = $articles->find()
            ->contain(['Authors'])
            ->where(['Articles._id' => '000000000000000000000001'])
            ->projectAs(ArticleDto::class)
            ->first();

        $this->assertInstanceOf(ArticleDto::class, $result);
        $this->assertSame('000000000000000000000001', $result->_id);
        $this->assertSame('First Article', $result->title);
        $this->assertInstanceOf(AuthorDto::class, $result->author);
        $this->assertSame('mariano', $result->author->name);
    }

    /**
     * Test projectAs() with HasMany association.
     */
    public function testProjectAsWithHasMany(): void
    {
        DtoMapper::clearCache();

        $articles = $this->getCollectionLocator()->get('Articles');
        $articles->hasMany('Comments');

        $result = $articles->find()
            ->contain(['Comments'])
            ->where(['Articles._id' => '000000000000000000000001'])
            ->projectAs(ArticleDto::class)
            ->first();

        $this->assertInstanceOf(ArticleDto::class, $result);
        $this->assertSame('000000000000000000000001', $result->_id);
        $this->assertIsArray($result->comments);
        $this->assertGreaterThan(0, count($result->comments));
        foreach ($result->comments as $comment) {
            $this->assertInstanceOf(CommentDto::class, $comment);
        }
    }

    /**
     * Test projectAs() with null BelongsTo association.
     */
    public function testProjectAsWithNullBelongsTo(): void
    {
        DtoMapper::clearCache();

        $articles = $this->getCollectionLocator()->get('Articles');
        $articles->belongsTo('Authors');

        // Clear authors to trigger null association
        $authors = $this->getCollectionLocator()->get('Authors');
        $authors->deleteAll([]);

        $result = $articles->find()
            ->contain(['Authors'])
            ->where(['Articles._id' => '000000000000000000000001'])
            ->projectAs(ArticleDto::class)
            ->first();

        $this->assertInstanceOf(ArticleDto::class, $result);
        $this->assertNull($result->author);
    }

    /**
     * Test projectAs() with empty HasMany association.
     */
    public function testProjectAsWithEmptyHasMany(): void
    {
        DtoMapper::clearCache();

        $articles = $this->getCollectionLocator()->get('Articles');
        $articles->hasMany('Comments');

        // Clear comments to trigger empty collection
        $comments = $this->getCollectionLocator()->get('Comments');
        $comments->deleteAll([]);

        $result = $articles->find()
            ->contain(['Comments'])
            ->where(['Articles._id' => '000000000000000000000001'])
            ->projectAs(ArticleDto::class)
            ->first();

        $this->assertInstanceOf(ArticleDto::class, $result);
        $this->assertSame([], $result->comments);
    }

    /**
     * Test getDtoClass() returns the DTO class.
     */
    public function testGetDtoClass(): void
    {
        $query = $this->collection->find();
        $this->assertNull($query->getDtoClass());

        $query->projectAs(SimpleArticleDto::class);
        $this->assertSame(SimpleArticleDto::class, $query->getDtoClass());
    }

    /**
     * Test isDtoProjectionEnabled().
     */
    public function testIsDtoProjectionEnabled(): void
    {
        $query = $this->collection->find();
        $this->assertFalse($query->isDtoProjectionEnabled());

        $query->projectAs(SimpleArticleDto::class);
        $this->assertTrue($query->isDtoProjectionEnabled());
    }

    /**
     * Test projectAs() with createFromArray factory method.
     */
    public function testProjectAsWithCreateFromArray(): void
    {
        DtoMapper::clearCache();

        $result = $this->collection->find()
            ->where(['_id' => '000000000000000000000001'])
            ->projectAs(ArticleArrayDto::class)
            ->first();

        $this->assertInstanceOf(ArticleArrayDto::class, $result);
        $this->assertSame('000000000000000000000001', $result->_id);
        $this->assertSame('First Article', $result->title);
        $this->assertSame('First Article Body', $result->body);
    }

    /**
     * Test projectAs() with createFromArray and BelongsTo association.
     */
    public function testProjectAsCreateFromArrayWithBelongsTo(): void
    {
        DtoMapper::clearCache();

        $articles = $this->getCollectionLocator()->get('Articles');
        $articles->belongsTo('Authors');

        $result = $articles->find()
            ->contain(['Authors'])
            ->where(['Articles._id' => '000000000000000000000001'])
            ->projectAs(ArticleArrayDto::class)
            ->first();

        $this->assertInstanceOf(ArticleArrayDto::class, $result);
        $this->assertSame('000000000000000000000001', $result->_id);
        $this->assertSame('First Article', $result->title);
        $this->assertInstanceOf(AuthorArrayDto::class, $result->author);
        $this->assertSame('mariano', $result->author->name);
    }

    /**
     * Test projectAs() with createFromArray and null association.
     */
    public function testProjectAsCreateFromArrayWithNullBelongsTo(): void
    {
        DtoMapper::clearCache();

        $articles = $this->getCollectionLocator()->get('Articles');
        $articles->belongsTo('Authors');

        // Clear authors to trigger null association
        $authors = $this->getCollectionLocator()->get('Authors');
        $authors->deleteAll([]);

        $result = $articles->find()
            ->contain(['Authors'])
            ->where(['Articles._id' => '000000000000000000000001'])
            ->projectAs(ArticleArrayDto::class)
            ->first();

        $this->assertInstanceOf(ArticleArrayDto::class, $result);
        $this->assertNull($result->author);
    }

    /**
     * Test getDtoHydrator() returns cached callable for plain DTOs.
     */
    public function testGetDtoHydratorPlainDto(): void
    {
        DtoMapper::clearCache();
        ResultSetFactory::clearDtoHydratorCache();

        $hydrator = $this->factory->getDtoHydrator(SimpleArticleDto::class);
        $this->assertIsCallable($hydrator);

        // Calling again should return the same cached callable
        $hydrator2 = $this->factory->getDtoHydrator(SimpleArticleDto::class);
        $this->assertSame($hydrator, $hydrator2);

        // Test the hydrator works
        $result = $hydrator(['_id' => '000000000000000000000001', 'title' => 'Test', 'body' => 'Body']);
        $this->assertInstanceOf(SimpleArticleDto::class, $result);
        $this->assertSame('000000000000000000000001', $result->_id);
        $this->assertSame('Test', $result->title);
    }

    /**
     * Test getDtoHydrator() returns cached callable for DTOs with createFromArray.
     */
    public function testGetDtoHydratorCreateFromArray(): void
    {
        DtoMapper::clearCache();
        ResultSetFactory::clearDtoHydratorCache();

        $hydrator = $this->factory->getDtoHydrator(ArticleArrayDto::class);
        $this->assertIsCallable($hydrator);

        // Calling again should return the same cached callable
        $hydrator2 = $this->factory->getDtoHydrator(ArticleArrayDto::class);
        $this->assertSame($hydrator, $hydrator2);

        // Test the hydrator works
        $result = $hydrator(['_id' => '000000000000000000000002', 'title' => 'Test 2', 'body' => 'Body 2']);
        $this->assertInstanceOf(ArticleArrayDto::class, $result);
        $this->assertSame('000000000000000000000002', $result->_id);
        $this->assertSame('Test 2', $result->title);
    }

    /**
     * Test clearDtoHydratorCache() clears the cache.
     */
    public function testClearDtoHydratorCache(): void
    {
        DtoMapper::clearCache();
        ResultSetFactory::clearDtoHydratorCache();

        // Get a hydrator to populate the cache
        $this->factory->getDtoHydrator(SimpleArticleDto::class);

        // Clear the cache
        ResultSetFactory::clearDtoHydratorCache();

        // Get the hydrator again - should be a new callable
        $hydrator2 = $this->factory->getDtoHydrator(SimpleArticleDto::class);

        // The callables should be equivalent but not the same instance
        // since the cache was cleared
        $this->assertIsCallable($hydrator2);
    }

    /**
     * Test hydrateDto() method.
     */
    public function testHydrateDto(): void
    {
        DtoMapper::clearCache();
        ResultSetFactory::clearDtoHydratorCache();

        $row = ['_id' => '000000000000000000000003', 'title' => 'Hydrate Test', 'body' => 'Body'];
        $result = $this->factory->hydrateDto($row, SimpleArticleDto::class);

        $this->assertInstanceOf(SimpleArticleDto::class, $result);
        $this->assertSame('000000000000000000000003', $result->_id);
        $this->assertSame('Hydrate Test', $result->title);
    }
}

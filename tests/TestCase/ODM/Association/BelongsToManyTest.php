<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM\Association;

use Cake\Database\Exception\DatabaseException;
use Cake\Database\Expression\OrderClauseExpression;
use Cake\Database\Expression\QueryExpression;
use Cake\Datasource\ConnectionManager;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\I18n\DateTime;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\ODM\Association\BelongsTo;
use Crustum\Mongo\ODM\Association\BelongsToMany;
use Crustum\Mongo\ODM\Association\HasMany;
use Crustum\Mongo\ODM\Association\Loader\SelectLoader;
use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\ODM\Document;
use Crustum\Mongo\ODM\Locator\CollectionLocator;
use Crustum\Mongo\ODM\Query\SelectQuery;
use Crustum\Mongo\ODM\RulesChecker;
use Crustum\Mongo\Test\TestCase\ODM\TestCase;
use InvalidArgumentException;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use TestApp\Model\Document\ArticlesTag;
use function Cake\Collection\collection;

/**
 * Tests BelongsToMany class
 */
#[CoversClass(BelongsToMany::class)]
class BelongsToManyTest extends TestCase
{
    /**
     * Fixtures
     *
     * @var array<string>
     */
    protected array $fixtures = [
        'plugin.Crustum/Mongo.Articles',
        'plugin.Crustum/Mongo.Tags',
        'plugin.Crustum/Mongo.SpecialTags',
        'plugin.Crustum/Mongo.ArticlesTags',
        'plugin.Crustum/Mongo.ArticlesTagsBindingKeys',
        'plugin.Crustum/Mongo.BinaryUuidItems',
        'plugin.Crustum/Mongo.BinaryUuidTags',
        'plugin.Crustum/Mongo.BinaryUuidItemsBinaryUuidTags',
        'plugin.Crustum/Mongo.CompositeKeyArticles',
        'plugin.Crustum/Mongo.CompositeKeyArticlesTags',
    ];

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

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    /**
     * Tests setForeignKey()
     */
    public function testSetForeignKey(): void
    {
        $assoc = new BelongsToMany('Test', $this->article, [
            'target' => $this->tag,
        ]);
        $this->assertSame('article_id', $assoc->getForeignKey());
        $this->assertSame($assoc, $assoc->setForeignKey('another_key'));
        $this->assertSame('another_key', $assoc->getForeignKey());
    }

    /**
     * Tests that the association reports it can be joined
     */
    public function testCanBeJoined(): void
    {
        $assoc = new BelongsToMany('Test', $this->article);
        $this->assertFalse($assoc->canBeJoined());
    }

    /**
     * Tests setSort() method
     */
    public function testSetSort(): void
    {
        $assoc = new BelongsToMany('Test', $this->article);
        $this->assertNull($assoc->getSort());

        $assoc->setSort('id ASC');
        $this->assertSame('id ASC', $assoc->getSort());

        $assoc->setSort(['_id' => 'ASC']);
        $this->assertSame(['_id' => 'ASC'], $assoc->getSort());

        $closure = (fn(): array => ['_id' => 'ASC']);
        $assoc->setSort($closure);
        $this->assertSame($closure, $assoc->getSort());

        $expression = new OrderClauseExpression('id', 'ASC');
        $assoc->setSort($expression);
        $this->assertSame($expression, $assoc->getSort());
    }

    /**
     * Tests that sorting works using the accepted types for `setSort()`.
     */
    public function testSorting(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $assoc = $articles->belongsToMany('Tags');

        $field = 'Tags._id';

        $assoc->setSort("{$field} DESC");
        $result = $articles->get('000000000000000000000001', ...['contain' => 'Tags']);
        $this->assertSame(['000000000000000000000002', '000000000000000000000001'], array_column($result['tags'], '_id'));

        $assoc->setSort(['Tags._id' => 'DESC']);
        $result = $articles->get('000000000000000000000001', ...['contain' => 'Tags']);
        $this->assertSame(['000000000000000000000002', '000000000000000000000001'], array_column($result['tags'], '_id'));

        $assoc->setSort(fn(): array => ['Tags._id' => 'DESC']);
        $result = $articles->get('000000000000000000000001', ...['contain' => 'Tags']);
        $this->assertSame(['000000000000000000000002', '000000000000000000000001'], array_column($result['tags'], '_id'));

        $assoc->setSort(new OrderClauseExpression('Tags._id', 'DESC'));
        $result = $articles->get('000000000000000000000001', ...['contain' => 'Tags']);
        $this->assertSame(['000000000000000000000002', '000000000000000000000001'], array_column($result['tags'], '_id'));
    }

    /**
     * Tests requiresKeys() method
     */
    public function testRequiresKeys(): void
    {
        $assoc = new BelongsToMany('Test', $this->article);
        // Default strategy is now subquery, which doesn't require keys
        $this->assertFalse($assoc->requiresKeys());

        $assoc->setStrategy(BelongsToMany::STRATEGY_SELECT);
        $this->assertTrue($assoc->requiresKeys());

        $assoc->setStrategy(BelongsToMany::STRATEGY_SUBQUERY);
        $this->assertFalse($assoc->requiresKeys());
    }

    /**
     * Tests that BelongsToMany can't use the join strategy
     */
    public function testStrategyFailure(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid strategy `join` was provided');
        $assoc = new BelongsToMany('Test', $this->article);
        $assoc->setStrategy(BelongsToMany::STRATEGY_JOIN);
    }

    public function testJunctionProperty(): void
    {
        $assoc = new BelongsToMany('Test', $this->article);
        $this->assertSame('_joinData', $assoc->getJunctionProperty());

        $assoc = new BelongsToMany('Test', $this->article, ['junctionProperty' => 'junction']);
        $this->assertSame('junction', $assoc->getJunctionProperty());

        $assoc->setJunctionProperty('_pivot');
        $this->assertSame('_pivot', $assoc->getJunctionProperty());
    }

    /**
     * Tests the junction method
     */
    public function testJunction(): void
    {
        $assoc = new BelongsToMany('Test', $this->article, [
            'target' => $this->tag,
            'strategy' => 'subquery',
        ]);
        $junction = $assoc->junction();
        $this->assertInstanceOf(BaseCollection::class, $junction);
        $this->assertSame('ArticlesTags', $junction->getAlias());
        $this->assertSame('articles_tags', $junction->getCollection());
        $this->assertSame($this->article, $junction->getAssociation('Articles')->getTarget());
        $this->assertSame($this->tag, $junction->getAssociation('Tags')->getTarget());

        $this->assertInstanceOf(BelongsTo::class, $junction->getAssociation('Articles'));
        $this->assertInstanceOf(BelongsTo::class, $junction->getAssociation('Tags'));

        $this->assertSame($junction, $this->tag->getAssociation('ArticlesTags')->getTarget());
        $this->assertSame($this->article, $this->tag->getAssociation('Articles')->getTarget());

        $this->assertInstanceOf(BelongsToMany::class, $this->tag->getAssociation('Articles'));
        $this->assertInstanceOf(HasMany::class, $this->tag->getAssociation('ArticlesTags'));

        $this->assertSame($junction, $assoc->junction());
        $junction2 = $this->getCollectionLocator()->get('Foos');
        $assoc->junction($junction2);
        $this->assertSame($junction2, $assoc->junction());

        $assoc->junction('ArticlesTags');
        $this->assertSame($junction, $assoc->junction());

        $this->assertSame($assoc->getStrategy(), $this->tag->getAssociation('Articles')->getStrategy());
        $this->assertSame($assoc->getStrategy(), $this->tag->getAssociation('ArticlesTags')->getStrategy());
        $this->assertSame($assoc->getStrategy(), $this->article->getAssociation('ArticlesTags')->getStrategy());

        $this->assertSame($this->article->getPrimaryKey(), $junction->getAssociation('Articles')->getBindingKey());
        $this->assertSame($this->tag->getPrimaryKey(), $junction->getAssociation('Tags')->getBindingKey());
    }

    /**
     * Tests the junction passes the source connection name on.
     */
    public function testJunctionConnection(): void
    {
        $config = ConnectionManager::getConfig('test_mongo');
        ConnectionManager::setConfig('other_source', $config);
        $otherConnection = ConnectionManager::get('other_source');
        $this->article->setConnection($otherConnection);

        $assoc = new BelongsToMany('Test', $this->article, [
            'target' => $this->tag,
        ]);
        $junction = $assoc->junction();
        $this->assertSame($otherConnection, $junction->getConnection());
        ConnectionManager::drop('other_source');
    }

    /**
     * Tests the junction method custom keys
     */
    public function testJunctionCustomKeys(): void
    {
        $this->article->belongsToMany('Tags', [
            'target' => $this->tag,
            'joinCollection' => 'articles_tags',
            'foreignKey' => 'article',
            'targetForeignKey' => 'tag',
        ]);
        $this->tag->belongsToMany('Articles', [
            'target' => $this->article,
            'joinCollection' => 'articles_tags',
            'foreignKey' => 'tag',
            'targetForeignKey' => 'article',
        ]);
        $junction = $this->article->getAssociation('Tags')->junction();
        $this->assertSame('article', $junction->getAssociation('Articles')->getForeignKey());
        $this->assertSame('article', $this->article->getAssociation('ArticlesTags')->getForeignKey());

        $junction = $this->tag->getAssociation('Articles')->junction();
        $this->assertSame('tag', $junction->getAssociation('Tags')->getForeignKey());
        $this->assertSame('tag', $this->tag->getAssociation('ArticlesTags')->getForeignKey());
    }

    /**
     * Tests it is possible to set the table name for the join table
     */
    public function testJunctionWithDefaultTableName(): void
    {
        $assoc = new BelongsToMany('Test', $this->article, [
            'target' => $this->tag,
            'joinCollection' => 'tags_articles',
        ]);
        $junction = $assoc->junction();
        $this->assertSame('TagsArticles', $junction->getAlias());
        $this->assertSame('tags_articles', $junction->getCollection());
    }

    /**
     * Test multiple associations with differerent keys fails
     */
    public function testMultipleAssociationsSameJunction(): void
    {
        $assoc = new BelongsToMany('This', $this->article, [
            'target' => $this->tag,
            'targetForeignKey' => 'this_id',
        ]);
        $assoc->junction();

        $assoc = new BelongsToMany('That', $this->article, [
            'target' => $this->tag,
            'targetForeignKey' => 'that_id',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $assoc->junction();
    }

    /**
     * Tests same source and target table failure.
     */
    public function testSameSourceTargetJunction(): void
    {
        $assoc = new BelongsToMany('This', $this->article, [
            'target' => $this->article,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The `This` association on `Articles` cannot target the same collection.');
        $assoc->junction();
    }

    /**
     * Tests saveStrategy
     */
    public function testSetSaveStrategy(): void
    {
        $assoc = new BelongsToMany('Test', $this->article);
        $this->assertSame(BelongsToMany::SAVE_REPLACE, $assoc->getSaveStrategy());

        $assoc->setSaveStrategy(BelongsToMany::SAVE_APPEND);
        $this->assertSame(BelongsToMany::SAVE_APPEND, $assoc->getSaveStrategy());

        $assoc->setSaveStrategy(BelongsToMany::SAVE_REPLACE);
        $this->assertSame(BelongsToMany::SAVE_REPLACE, $assoc->getSaveStrategy());
    }

    /**
     * Tests that it is possible to pass the saveAssociated strategy in the constructor
     */
    public function testSaveStrategyInOptions(): void
    {
        $assoc = new BelongsToMany('Test', $this->article, ['saveStrategy' => BelongsToMany::SAVE_APPEND]);
        $this->assertSame(BelongsToMany::SAVE_APPEND, $assoc->getSaveStrategy());
    }

    /**
     * Tests that passing an invalid strategy will throw an exception
     */
    public function testSaveStrategyInvalid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid save strategy `depsert`');
        new BelongsToMany('Test', $this->article, ['saveStrategy' => 'depsert']);
    }

    /**
     * Ensure that the `finder` option is applied to the target
     * table.
     */
    public function testFinderOption(): void
    {
        $this->setAppNamespace('TestApp');

        $articles = $this->getCollectionLocator()->get('Articles');
        $tags = $this->getCollectionLocator()->get('Tags');

        $tags->associations()->get('Articles')->setFinder('published');
        $articles->updateAll(['published' => 'N'], ['_id' => '000000000000000000000001']);
        $document = $tags->get('000000000000000000000001', ...['contain' => 'Articles']);
        $this->assertCount(1, $document->articles, 'only one article should load');
        $this->assertSame('Y', $document->articles[0]->published);
    }

    /**
     * Test cascading deletes.
     */
    public function testCascadeDelete(): void
    {
        /** @var \Crustum\Mongo\ODM\BaseCollection&\Mockery\MockInterface $articleTag */
        $articleTag = Mockery::mock(new BaseCollection(['alias' => 'ArticlesTags', 'collection' => 'articles_tags']))
            ->makePartial();
        $config = [
            'sort' => ['_id' => 'ASC'],
        ];
        $association = new BelongsToMany('Tags', $this->article, $config);
        $association->junction($articleTag);
        $this->article
            ->getAssociation($articleTag->getAlias())
            ->setConditions(['click_count' => 3]);

        $articleTag->shouldReceive('deleteAll')
            ->once()
            ->with([
                'click_count' => 3,
                'article_id' => '000000000000000000000001',
            ]);

        $document = new Document(['_id' => '000000000000000000000001', 'name' => 'PHP']);
        $association->cascadeDelete($document);
    }

    /**
     * Test cascading deletes with dependent=false
     */
    public function testCascadeDeleteDependent(): void
    {
        /** @var \Crustum\Mongo\ODM\BaseCollection&\Mockery\MockInterface $articleTag */
        $articleTag = Mockery::mock(new BaseCollection(['alias' => 'ArticlesTags', 'collection' => 'articles_tags']))
            ->makePartial();
        $config = [
            'target' => $this->tag,
            'dependent' => false,
            'sort' => ['_id' => 'ASC'],
        ];
        $association = new BelongsToMany('Tags', $this->article, $config);
        $association->junction($articleTag);
        $this->article
            ->getAssociation($articleTag->getAlias())
            ->setConditions(['click_count' => 3]);

        $articleTag->shouldReceive('deleteAll')->never();
        $articleTag->shouldReceive('delete')->never();

        $document = new Document(['_id' => '000000000000000000000001', 'name' => 'PHP']);
        $association->cascadeDelete($document);
    }

    /**
     * Test cascading deletes with callbacks.
     */
    public function testCascadeDeleteWithCallbacks(): void
    {
        $articleTag = $this->getCollectionLocator()->get('ArticlesTags');
        $config = [
            'target' => $this->tag,
            'cascadeCallbacks' => true,
        ];
        $association = new BelongsToMany('Tag', $this->article, $config);
        $association->junction($articleTag);

        $this->article->getAssociation($articleTag->getAlias());

        $counter = 0;
        $articleTag->getEventManager()->on('Collection.beforeDelete', function () use (&$counter): void {
            $counter++;
        });

        $this->assertSame(2, $articleTag->find()->where(['article_id' => '000000000000000000000001'])->count());
        $document = new Document(['_id' => '000000000000000000000001', 'name' => 'PHP']);
        $association->cascadeDelete($document);

        $this->assertSame(0, $articleTag->find()->where(['article_id' => '000000000000000000000001'])->count());
        $this->assertSame(2, $counter);
    }

    /**
     * Test cascading delete with a rule preventing deletion
     */
    public function testCascadeDeleteCallbacksRuleFailure(): void
    {
        $articleTag = $this->getCollectionLocator()->get('ArticlesTags');
        $config = [
            'target' => $this->tag,
            'cascadeCallbacks' => true,
        ];
        $association = new BelongsToMany('Tag', $this->article, $config);
        $association->junction($articleTag);

        $this->article->getAssociation($articleTag->getAlias());

        $articleTag->getEventManager()->on('Collection.buildRules', function ($event, $rules): void {
            $rules->addDelete(fn(): false => false);
        });
        $document = new Document(['_id' => '000000000000000000000001', 'name' => 'PHP']);
        $this->assertFalse($association->cascadeDelete($document));

        $matching = $articleTag->find()
            ->where(['ArticlesTags.tag_id' => $document->getId()])
            ->all();
        $this->assertGreaterThan(0, count($matching));
    }

    /**
     * Test linking entities having a non persisted source entity
     */
    public function testLinkWithNotPersistedSource(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Source entity needs to be persisted before links can be created or removed');
        $config = [
            'target' => $this->tag,
            'joinCollection' => 'tags_articles',
        ];
        $assoc = new BelongsToMany('Test', $this->article, $config);
        $document = new Document(['_id' => '000000000000000000000001']);
        $tags = [new Document(['_id' => '000000000000000000000002']), new Document(['_id' => '000000000000000000000003'])];
        $assoc->link($document, $tags);
    }

    /**
     * Test liking entities having a non persisted target entity
     */
    public function testLinkWithNotPersistedTarget(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot link entities that have not been persisted yet');
        $config = [
            'target' => $this->tag,
            'joinCollection' => 'tags_articles',
        ];
        $assoc = new BelongsToMany('Test', $this->article, $config);
        $document = new Document(['_id' => '000000000000000000000001'], ['markNew' => false]);
        $tags = [new Document(['_id' => '000000000000000000000002']), new Document(['_id' => '000000000000000000000003'])];
        $assoc->link($document, $tags);
    }

    /**
     * Tests that linking entities will persist correctly with append strategy
     */
    public function testLinkSuccessSaveAppend(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $tags = $this->getCollectionLocator()->get('Tags');

        $config = [
            'target' => $tags,
            'saveStrategy' => BelongsToMany::SAVE_APPEND,
        ];
        $assoc = $articles->belongsToMany('Tags', $config);

        // Load without tags as that is a main use case for append strategies
        $article = $articles->get('000000000000000000000001');
        $opts = ['markNew' => false];
        $tags = [
            new Document(['_id' => '000000000000000000000002', 'name' => 'add'], $opts),
            new Document(['_id' => '000000000000000000000003', 'name' => 'adder'], $opts),
        ];

        $this->assertTrue($assoc->link($article, $tags));
        $this->assertCount(2, $article->tags, 'In-memory tags are incorrect');
        $this->assertSame(['000000000000000000000002', '000000000000000000000003'], collection($article->tags)->extract('_id')->toList());

        $article = $articles->get('000000000000000000000001', ...['contain' => ['Tags']]);
        $this->assertCount(3, $article->tags, 'Persisted tags are wrong');
        $this->assertSame(['000000000000000000000001', '000000000000000000000002', '000000000000000000000003'], collection($article->tags)->extract('_id')->toList());
    }

    /**
     * Tests that linking the same tag to multiple articles works
     */
    public function testLinkSaveAppendSharedTarget(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $tags = $this->getCollectionLocator()->get('Tags');
        $articlesTags = $this->getCollectionLocator()->get('ArticlesTags');
        $articlesTags->deleteAll([]);

        $config = [
            'target' => $tags,
            'saveStrategy' => BelongsToMany::SAVE_APPEND,
        ];
        $assoc = $articles->belongsToMany('Tags', $config);

        $articleOne = $articles->get('000000000000000000000001');
        $articleTwo = $articles->get('000000000000000000000002');

        $tagTwo = $tags->get('000000000000000000000002');
        $tagThree = $tags->get('000000000000000000000003');

        $this->assertTrue($assoc->link($articleOne, [$tagThree, $tagTwo]));
        $this->assertTrue($assoc->link($articleTwo, [$tagThree]));

        $this->assertCount(2, $articleOne->tags, 'In-memory tags are incorrect');
        $this->assertSame(['000000000000000000000003', '000000000000000000000002'], collection($articleOne->tags)->extract('_id')->toList());

        $this->assertCount(1, $articleTwo->tags, 'In-memory tags are incorrect');
        $this->assertSame(['000000000000000000000003'], collection($articleTwo->tags)->extract('_id')->toList());
        $rows = $articlesTags->find()->all();
        $this->assertCount(3, $rows, '3 link rows should be created.');
    }

    /**
     * Tests that liking entities will validate data and pass on to _saveLinks
     */
    public function testLinkSuccessWithMocks(): void
    {
        $connection = ConnectionManager::get('test_mongo');
        /** @var \Crustum\Mongo\ODM\BaseCollection&\Mockery\MockInterface $joint */
        $joint = Mockery::mock(new BaseCollection(['alias' => 'ArticlesTags', 'connection' => $connection]))
            ->makePartial();

        $config = [
            'target' => $this->tag,
            'through' => $joint,
            'joinCollection' => 'tags_articles',
        ];

        $assoc = new BelongsToMany('Test', $this->article, $config);
        $opts = ['markNew' => false];
        $document = new Document(['_id' => '000000000000000000000001'], $opts);
        $tags = [new Document(['_id' => '000000000000000000000002'], $opts), new Document(['_id' => '000000000000000000000003'], $opts)];
        $saveOptions = ['foo' => 'bar'];

        $joint->shouldReceive('getPrimaryKey')
            ->andReturn(['article_id', 'tag_id']);

        $joint->shouldReceive('save')
            ->twice()
            ->andReturn($document, $document);

        $this->assertTrue($assoc->link($document, $tags, $saveOptions));
        $this->assertSame($document->test, $tags);
    }

    /**
     * Tests that linking entities will set the junction table registry alias
     */
    public function testLinkSetSourceToJunctionEntities(): void
    {
        $connection = ConnectionManager::get('test_mongo');
        /** @var \Crustum\Mongo\ODM\BaseCollection&\Mockery\MockInterface $joint */
        $junctionCollection = new BaseCollection(['alias' => 'ArticlesTags', 'connection' => $connection]);
        $junctionCollection->setRegistryAlias('Plugin.ArticlesTags');

        $joint = Mockery::mock($junctionCollection)->makePartial();

        $config = [
            'target' => $this->tag,
            'through' => $joint,
        ];

        $assoc = new BelongsToMany('Tags', $this->article, $config);
        $opts = ['markNew' => false];
        $document = new Document(['_id' => '000000000000000000000001'], $opts);
        $tags = [new Document(['_id' => '000000000000000000000002'], $opts)];

        $joint->shouldReceive('getPrimaryKey')
            ->andReturn(['article_id', 'tag_id']);

        $joint->shouldReceive('save')
            ->once()
            ->andReturnUsing(function (EntityInterface $e): EntityInterface {
                $this->assertSame('Plugin.ArticlesTags', $e->getSource());

                return $e;
            });

        $this->assertTrue($assoc->link($document, $tags));
        $this->assertSame($document->tags, $tags);
        $this->assertSame('Plugin.ArticlesTags', $document->tags[0]->get('_joinData')->getSource());
    }

    /**
     * Test liking entities having a non persisted source entity
     */
    public function testUnlinkWithNotPersistedSource(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Source entity needs to be persisted before links can be created or removed');
        $config = [
            'target' => $this->tag,
            'joinCollection' => 'tags_articles',
        ];
        $assoc = new BelongsToMany('Test', $this->article, $config);
        $document = new Document(['_id' => '000000000000000000000001']);
        $tags = [new Document(['_id' => '000000000000000000000002']), new Document(['_id' => '000000000000000000000003'])];
        $assoc->unlink($document, $tags);
    }

    /**
     * Test liking entities having a non persisted target entity
     */
    public function testUnlinkWithNotPersistedTarget(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot link entities that have not been persisted');
        $config = [
            'target' => $this->tag,
            'joinCollection' => 'tags_articles',
        ];
        $assoc = new BelongsToMany('Test', $this->article, $config);
        $document = new Document(['_id' => '000000000000000000000001'], ['markNew' => false]);
        $tags = [new Document(['_id' => '000000000000000000000002']), new Document(['_id' => '000000000000000000000003'])];
        $assoc->unlink($document, $tags);
    }

    /**
     * Tests that unlinking calls the right methods
     */
    public function testUnlinkSuccess(): void
    {
        $joint = $this->getCollectionLocator()->get('SpecialTags');
        $articles = $this->getCollectionLocator()->get('Articles');
        $tags = $this->getCollectionLocator()->get('Tags');

        $assoc = $articles->belongsToMany('Tags', [
            'target' => $tags,
            'through' => $joint,
            'joinCollection' => 'special_tags',
        ]);
        $document = $articles->get('000000000000000000000002', ...['contain' => 'Tags']);
        $initial = $document->tags;
        $this->assertCount(1, $initial);

        $this->assertTrue($assoc->unlink($document, $document->tags));
        $this->assertEmpty($document->get('tags'), 'Property should be empty');

        $new = $articles->get('000000000000000000000002', ...['contain' => 'Tags']);
        $this->assertCount(0, $new->tags, 'DB should be clean');
        $this->assertSame(3, $tags->find()->count(), 'Tags should still exist');
    }

    /**
     * Tests that unlinking with last parameter set to false
     * will not remove entities from the association property
     */
    public function testUnlinkWithoutPropertyClean(): void
    {
        $joint = $this->getCollectionLocator()->get('SpecialTags');
        $articles = $this->getCollectionLocator()->get('Articles');
        $tags = $this->getCollectionLocator()->get('Tags');

        $assoc = $articles->belongsToMany('Tags', [
            'target' => $tags,
            'through' => $joint,
            'joinCollection' => 'special_tags',
            'conditions' => ['SpecialTags.highlighted' => true],
        ]);
        $document = $articles->get('000000000000000000000002', ...['contain' => 'Tags']);
        $initial = $document->tags;
        $this->assertCount(1, $initial);

        $this->assertTrue($assoc->unlink($document, $initial, ['cleanProperty' => false]));
        $this->assertNotEmpty($document->get('tags'), 'Property should not be empty');
        $this->assertEquals($initial, $document->get('tags'), 'Property should be untouched');

        $new = $articles->get('000000000000000000000002', ...['contain' => 'Tags']);
        $this->assertCount(0, $new->tags, 'DB should be clean');
    }

    /**
     * Tests that unlink returns false when junction table deleteMany fails
     */
    public function testUnlinkFailure(): void
    {
        $connection = ConnectionManager::get('test_mongo');
        /** @var \Crustum\Mongo\ODM\BaseCollection&\Mockery\MockInterface $joint */
        $joint = Mockery::mock(new BaseCollection(['alias' => 'SpecialTags', 'collection' => 'special_tags', 'connection' => $connection]))
            ->makePartial();

        $articles = $this->getCollectionLocator()->get('Articles');

        $assoc = $articles->belongsToMany('Tags', [
            'through' => $joint,
        ]);

        $document = $articles->get('000000000000000000000002', contain: ['Tags']);
        $this->assertCount(1, $document->tags);

        $joint->shouldReceive('deleteMany')
            ->once()
            ->andReturn(false);

        $this->assertFalse($assoc->unlink($document, $document->tags));
        $this->assertCount(1, $document->tags);
    }

    /**
     * Tests that replaceLink requires the sourceEntity to have primaryKey values
     * for the source entity
     */
    public function testReplaceWithMissingPrimaryKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Could not find primary key value for source entity');
        $config = [
            'target' => $this->tag,
            'joinCollection' => 'tags_articles',
        ];
        $assoc = new BelongsToMany('Test', $this->article, $config);
        $document = new Document(['foo' => 1], ['markNew' => false]);
        $tags = [new Document(['_id' => '000000000000000000000002']), new Document(['_id' => '000000000000000000000003'])];
        $assoc->replaceLinks($document, $tags);
    }

    /**
     * Test that replaceLinks() can saveAssociated an empty set, removing all rows.
     */
    public function testReplaceLinksUpdateToEmptySet(): void
    {
        $joint = $this->getCollectionLocator()->get('ArticlesTags');
        $articles = $this->getCollectionLocator()->get('Articles');
        $tags = $this->getCollectionLocator()->get('Tags');

        $assoc = $articles->belongsToMany('Tags', [
            'target' => $tags,
            'through' => $joint,
            'joinCollection' => 'articles_tags',
        ]);

        $document = $articles->get('000000000000000000000001', ...['contain' => 'Tags']);
        $this->assertCount(2, $document->tags);

        $assoc->replaceLinks($document, []);
        $this->assertSame([], $document->tags, 'Property should be empty');
        $this->assertFalse($document->isDirty('tags'), 'Property should be cleaned');

        $new = $articles->get('000000000000000000000001', ...['contain' => 'Tags']);
        $this->assertSame([], $new->tags, 'Should not be data in db');
    }

    /**
     * Tests that replaceLinks will delete entities not present in the passed,
     * array, maintain those are already persisted and were passed and also
     * insert the rest.
     */
    public function testReplaceLinkSuccess(): void
    {
        $joint = $this->getCollectionLocator()->get('ArticlesTags');
        $articles = $this->getCollectionLocator()->get('Articles');
        $tags = $this->getCollectionLocator()->get('Tags');

        $assoc = $articles->belongsToMany('Tags', [
            'target' => $tags,
            'through' => $joint,
        ]);
        $document = $articles->get('000000000000000000000001', ...['contain' => 'Tags']);

        // 1=existing, 2=removed, 3=new link, & new tag
        $tagData = [
            new Document(['_id' => '000000000000000000000001'], ['markNew' => false]),
            new Document(['_id' => '000000000000000000000003']),
            new Document(['name' => 'net new']),
        ];

        $result = $assoc->replaceLinks($document, $tagData, ['associated' => false]);
        $this->assertTrue($result);
        $this->assertSame($tagData, $document->tags, 'Tags should match replaced objects');
        $this->assertFalse($document->isDirty('tags'), 'Should be clean');

        $fresh = $articles->get('000000000000000000000001', ...['contain' => 'Tags']);
        $this->assertCount(3, $fresh->tags, 'Records should be in db');

        $this->assertNotEmpty($tags->get('000000000000000000000002'), 'Unlinked tag should still exist');
    }

    /**
     * Tests that replaceLinks() will contain() the target table when
     * there are conditions present on the association.
     *
     * In this case the replacement will fail because the association conditions
     * hide the fixture data.
     */
    public function testReplaceLinkWithConditions(): void
    {
        $this->markTestSkipped('F25: replaceLinks ignores association conditions — empty replace still unlinks non-matching junction rows; see 18-orm-tests-port-plan.md.');
        $joint = $this->getCollectionLocator()->get('SpecialTags');
        $articles = $this->getCollectionLocator()->get('Articles');
        $tags = $this->getCollectionLocator()->get('Tags');

        $assoc = $articles->belongsToMany('Tags', [
            'target' => $tags,
            'through' => $joint,
            'joinCollection' => 'special_tags',
            'conditions' => ['SpecialTags.highlighted' => true],
        ]);
        $document = $articles->get('000000000000000000000001', ...['contain' => 'Tags']);

        $result = $assoc->replaceLinks($document, [], ['associated' => false]);
        $this->assertTrue($result);
        $this->assertSame([], $document->tags, 'Tags should match replaced objects');
        $this->assertFalse($document->isDirty('tags'), 'Should be clean');

        $fresh = $articles->get('000000000000000000000001', ...['contain' => 'Tags']);
        $this->assertCount(0, $fresh->tags, 'Association should be empty');

        $jointCount = $joint->find()->where(['article_id' => '000000000000000000000001'])->count();
        $this->assertSame(1, $jointCount, 'Non matching joint record should remain.');
    }

    /**
     * Test that replaceLinks() will apply finder conditions
     * defined in the junction table associations if they exist.
     */
    public function testReplaceLinkWithFinderInJunctionAssociations(): void
    {
        $this->setAppNamespace('TestApp');

        $joint = $this->getCollectionLocator()->get('ArticlesTags');
        $articles = $this->getCollectionLocator()->get('Articles');
        $tags = $this->getCollectionLocator()->get('Tags');

        // Update an article to not match the association finder.
        $articles->updateAll(['published' => 'N'], ['_id' => '000000000000000000000001']);
        $assoc = $tags->associations()->get('Articles')
            ->setFinder('published')
            ->setThrough($joint);
        $document = $tags->get('000000000000000000000001', ...['contain' => 'Articles']);
        $this->assertCount(1, $document->articles);

        $result = $assoc->replaceLinks($document, [], ['associated' => false]);
        $this->assertTrue($result);
        $this->assertSame([], $document->articles, 'Articles should match replaced objects');
        $this->assertFalse($document->isDirty('articles'), 'Should be clean');

        $fresh = $tags->get('000000000000000000000001', ...['contain' => 'Articles']);
        $this->assertCount(0, $fresh->articles, 'Association should be empty');

        $other = $joint->find()->where(['tag_id' => '000000000000000000000001'])->toArray();
        $this->assertCount(1, $other, 'Non matching joint record should remain.');
        $this->assertSame('000000000000000000000001', $other[0]->article_id);
    }

    /**
     * Test that replaceLinks() loads junction records with the correct entity class
     */
    public function testReplaceLinksFetchCorrectJunctionEntity(): void
    {
        $joint = $this->getCollectionLocator()->get('ArticlesTags');
        $articles = $this->getCollectionLocator()->get('Articles');
        $tags = $this->getCollectionLocator()->get('Tags');

        $assoc = $articles->belongsToMany('Tags', [
            'target' => $tags,
            'through' => $joint,
        ]);
        $joint->setDocumentClass(ArticlesTag::class);

        $joint->getEventManager()->on('Collection.afterDelete', function ($event, $document): void {
            $this->assertInstanceOf(ArticlesTag::class, $document);
            $this->assertNotEmpty($document->tag_id);
            $this->assertNotEmpty($document->article_id);
        });

        $document = $articles->get('000000000000000000000001', ...['contain' => 'Tags']);
        $this->assertCount(2, $document->tags);

        $assoc->replaceLinks($document, []);
    }

    /**
     * Test that replaceLinks() loads junction records with the correct entity class
     */
    public function testReplaceLinksFinderCondition(): void
    {
        $this->setAppNamespace('TestApp');

        $joint = $this->getCollectionLocator()->get('ArticlesTags');
        $tags = $this->getCollectionLocator()->get('Tags');

        $assoc = $tags->associations()->get('Articles')
            ->setFinder(['published' => ['title' => 'First Article']])
            ->setThrough($joint);
        $document = $tags->get('000000000000000000000001', ...['contain' => 'Articles']);
        $this->assertCount(1, $document->articles);

        $assoc->replaceLinks($document, []);

        $fresh = $tags->get('000000000000000000000001', ...['contain' => 'Articles']);
        $this->assertCount(0, $fresh->articles, 'Association should be empty');

        $other = $joint->find()->where(['tag_id' => '000000000000000000000001'])->toArray();
        $this->assertCount(1, $other, 'Non matching joint record should remain.');
        $this->assertSame('000000000000000000000002', $other[0]->article_id);
    }

    /**
     * Test that replace links use loads junction records with a concrete
     * target table so that finders with contain will work.
     */
    public function testReplaceLinksFinderContain(): void
    {
        $this->setAppNamespace('TestApp');

        $joint = $this->getCollectionLocator()->get('ArticlesTags');
        $articles = $this->getCollectionLocator()->get('Articles');
        $tags = $this->getCollectionLocator()->get('Tags');

        $assoc = $tags->associations()->get('Articles')
            ->setFinder('withAuthors')
            ->setThrough($joint);
        $tag = $tags->get('000000000000000000000001');
        $article = $articles->get('000000000000000000000001');

        $assoc->replaceLinks($tag, [$article]);

        $fresh = $tags->get('000000000000000000000001', ...['contain' => 'Articles']);
        $this->assertCount(1, $fresh->articles);
    }

    /**
     * Tests replaceLinks with failing domain rules and new link targets.
     */
    public function testReplaceLinkFailingDomainRules(): void
    {
        $this->markTestSkipped('F25: replaceLinks does not roll back existing links when the new target fails domain rules; see 18-orm-tests-port-plan.md.');
        $articles = $this->getCollectionLocator()->get('Articles');
        $tags = $this->getCollectionLocator()->get('Tags');
        $tags->getEventManager()->on('Collection.buildRules', function (EventInterface $event, RulesChecker $rules): void {
            $rules->add(fn(): false => false, 'rule', ['errorField' => 'name', 'message' => 'Bad data']);
        });

        $assoc = $articles->belongsToMany('Tags', [
            'target' => $tags,
            'through' => $this->getCollectionLocator()->get('ArticlesTags'),
        ]);
        $document = $articles->get('000000000000000000000001', ...['contain' => 'Tags']);
        $originalCount = count($document->tags);

        $tags = [
            new Document(['name' => 'tag99', 'description' => 'Best tag']),
        ];
        $result = $assoc->replaceLinks($document, $tags);
        $this->assertFalse($result, 'replace should have failed.');
        $this->assertNotEmpty($tags[0]->getErrors(), 'Bad entity should have errors.');

        $document = $articles->get('000000000000000000000001', ...['contain' => 'Tags']);
        $this->assertCount($originalCount, $document->tags, 'Should not have changed.');
        $this->assertSame('tag1', $document->tags[0]->name);
    }

    /**
     * Tests that replaceLinks will delete entities not present in the passed,
     * array, maintain those are already persisted and were passed and also
     * insert the rest.
     */
    public function testReplaceLinkBinaryUuid(): void
    {
        $this->markTestSkipped('BinaryUuid fixtures/schema use string _id; junction FK type must match bin_uuid PK — rewrite fixtures + schema_mongo (F29).');
        $items = $this->getCollectionLocator()->get('BinaryUuidItems');
        $tags = $this->getCollectionLocator()->get('BinaryUuidTags');

        $items->belongsToMany('BinaryUuidTags', [
            'target' => $tags,
        ]);
        $itemName = 'Item 1';
        $item = $items->find()->where(['BinaryUuidItems.name' => $itemName])->firstOrFail();
        $existingTag = $tags->find()->where(['BinaryUuidTags.name' => 'Defect'])->firstOrFail();

        // 1=existing, 2=new tag
        $item->binary_uuid_tags = [
            $existingTag,
            new Document(['name' => 'net new']),
        ];
        $item->name = 'Updated';

        $items->saveOrFail($item);

        $refresh = $items->find()->where(['_id' => $item->getId()])->contain('BinaryUuidTags')->firstOrFail();
        $this->assertCount(2, $refresh->binary_uuid_tags, 'Two tags should exist');

        $refresh->binary_uuid_tags = [$refresh->binary_uuid_tags[0]];
        $items->save($refresh);

        $refresh = $items->get($item->getId(), ...['contain' => 'BinaryUuidTags']);
        $this->assertCount(1, $refresh->binary_uuid_tags, 'One tag should remain');
    }

    public function testReplaceLinksComplexTypeForeignKey(): void
    {
        $this->markTestSkipped('Hangs on composite-type foreign key loading — pre-existing (F#); SelectLoader casts array key to string.');
        $articles = $this->fetchCollection('CompositeKeyArticles');
        $tags = $this->fetchCollection('Tags');

        $articles->belongsToMany('Tags', [
            'foreignKey' => ['author_id', 'created'],
        ]);

        $article = $articles->newDocument([
            'author_id' => '000000000000000000000001',
            'body' => 'First post',
            'created' => new DateTime(),
        ]);
        $articles->saveOrFail($article);
        $tag1 = $tags->find()->where(['Tags.name' => 'tag1'])->firstOrFail();
        $tag2 = $tags->find()->where(['Tags.name' => 'tag2'])->firstOrFail();

        $findArticle = (fn($article) => $articles->find()
            ->where(['CompositeKeyArticles.author_id' => $article->author_id])
            ->contain('Tags')
            ->firstOrFail());

        $article = $findArticle($article);
        $this->assertEmpty($article->tags);

        // Create the first link
        $article = $articles->patchDocument($article, ['tags' => ['_ids' => [$tag1->getId()]]]);
        $result = $articles->save($article, ['associated' => 'Tags']);
        $this->assertNotEmpty($result);
        $this->assertCount(1, $result->tags);
        $this->assertEquals($tag1->getId(), $result->tags[0]->getId());

        // Add second tag. Reload tag objects so created fields have different
        // instances.
        $article = $findArticle($article);
        $article = $articles->patchDocument($article, ['tags' => ['_ids' => [$tag1->getId(), $tag2->getId()]]]);

        $result = $articles->save($article, ['associated' => 'Tags']);

        // Check in memory entity.
        $this->assertNotEmpty($result);
        $this->assertCount(2, $result->tags);
        $this->assertEquals('tag1', $result->tags[0]->name);
        $this->assertEquals('tag2', $result->tags[1]->name);

        // Reload to check persisted state.
        $result = $findArticle($article);
        $this->assertNotEmpty($result);
        $this->assertCount(2, $result->tags);
        $this->assertEquals('tag1', $result->tags[0]->name);
        $this->assertEquals('tag2', $result->tags[1]->name);
    }

    public function testReplaceLinksMissingKeyData(): void
    {
        $this->markTestSkipped('Hangs intermittently on missing-key link data — pre-existing (F#); duplicate-key state after prior tests.');
        $articles = $this->fetchCollection('Articles');
        $tags = $this->fetchCollection('Tags');

        $articles->belongsToMany('Tags');
        $article = $articles->find()->firstOrFail();

        $tag1 = $tags->find()->where(['Tags.name' => 'tag1'])->firstOrFail();
        $tag1->_joinData = new ArticlesTag(['tag_id' => '000000000000000000000099']);

        $article->tags = [$tag1];
        $articles->saveOrFail($article, ['associated' => ['Tags']]);

        $this->assertCount(1, $article->tags);
    }

    /**
     * Provider for empty values
     *
     * @return array
     */
    public static function emptyProvider(): array
    {
        return [
            [''],
            [false],
            [null],
            [[]],
        ];
    }

    /**
     * Test that saving an empty set on create works.
     *
     * @param mixed $value
     */
    #[DataProvider('emptyProvider')]
    public function testSaveAssociatedEmptySetSuccess(string|bool|array|null $value): void
    {
        $collection = new BaseCollection(['alias' => 'Articles', 'collection' => 'articles']);
        $collection->setSchemaFromArray([]);

        $assoc = Mockery::mock(BelongsToMany::class, ['tags', $collection])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $document = new Document([
            '_id' => '000000000000000000000001',
            'tags' => $value,
        ], ['markNew' => true]);

        $assoc->setSaveStrategy(BelongsToMany::SAVE_REPLACE);
        $assoc->shouldReceive('replaceLinks')->never();
        $assoc->shouldReceive('saveTarget')->never();
        $this->assertSame($document, $assoc->saveAssociated($document));
    }

    /**
     * Test that saving an empty set on update works.
     *
     * @param mixed $value
     */
    #[DataProvider('emptyProvider')]
    public function testSaveAssociatedEmptySetUpdateSuccess(string|bool|array|null $value): void
    {
        $collection = new BaseCollection(['alias' => 'Articles', 'collection' => 'articles']);
        $collection->setSchemaFromArray([]);

        $assoc = Mockery::mock(BelongsToMany::class, ['tags', $collection])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $document = new Document([
            '_id' => '000000000000000000000001',
            'tags' => $value,
        ], ['markNew' => false]);

        $assoc->setSaveStrategy(BelongsToMany::SAVE_REPLACE);
        $assoc->shouldReceive('replaceLinks')
            ->once()
            ->andReturn(true);

        $assoc->shouldReceive('_saveTarget')->never();

        $this->assertSame($document, $assoc->saveAssociated($document));
    }

    /**
     * Tests saving with replace strategy returning true
     */
    public function testSaveAssociatedWithReplace(): void
    {
        $collection = new BaseCollection(['alias' => 'Articles', 'collection' => 'articles']);
        $collection->setSchemaFromArray([]);

        $assoc = Mockery::mock(BelongsToMany::class, ['tags', $collection])
            ->makePartial();
        $document = new Document([
            '_id' => '000000000000000000000001',
            'tags' => [
                new Document(['name' => 'foo']),
            ],
        ]);

        $options = ['foo' => 'bar'];
        $assoc->setSaveStrategy(BelongsToMany::SAVE_REPLACE);
        $assoc->shouldReceive('replaceLinks')
            ->once()
            ->with($document, $document->tags, $options)
            ->andReturn(true);
        $this->assertSame($document, $assoc->saveAssociated($document, $options));
    }

    /**
     * Tests saving with replace strategy returning true
     */
    public function testSaveAssociatedWithReplaceReturnFalse(): void
    {
        $collection = new BaseCollection(['alias' => 'Articles', 'collection' => 'articles']);
        $collection->setSchemaFromArray([]);

        $assoc = Mockery::mock(BelongsToMany::class, ['tags', $collection])
            ->makePartial();
        $document = new Document([
            '_id' => '000000000000000000000001',
            'tags' => [
                new Document(['name' => 'foo']),
            ],
        ]);

        $options = ['foo' => 'bar'];
        $assoc->setSaveStrategy(BelongsToMany::SAVE_REPLACE);
        $assoc->shouldReceive('replaceLinks')
            ->once()
            ->with($document, $document->tags, $options)
            ->andReturn(false);
        $this->assertFalse($assoc->saveAssociated($document, $options));
    }

    /**
     * Tests that setTargetForeignKey() returns the correct configured value
     */
    public function testSetTargetForeignKey(): void
    {
        $assoc = new BelongsToMany('Test', $this->article, [
            'target' => $this->tag,
        ]);
        $this->assertSame('tag_id', $assoc->getTargetForeignKey());
        $assoc->setTargetForeignKey('another_key');
        $this->assertSame('another_key', $assoc->getTargetForeignKey());

        $assoc = new BelongsToMany('Test', $this->article, [
            'target' => $this->tag,
            'targetForeignKey' => 'foo',
        ]);
        $this->assertSame('foo', $assoc->getTargetForeignKey());
    }

    /**
     * Tests that custom foreignKeys are properly transmitted to involved associations
     * when they are customized
     */
    public function testJunctionWithCustomForeignKeys(): void
    {
        $assoc = new BelongsToMany('Test', $this->article, [
            'target' => $this->tag,
            'foreignKey' => 'Art',
            'targetForeignKey' => 'Tag',
        ]);
        $junction = $assoc->junction();
        $this->assertSame('Art', $junction->getAssociation('Articles')->getForeignKey());
        $this->assertSame('Tag', $junction->getAssociation('Tags')->getForeignKey());

        $inverseRelation = $this->tag->getAssociation('Articles');
        $this->assertSame('Tag', $inverseRelation->getForeignKey());
        $this->assertSame('Art', $inverseRelation->getTargetForeignKey());
    }

    /**
     * Test that fallback class is used for join table even when fallback
     * class usage is turned off for table locator.
     */
    public function testFallbackClassForJunction(): void
    {
        $assoc = new BelongsToMany('Test', $this->article, [
            'target' => $this->tag,
        ]);
        $assoc->setCollectionLocator(new CollectionLocator());

        $junction = $assoc->junction();
        $this->assertInstanceOf(BaseCollection::class, $junction);
    }

    /**
     * Test that a missing `through` collection falls back to BaseCollection
     * (ODM junctions always fall back; there is no SQL table class lookup).
     */
    public function testNoFallbackClassForThrough(): void
    {
        $assoc = new BelongsToMany('Test', $this->article, [
            'target' => $this->tag,
            'through' => 'NonExistentThrough',
        ]);
        $collectionLocator = new CollectionLocator();
        $assoc->setCollectionLocator($collectionLocator);
        $junction = $assoc->junction();
        $this->assertInstanceOf(BaseCollection::class, $junction);
    }

    /**
     * Tests that property is being set using the constructor options.
     */
    public function testPropertyOption(): void
    {
        $config = ['propertyName' => 'thing_placeholder'];
        $association = new BelongsToMany('Thing', $this->getCollectionLocator()->get('Authors'), $config);
        $this->assertSame('thing_placeholder', $association->getProperty());
    }

    /**
     * Test that plugin names are omitted from property()
     */
    public function testPropertyNoPlugin(): void
    {
        $config = [
            'target' => $this->tag,
        ];
        $association = new BelongsToMany('Contacts.Tags', $this->article, $config);
        $this->assertSame('tags', $association->getProperty());
    }

    /**
     * Test that the generated associations are correct.
     */
    public function testGeneratedAssociations(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $tags = $this->getCollectionLocator()->get('Tags');
        $conditions = ['SpecialTags.highlighted' => true];
        $assoc = $articles->belongsToMany('Tags', [
            'target' => $tags,
            'foreignKey' => 'foreign_key',
            'targetForeignKey' => 'target_foreign_key',
            'through' => 'SpecialTags',
            'conditions' => $conditions,
        ]);
        // Generate associations
        $assoc->junction();

        $tagAssoc = $articles->getAssociation('Tags');
        $this->assertNotEmpty($tagAssoc, 'btm should exist');
        $this->assertEquals($conditions, $tagAssoc->getConditions());
        $this->assertSame('target_foreign_key', $tagAssoc->getTargetForeignKey());
        $this->assertSame('foreign_key', $tagAssoc->getForeignKey());

        $jointAssoc = $articles->getAssociation('SpecialTags');
        $this->assertNotEmpty($jointAssoc, 'has many to junction should exist');
        $this->assertInstanceOf(HasMany::class, $jointAssoc);
        $this->assertSame('foreign_key', $jointAssoc->getForeignKey());

        $articleAssoc = $tags->getAssociation('Articles');
        $this->assertNotEmpty($articleAssoc, 'reverse btm should exist');
        $this->assertInstanceOf(BelongsToMany::class, $articleAssoc);
        $this->assertEquals($conditions, $articleAssoc->getConditions());
        $this->assertSame('foreign_key', $articleAssoc->getTargetForeignKey(), 'keys should swap');
        $this->assertSame('target_foreign_key', $articleAssoc->getForeignKey(), 'keys should swap');

        $jointAssoc = $tags->getAssociation('SpecialTags');
        $this->assertNotEmpty($jointAssoc, 'has many to junction should exist');
        $this->assertInstanceOf(HasMany::class, $jointAssoc);
        $this->assertSame('target_foreign_key', $jointAssoc->getForeignKey());
    }

    /**
     * Tests that eager loading requires association keys
     */
    public function testEagerLoadingRequiresPrimaryKey(): void
    {
        $this->markTestSkipped('SQL-only: CollectionSchema has no dropConstraint(\'primary\') — Mongo has no constraints (F19).');
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('The `tags` table does not define a primary key');
        $collection = $this->getCollectionLocator()->get('Articles');
        $tags = $this->getCollectionLocator()->get('Tags');
        $tags->getSchema()->dropConstraint('primary');

        $collection->belongsToMany('Tags');
        $collection->find()->contain('Tags')->first();
    }

    /**
     * Tests that fetching belongsToMany association will not force
     * all fields being returned, but instead will honor the select() clause
     *
     * @see https://github.com/cakephp/cakephp/issues/7916
     */
    public function testEagerLoadingBelongsToManyLimitedFields(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->belongsToMany('Tags');

        $result = $collection
            ->find()
            ->contain(['Tags' => fn(SelectQuery $q): SelectQuery => $q->select(['_id'])])
            ->first();

        $this->assertNotEmpty($result->tags[0]->getId());
        $this->assertEmpty($result->tags[0]->name);

        $result = $collection
            ->find()
            ->contain([
                'Tags' => [
                    'fields' => [
                        'Tags.name',
                    ],
                ],
            ])
            ->first();
        $this->assertNotEmpty($result->tags[0]->name);
        $this->assertEmpty($result->tags[0]->getId());
    }

    /**
     * Tests that fetching belongsToMany association will retain autoFields(true) if it was used.
     *
     * @see https://github.com/cakephp/cakephp/issues/8052
     */
    public function testEagerLoadingBelongsToManyLimitedFieldsWithAutoFields(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->belongsToMany('Tags');

        $result = $collection
            ->find()
            ->contain(['Tags' => fn(SelectQuery $q): SelectQuery => $q
                ->select(['two' => $q->func()->add(1, 1)])
                ->enableAutoFields()])
            ->first();

        $this->assertNotEmpty($result->tags[0]->two, 'Should have computed field');
        $this->assertNotEmpty($result->tags[0]->name, 'Should have standard field');
    }

    /**
     * Test that association proxy find() works with no join records
     */
    public function testAssociationProxyFindNoJoinRecords(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->belongsToMany('Tags');
        $collection->Tags->junction()->deleteAll([]);

        $query = $collection->Tags->find();
        $result = $query->toArray();
        $this->assertCount(3, $result);
    }

    /**
     * Test that association proxy find() applies joins when conditions are involved.
     */
    public function testAssociationProxyFindWithConditions(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->belongsToMany('Tags', [
            'conditions' => ['SpecialTags.highlighted' => true],
            'through' => 'SpecialTags',
        ]);
        $query = $collection->Tags->find();
        $result = $query->toArray();
        $this->assertCount(1, $result);
        $this->assertSame('000000000000000000000001', $result[0]->getId());
    }

    /**
     * Test that association proxy find() applies complex conditions
     */
    public function testAssociationProxyFindWithComplexConditions(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->belongsToMany('Tags', [
            'conditions' => [
                'OR' => [
                    'SpecialTags.highlighted' => true,
                ],
            ],
            'through' => 'SpecialTags',
        ]);
        $query = $collection->Tags->find();
        $result = $query->toArray();
        $this->assertCount(1, $result);
        $this->assertSame('000000000000000000000001', $result[0]->getId());
    }

    /**
     * Test that matching() works on belongsToMany associations.
     */
    public function testBelongsToManyAssociationWithArrayConditions(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->belongsToMany('Tags', [
            'conditions' => ['SpecialTags.highlighted' => true],
            'through' => 'SpecialTags',
        ]);
        $query = $collection->find()->matching('Tags', fn(SelectQuery $q): SelectQuery => $q->where(['Tags.name' => 'tag1']));
        $results = $query->toArray();
        $this->assertCount(1, $results);
        $this->assertNotEmpty($results[0]->_matchingData);
    }

    /**
     * Test that matching() works on belongsToMany associations.
     */
    public function testBelongsToManyAssociationWithExpressionConditions(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->belongsToMany('Tags', [
            'conditions' => [new QueryExpression("name LIKE 'tag%'")],
            'through' => 'SpecialTags',
        ]);
        $query = $collection->find()->matching('Tags', fn(SelectQuery $q): SelectQuery => $q->where(['Tags.name' => 'tag1']));
        $results = $query->toArray();
        $this->assertCount(1, $results);
        $this->assertNotEmpty($results[0]->_matchingData);
    }

    /**
     * Test that association proxy find() with matching resolves joins correctly
     */
    public function testAssociationProxyFindWithConditionsMatching(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->belongsToMany('Tags', [
            'conditions' => ['SpecialTags.highlighted' => true],
            'through' => 'SpecialTags',
        ]);
        $query = $collection->Tags->find()->matching('Articles', fn(SelectQuery $query): SelectQuery => $query->where(['Articles._id' => '000000000000000000000001']));
        // The inner join on special_tags excludes the results.
        $this->assertSame(0, $query->count());
    }

    /**
     * Test custom binding key for target table association
     */
    public function testCustomTargetBindingKeyContain(): void
    {
        $this->getCollectionLocator()->get('ArticlesTags')
            ->belongsTo('SpecialTags', [
                'bindingKey' => 'tag_id',
                'foreignKey' => 'tag_id',
            ]);

        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->belongsToMany('SpecialTags', [
            'through' => 'ArticlesTags',
            'targetForeignKey' => 'tag_id',
        ]);

        $results = $collection->find()
            ->contain('SpecialTags', fn($query) => $query->orderBy(['SpecialTags.tag_id']))
            ->where(['_id' => '000000000000000000000002'])
            ->toArray();

        $this->assertCount(1, $results);
        $this->assertCount(2, $results[0]->special_tags);

        $this->assertSame('000000000000000000000002', $results[0]->special_tags[0]->getId());
        $this->assertSame('000000000000000000000001', $results[0]->special_tags[0]->tag_id);

        $this->assertSame('000000000000000000000001', $results[0]->special_tags[1]->getId());
        $this->assertSame('000000000000000000000003', $results[0]->special_tags[1]->tag_id);
    }

    /**
     * Test custom binding key for target table association
     */
    public function testCustomTargetBindingKeyLink(): void
    {
        $this->getCollectionLocator()->get('ArticlesTags')
            ->belongsTo('SpecialTags', [
                'bindingKey' => 'tag_id',
                'foreignKey' => 'tag_id',
            ]);

        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->belongsToMany('SpecialTags', [
            'through' => 'ArticlesTags',
            'targetForeignKey' => 'tag_id',
        ]);

        $specialTag = $collection->SpecialTags->newDocument([
            'article_id' => '000000000000000000000002',
            'tag_id' => '000000000000000000000002',
        ]);
        $collection->SpecialTags->save($specialTag);

        $article = $collection->get('000000000000000000000002');
        $this->assertTrue($collection->SpecialTags->link($article, [$specialTag]));

        $results = $collection->find()
            ->contain('SpecialTags')
            ->where(['_id' => '000000000000000000000002'])
            ->toArray();

        $this->assertCount(1, $results);
        $this->assertCount(3, $results[0]->special_tags);
    }

    /**
     * Test custom binding key for target table association
     */
    public function testBindingKeyMatching(): void
    {
        $collection = $this->getCollectionLocator()->get('Tags');
        $collection->belongsToMany('Articles', [
            'through' => 'ArticlesTagsBindingKeys',
            'foreignKey' => 'tagname',
            'bindingKey' => 'name',
        ]);
        $query = $collection->find()
            ->matching('Articles', fn($q) => $q->where(['Articles._id' => ['$exists' => true]]));
        $results = $query->all();

        // 4 records in the junction table.
        $this->assertCount(4, $results);
    }

    public function testEagerLoaderConnectionRole(): void
    {
        $connection = ConnectionManager::get('test_mongo');
        $articles = $this->getCollectionLocator()->get('Articles')->setConnection($connection);
        $articles->belongsToMany('Tags');

        $query = $articles->find();
        $this->assertSame(
            Connection::ROLE_WRITE,
            $query->getConnectionRole(),
            'Select queries default to the write role',
        );

        $query->useReadRole();
        $this->assertSame(
            Connection::ROLE_READ,
            $query->getConnectionRole(),
            'useReadRole() routes the query to the read role',
        );

        $target = $articles->Tags->getTarget();
        $child = $target->find();
        $loader = new SelectLoader([
            'finder' => fn() => $child,
            'foreignKey' => 'tag_id',
            'bindingKey' => '_id',
            'associationType' => 'manyToMany',
            'nestKey' => 'tags',
        ]);
        $callback = $loader->buildEagerLoader(['query' => $query]);
        $callback([]);

        $this->assertSame(
            Connection::ROLE_READ,
            $child->getConnectionRole(),
            'Child association query inherits the parent read role',
        );
    }
}

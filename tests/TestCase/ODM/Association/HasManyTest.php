<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM\Association;

use Cake\Database\Expression\OrderClauseExpression;
use Cake\Database\ExpressionInterface;
use Cake\Database\TypeMap;
use Cake\Datasource\ConnectionManager;
use Cake\Datasource\EntityInterface;
use Cake\Datasource\ResultSetInterface;
use Closure;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\ODM\Association;
use Crustum\Mongo\ODM\Association\HasMany;
use Crustum\Mongo\ODM\Association\Loader\SelectLoader;
use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\ODM\Document;
use Crustum\Mongo\ODM\Query\SelectQuery;
use Crustum\Mongo\ODM\ResultSet;
use Crustum\Mongo\Test\TestCase\ODM\TestCase;
use InvalidArgumentException;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use function Cake\I18n\__;

/**
 * Tests HasMany class
 */
#[CoversClass(HasMany::class)]
class HasManyTest extends TestCase
{
    /**
     * Fixtures
     *
     * @var array<string>
     */
    protected array $fixtures = [
        'plugin.Crustum/Mongo.Categories',
        'plugin.Crustum/Mongo.Comments',
        'plugin.Crustum/Mongo.Articles',
        'plugin.Crustum/Mongo.Tags',
        'plugin.Crustum/Mongo.Authors',
        'plugin.Crustum/Mongo.Users',
        'plugin.Crustum/Mongo.ArticlesTags',
    ];

    /**
     * @var \Crustum\Mongo\ODM\BaseCollection
     */
    protected $author;

    /**
     * @var \Crustum\Mongo\ODM\BaseCollection&\Mockery\MockInterface
     */
    protected $article;

    /**
     * @var \Cake\Database\TypeMap
     */
    protected $articlesTypeMap;

    /**
     * @var bool
     */
    protected $autoQuote;

    /**
     * Set up
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setAppNamespace('TestApp');

        $this->author = $this->getCollectionLocator()->get('Authors', [
            'schema' => [
                '_id' => ['type' => 'objectid'],
                'name' => ['type' => 'string'],
                '_constraints' => [
                    'primary' => ['type' => 'primary', 'columns' => ['_id']],
                ],
            ],
        ]);
        $connection = ConnectionManager::get('test_mongo');
        $article = new BaseCollection([
            'alias' => 'Articles',
            'collection' => 'articles',
            'connection' => $connection,
        ]);
        $article->setSchemaFromArray([
            '_id' => ['type' => 'objectid'],
            'title' => ['type' => 'string'],
            'author_id' => ['type' => 'objectid'],
            '_constraints' => [
                'primary' => ['type' => 'primary', 'columns' => ['_id']],
            ],
        ]);
        $this->article = Mockery::mock($article)->makePartial();

        $this->articlesTypeMap = new TypeMap([
            'Articles._id' => 'objectid',
            '_id' => 'objectid',
            'Articles.title' => 'string',
            'title' => 'string',
            'Articles.author_id' => 'objectid',
            'author_id' => 'objectid',
            'Articles__id' => 'objectid',
            'Articles__title' => 'string',
            'Articles__author_id' => 'objectid',
        ]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        // Clear the table locator to avoid state leaking to next tests
        $this->getCollectionLocator()->clear();
    }

    /**
     * Tests that foreignKey() returns the correct configured value
     */
    public function testSetForeignKey(): void
    {
        $assoc = new HasMany('Articles', $this->author);
        $this->assertSame('author_id', $assoc->getForeignKey());
        $this->assertSame($assoc, $assoc->setForeignKey('another_key'));
        $this->assertSame('another_key', $assoc->getForeignKey());
    }

    /**
     * Test that foreignKey generation ignores database names in target table.
     */
    public function testForeignKeyIgnoreDatabaseName(): void
    {
        $this->author->setCollection('schema.authors');
        $assoc = new HasMany('Articles', $this->author);
        $this->assertSame('author_id', $assoc->getForeignKey());
    }

    /**
     * Tests that the association reports it can be joined
     */
    public function testCanBeJoined(): void
    {
        $assoc = new HasMany('Test', $this->author);
        $this->assertFalse($assoc->canBeJoined());
    }

    /**
     * Tests setSort() method
     */
    public function testSetSort(): void
    {
        $assoc = new HasMany('Test', $this->author);
        $this->assertNull($assoc->getSort());

        $assoc->setSort('id ASC');
        $this->assertSame('id ASC', $assoc->getSort());

        $assoc->setSort(['_id' => 'ASC']);
        $this->assertSame(['_id' => 'ASC'], $assoc->getSort());

        $closure = (fn(): array => ['_id' => 'ASC']);
        $assoc->setSort($closure);
        $this->assertSame($closure, $assoc->getSort());

        $expression = new OrderClauseExpression('_id', 'ASC');
        $assoc->setSort($expression);
        $this->assertSame($expression, $assoc->getSort());
    }

    /**
     * Tests that sorting works using the accepted types for `setSort()`.
     */
    public function testSorting(): void
    {
        $authors = $this->getCollectionLocator()->get('Authors');
        $assoc = $authors->Articles;

        $field = 'Articles._id';

        $ids = static fn(array $articles): array => array_map(
            static fn(EntityInterface|array $article): mixed => $article instanceof EntityInterface
                ? $article->get('_id')
                : ($article['_id'] ?? null),
            $articles,
        );

        $assoc->setSort("{$field} DESC");
        $result = $authors->get('000000000000000000000001', ...['contain' => 'Articles']);
        $this->assertSame(['000000000000000000000003', '000000000000000000000001'], $ids($result['articles']));

        $assoc->setSort(['Articles._id' => 'DESC']);
        $result = $authors->get('000000000000000000000001', ...['contain' => 'Articles']);
        $this->assertSame(['000000000000000000000003', '000000000000000000000001'], $ids($result['articles']));

        $assoc->setSort(fn(): array => ['Articles._id' => 'DESC']);
        $result = $authors->get('000000000000000000000001', ...['contain' => 'Articles']);
        $this->assertSame(['000000000000000000000003', '000000000000000000000001'], $ids($result['articles']));

        $assoc->setSort(new OrderClauseExpression('Articles._id', 'DESC'));
        $result = $authors->get('000000000000000000000001', ...['contain' => 'Articles']);
        $this->assertSame(['000000000000000000000003', '000000000000000000000001'], $ids($result['articles']));
    }

    /**
     * Tests requiresKeys() method
     */
    public function testRequiresKeys(): void
    {
        $assoc = new HasMany('Test', $this->author);
        // Default strategy is now subquery, which doesn't require keys
        $this->assertFalse($assoc->requiresKeys());

        $assoc->setStrategy(HasMany::STRATEGY_SELECT);
        $this->assertTrue($assoc->requiresKeys());

        $assoc->setStrategy(HasMany::STRATEGY_SUBQUERY);
        $this->assertFalse($assoc->requiresKeys());
    }

    /**
     * Tests that HasMany can't use the join strategy
     */
    public function testStrategyFailure(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid strategy `join` was provided');
        $assoc = new HasMany('Test', $this->author);
        $assoc->setStrategy(HasMany::STRATEGY_JOIN);
    }

    /**
     * Test the eager loader method with no extra options
     */
    public function testEagerLoader(): void
    {
        $config = [
            'target' => $this->article,
            'strategy' => 'select',
        ];
        $association = new HasMany('Articles', $this->author, $config);
        $query = $this->article->selectQuery();
        $this->article->shouldReceive('find')
            ->andReturn($query);
        $keys = [
            '000000000000000000000001', '000000000000000000000002',
            '000000000000000000000003', '000000000000000000000004',
        ];

        $callable = $association->eagerLoader(['keys' => $keys, 'query' => $query]);
        $row = ['_id' => '000000000000000000000001'];

        $result = $callable([$row]);
        $this->assertArrayHasKey('articles', $result[0]);
        $this->assertSame('000000000000000000000001', $result[0]['articles'][0]->author_id);
        $this->assertSame('000000000000000000000001', $result[0]['articles'][1]->author_id);

        $row = ['_id' => '000000000000000000000002'];
        $result = $callable([$row]);
        $this->assertSame([], $result[0]['articles']);

        $row = ['_id' => '000000000000000000000003'];
        $result = $callable([$row]);
        $this->assertArrayHasKey('articles', $result[0]);
        $this->assertSame('000000000000000000000003', $result[0]['articles'][0]->author_id);

        $row = ['_id' => '000000000000000000000004'];
        $result = $callable([$row]);
        $this->assertSame([], $result[0]['articles']);
    }

    /**
     * Test the eager loader method with default query clauses
     */
    public function testEagerLoaderWithDefaults(): void
    {
        $config = [
            'target' => $this->article,
            'conditions' => ['Articles.published' => 'Y'],
            'sort' => ['_id' => 'ASC'],
            'strategy' => 'select',
        ];
        $association = new HasMany('Articles', $this->author, $config);
        $keys = [
            '000000000000000000000001', '000000000000000000000002',
            '000000000000000000000003', '000000000000000000000004',
        ];

        $query = $this->article->selectQuery();
        $this->article->shouldReceive('find')
            ->andReturn($query);

        $callable = $association->eagerLoader(['keys' => $keys, 'query' => $query]);
        $callable([['_id' => '000000000000000000000001']]);

        $this->assertSame(['_id' => 1], $query->clause('order'));

        $where = $query->clause('where');
        $this->assertSame('Y', $where['published']);
        $this->assertSame(['000000000000000000000001'], array_map(strval(...), $where['author_id']['$in']));
    }

    /**
     * Test the eager loader method with overridden query clauses
     */
    public function testEagerLoaderWithOverrides(): void
    {
        $config = [
            'target' => $this->article,
            'conditions' => ['Articles.published' => 'Y'],
            'sort' => ['_id' => 'ASC'],
            'strategy' => 'select',
        ];
        $this->article->hasMany('Comments', ['strategy' => 'select']);

        $association = new HasMany('Articles', $this->author, $config);
        $keys = [
            '000000000000000000000001', '000000000000000000000002',
            '000000000000000000000003', '000000000000000000000004',
        ];

        /** @var \Cake\ORM\Query\SelectQuery $query */
        $query = $this->article->query();
        $query->addDefaultTypes($this->article);

        $this->article->shouldReceive('find')
            ->andReturn($query);

        $callable = $association->eagerLoader([
            'conditions' => ['Articles.id !=' => 3],
            'sort' => ['title' => 'DESC'],
            'fields' => ['_id', 'title', 'author_id'],
            'contain' => ['Comments' => ['fields' => ['comment', 'article_id']]],
            'keys' => $keys,
            'query' => $query,
        ]);
        $callable([['_id' => '000000000000000000000001']]);

        $this->assertSame(['_id' => 1, 'title' => 1, 'author_id' => 1], $query->clause('select'));
        $this->assertSame(['title' => -1], $query->clause('order'));
        $this->assertArrayHasKey('Comments', $query->getContain());

        $where = $query->clause('where');
        $this->assertSame('Y', $where['published']);
        $this->assertSame(['000000000000000000000001'], array_map(strval(...), $where['author_id']['$in']));
    }

    /**
     * Test that failing to add the foreignKey to the list of fields will throw an
     * exception
     */
    public function testEagerLoaderFieldsException(): void
    {
        // This test now verifies that missing foreign keys are automatically added
        // instead of throwing an exception
        $config = [
            'target' => $this->article,
            'strategy' => 'select',
        ];
        $association = new HasMany('Articles', $this->author, $config);
        $keys = [1, 2, 3, 4];

        // Create a query to be used as sourceQuery
        $sourceQuery = $this->article->selectQuery();

        // Setup the mock to track what happens
        $queriesReturned = [];
        $this->article->shouldReceive('find')
            ->once()
            ->with('all')
            ->andReturnUsing(function () use (&$queriesReturned) {
                // Preserve the current auto-quoting state (might be affected by other tests)
                $query = $this->article->selectQuery();
                $query->enableAutoFields(false);
                $queriesReturned[] = $query;

                return $query;
            });

        $loader = $association->eagerLoader([
            'fields' => ['_id', 'title'],
            'keys' => $keys,
            'query' => $sourceQuery,
        ]);

        // Verify that the loader was created successfully
        $this->assertIsCallable($loader);

        // Run the loader (ODM eager loaders are lazy) so `find()` fires.
        $loader([['_id' => '000000000000000000000001']]);

        // Verify that find was called and a query was returned
        $this->assertCount(1, $queriesReturned, 'Find should have been called once');

        // Check the query that was actually modified by the loader
        $fetchQuery = $queriesReturned[0];
        $select = $fetchQuery->clause('select');

        // The foreign key should be in the select clause
        // Handle both quoted and non-quoted identifiers
        $hasAuthorId = false;
        foreach ($select as $key => $field) {
            // Check if the field contains author_id (handles both quoted and non-quoted)
            if (
                str_contains((string)$field, 'author_id') ||
                str_contains((string)$key, 'author_id')
            ) {
                $hasAuthorId = true;
                break;
            }
        }

        $this->assertTrue(
            $hasAuthorId,
            'Foreign key author_id should be added. Select clause: ' . json_encode($select),
        );
    }

    /**
     * Tests that eager loader accepts a queryBuilder option.
     *
     * Cake asserted SQL `join('comments')` / `clause('join')`; ODM applies
     * the builder onto the association query (select + where). Nested
     * `contain()` is a separate loader contract — do not sneak it in here.
     */
    public function testEagerLoaderWithQueryBuilder(): void
    {
        $config = [
            'target' => $this->article,
            'strategy' => 'select',
        ];
        $association = new HasMany('Articles', $this->author, $config);
        $keys = [
            '000000000000000000000001', '000000000000000000000002',
            '000000000000000000000003', '000000000000000000000004',
        ];

        $query = $this->article->selectQuery();
        $this->article->shouldReceive('find')
            ->andReturn($query);

        $queryBuilder = fn($q) => $q
            ->select(['author_id', 'title'])
            ->where(['published' => 'Y']);
        $callable = $association->eagerLoader([
            'keys' => $keys,
            'query' => $query,
            'queryBuilder' => $queryBuilder,
        ]);
        $callable([['_id' => '000000000000000000000001']]);

        $this->assertSame(['author_id' => 1, 'title' => 1, '_id' => 0], $query->clause('select'));
        $where = $query->clause('where');
        $this->assertSame('Y', $where['published']);
        $this->assertSame(['000000000000000000000001'], array_map(strval(...), $where['author_id']['$in']));
    }

    /**
     * Test composite binding keys compile to a tuple `$or` filter and attach matches.
     */
    public function testEagerLoaderMultipleKeys(): void
    {
        $config = [
            'target' => $this->article,
            'strategy' => 'select',
            'foreignKey' => ['author_id', 'site_id'],
        ];

        $this->author->setPrimaryKey(['_id', 'site_id']);
        $association = new HasMany('Articles', $this->author, $config);
        $results = new ResultSet([
            new Document([
                '_id' => '000000000000000000000001',
                'title' => 'article 1',
                'author_id' => '000000000000000000000002',
                'site_id' => '000000000000000000000010',
            ]),
            new Document([
                '_id' => '000000000000000000000002',
                'title' => 'article 2',
                'author_id' => '000000000000000000000001',
                'site_id' => '000000000000000000000020',
            ]),
        ]);
        $query = new class ($this->article, $results) extends SelectQuery {
            public function __construct(
                BaseCollection $collection,
                protected ResultSet $resultSet,
            ) {
                parent::__construct($collection);
            }

            public function all(): ResultSetInterface
            {
                return $this->resultSet;
            }
        };
        $this->article->shouldReceive('find')
            ->with('all')
            ->andReturn($query);

        $callable = $association->eagerLoader(['query' => $query]);
        $authors = [
            ['_id' => '000000000000000000000002', 'site_id' => '000000000000000000000010'],
            ['_id' => '000000000000000000000001', 'site_id' => '000000000000000000000020'],
        ];
        $result = $callable($authors);

        $where = $query->clause('where');
        $this->assertEqualsCanonicalizing([
            ['author_id' => '000000000000000000000002', 'site_id' => '000000000000000000000010'],
            ['author_id' => '000000000000000000000001', 'site_id' => '000000000000000000000020'],
        ], $where['$or']);
        $this->assertCount(1, $result[0]['articles']);
        $this->assertSame('000000000000000000000001', $result[0]['articles'][0]->get('_id'));
        $this->assertCount(1, $result[1]['articles']);
        $this->assertSame('000000000000000000000002', $result[1]['articles'][0]->get('_id'));
    }

    /**
     * Test that not selecting join keys fails with an error
     */
    public function testEagerloaderNoForeignKeys(): void
    {
        $authors = $this->getCollectionLocator()->get('Authors');
        // Use select strategy explicitly to test that it throws when foreign key is missing
        $authors->Articles->setStrategy(Association::STRATEGY_SELECT);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unable to load `Articles` association. Ensure foreign key in `Authors`');
        $query = $authors->find()
            ->select(['Authors.name'])
            ->where(['Authors._id' => '000000000000000000000001'])
            ->contain('Articles');
        $query->first();
    }

    /**
     * Test cascading deletes.
     */
    public function testCascadeDelete(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $config = [
            'dependent' => true,
            'target' => $articles,
            'conditions' => ['Articles.published' => 'Y'],
        ];
        $association = new HasMany('Articles', $this->author, $config);

        $document = new Document(['_id' => '000000000000000000000001', 'name' => 'PHP']);
        $this->assertTrue($association->cascadeDelete($document));

        $published = $articles
            ->find('published')
            ->where([
                'published' => 'Y',
                'author_id' => '000000000000000000000001',
            ]);
        $this->assertCount(0, $published->all());
    }

    /**
     * Test cascading deletes with a finder
     */
    public function testCascadeDeleteFinder(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $config = [
            'dependent' => true,
            'target' => $articles,
            'finder' => 'published',
        ];
        // Exclude one record from the association finder
        $articles->updateAll(
            ['published' => 'N'],
            ['author_id' => '000000000000000000000001', 'title' => 'First Article'],
        );
        $association = new HasMany('Articles', $this->author, $config);

        $document = new Document(['_id' => '000000000000000000000001', 'name' => 'PHP']);
        $this->assertTrue($association->cascadeDelete($document));

        $published = $articles->find('published')->where(['author_id' => '000000000000000000000001']);
        $this->assertCount(0, $published->all(), 'Associated records should be removed');

        $all = $articles->find()->where(['author_id' => '000000000000000000000001']);
        $this->assertCount(1, $all->all(), 'Record not in association finder should remain');
    }

    /**
     * Test cascading delete with has many.
     */
    public function testCascadeDeleteCallbacks(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $config = [
            'dependent' => true,
            'target' => $articles,
            'conditions' => ['Articles.published' => 'Y'],
            'cascadeCallbacks' => true,
        ];
        $association = new HasMany('Articles', $this->author, $config);

        $author = new Document(['_id' => '000000000000000000000001', 'name' => 'mark']);
        $this->assertTrue($association->cascadeDelete($author));

        $query = $articles->find()->where(['author_id' => '000000000000000000000001']);
        $this->assertSame(0, $query->count(), 'Cleared related rows');

        $query = $articles->find()->where(['author_id' => '000000000000000000000003']);
        $this->assertSame(1, $query->count(), 'other records left behind');
    }

    /**
     * Test cascading delete with a rule preventing deletion
     */
    public function testCascadeDeleteCallbacksRuleFailure(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $config = [
            'dependent' => true,
            'target' => $articles,
            'cascadeCallbacks' => true,
        ];
        $association = new HasMany('Articles', $this->author, $config);
        $articles = $association->getTarget();
        $articles->getEventManager()->on('Collection.buildRules', function ($event, $rules): void {
            $rules->addDelete(fn(): false => false);
        });

        $author = new Document(['_id' => '000000000000000000000001', 'name' => 'mark']);
        $this->assertFalse($association->cascadeDelete($author));
        $matching = $articles->find()
            ->where(['Articles.author_id' => $author->getId()])
            ->all();
        $this->assertGreaterThan(0, count($matching));
    }

    /**
     * Test that saveAssociated() ignores non entity values.
     */
    public function testSaveAssociatedOnlyEntities(): void
    {
        $spy = Mockery::spy(BaseCollection::class);
        $config = [
            'target' => $spy,
        ];

        $document = new Document([
            'username' => 'Mark',
            'email' => 'mark@example.com',
            'articles' => [
                ['title' => 'First Post'],
                new Document(['title' => 'Second Post']),
            ],
        ]);

        $association = new HasMany('Articles', $this->author, $config);
        $result = $association->saveAssociated($document);
        $this->assertSame($result, $document);

        $spy->shouldNotHaveReceived('saveAssociated');
    }

    /**
     * Tests that property is being set using the constructor options.
     */
    public function testPropertyOption(): void
    {
        $config = ['propertyName' => 'thing_placeholder'];
        $association = new HasMany('Authors', $this->getCollectionLocator()->get('Authors'), $config);
        $this->assertSame('thing_placeholder', $association->getProperty());
    }

    /**
     * Tests propertyName is used during marshalling and validation
     */
    public function testPropertyOptionMarshalAndValidation(): void
    {
        $authors = $this->getCollectionLocator()->get('Authors');
        $authors->Articles->setProperty('blogs');
        $authors->getValidator()
            ->requirePresence('blogs', true, 'blogs must be set');

        $data = [
            'name' => 'corey',
        ];
        $author = $authors->newDocument($data);
        $this->assertEmpty($author->blogs, 'No blogs set');
        $this->assertTrue($author->hasErrors(), 'Should have validation errors');
        $this->assertArrayHasKey('blogs', $author->getErrors());
    }

    /**
     * Test that plugin names are omitted from property()
     */
    public function testPropertyNoPlugin(): void
    {
        $config = [
            'target' => $this->article,
        ];
        $association = new HasMany('Contacts.Addresses', $this->author, $config);
        $this->assertSame('addresses', $association->getProperty());
    }

    /**
     * Test that the ValueBinder is reset when using strategy = Association::STRATEGY_SUBQUERY
     */
    public function testValueBinderUpdateOnSubQueryStrategy(): void
    {
        $Authors = $this->getCollectionLocator()->get('Authors');
        $Authors->Articles->setStrategy(Association::STRATEGY_SUBQUERY);

        $query = $Authors->find();
        $authorsAndArticles = $query
            ->select([
                '_id',
                'slug' => $query->func()->concat([
                    '---',
                    'name' => 'identifier',
                ]),
            ])
            ->contain('Articles')
            ->where(['name' => 'mariano'])
            ->first();

        $this->assertCount(2, $authorsAndArticles->get('articles'));
    }

    /**
     * Tests using subquery strategy when parent query
     * that contains limit without order.
     */
    public function testSubqueryWithLimit(): void
    {
        $Authors = $this->getCollectionLocator()->get('Authors');
        $Authors->Articles->setStrategy(Association::STRATEGY_SUBQUERY);

        $query = $Authors->find();
        $result = $query
            ->contain('Articles')
            ->first();

        if (in_array($result->name, ['mariano', 'larry'])) {
            $this->assertNotEmpty($result->articles);
        } else {
            $this->assertEmpty($result->articles);
        }
    }

    /**
     * Tests using subquery strategy when parent query
     * that contains limit with order.
     */
    public function testSubqueryWithLimitAndOrder(): void
    {
        $Authors = $this->getCollectionLocator()->get('Authors');
        $Authors->Articles->setStrategy(Association::STRATEGY_SUBQUERY);

        $query = $Authors->find();
        $result = $query
            ->contain('Articles')
            ->orderBy(['name' => 'ASC'])
            ->limit(2)
            ->toArray();

        $this->assertCount(0, $result[0]->articles);
        $this->assertCount(1, $result[1]->articles);
    }

    /**
     * Subquery strategy when a HAVING-referenced select alias would collide
     * with the binding key column name. The collision must be avoided to
     * prevent "Duplicate column name" errors in the generated subquery.
     */
    public function testSubqueryWithHavingAliasCollidingWithBindingKey(): void
    {
        $Authors = $this->getCollectionLocator()->get('Authors');
        $Authors->Articles->setStrategy(Association::STRATEGY_SUBQUERY);

        // Alias 'id' collides with the binding key column name. The collision
        // guard must skip preserving the alias in the generated subquery to
        // avoid producing a duplicate 'id' column.
        $query = $Authors->find();
        $result = $query
            ->select([
                'Authors._id',
                '_id' => $query->func()->concat(['x'], ['string']),
                'cnt' => $query->func()->count($query->identifier('Authors._id')),
            ])
            ->contain('Articles')
            ->groupBy(['Authors._id'])
            ->having(['cnt >=' => 1], ['cnt' => 'integer'])
            ->toArray();

        $this->assertNotEmpty($result);
    }

    /**
     * Subquery strategy when the same alias is referenced by both ORDER BY
     * and HAVING. The HAVING branch must not re-add an alias already
     * preserved by the ORDER BY branch.
     */
    public function testSubqueryWithHavingAndOrderOnSameAlias(): void
    {
        $Authors = $this->getCollectionLocator()->get('Authors');
        $Authors->Articles->setStrategy(Association::STRATEGY_SUBQUERY);

        $query = $Authors->find();
        $result = $query
            ->select([
                'name_length' => $query->func()->length(['Authors.name' => 'identifier']),
            ])
            ->enableAutoFields()
            ->contain('Articles')
            ->groupBy(['Authors._id'])
            ->having(['name_length >' => 4], ['name_length' => 'integer'])
            ->orderBy(['name_length' => 'DESC'])
            ->toArray();

        $this->assertNotEmpty($result);
    }

    /**
     * Subquery strategy + HAVING on an aggregate alias.
     * The aggregate must be preserved in SELECT but skipped in GROUP BY.
     */
    public function testSubqueryWithHavingOnAggregateAlias(): void
    {
        $Authors = $this->getCollectionLocator()->get('Authors');
        $Authors->Articles->setStrategy(Association::STRATEGY_SUBQUERY);

        $query = $Authors->find();
        $result = $query
            ->select([
                'Authors._id',
                'Authors.name',
                'article_count' => $query->func()->count($query->identifier('Articles._id')),
            ])
            ->leftJoinWith('Articles')
            ->contain('Articles')
            ->groupBy(['Authors._id', 'Authors.name'])
            ->having(['article_count >' => 0], ['article_count' => 'integer'])
            ->toArray();

        $this->assertNotEmpty($result);
    }

    /**
     * Tests subquery strategy when the parent query uses HAVING on a SELECT alias.
     *
     * The alias must be preserved in the generated subquery SELECT, otherwise the
     * HAVING clause references a column that no longer exists.
     */
    public function testSubqueryWithHavingOnSelectAlias(): void
    {
        $Authors = $this->getCollectionLocator()->get('Authors');
        $Authors->Articles->setStrategy(Association::STRATEGY_SUBQUERY);

        $query = $Authors->find();
        $result = $query
            ->select([
                'name_length' => $query->func()->length(['Authors.name' => 'identifier']),
            ])
            ->enableAutoFields()
            ->contain('Articles')
            ->groupBy(['Authors._id'])
            ->having(['name_length >' => 5], ['name_length' => 'integer'])
            ->toArray();

        $names = array_map(fn(EntityInterface $author): string => $author->name, $result);
        sort($names);
        $this->assertSame(['garrett', 'mariano'], $names);
    }

    /**
     * Subquery strategy with a self-referential HasMany association.
     *
     * When source and target alias are the same (e.g. a tree structure
     * with parent_id), the subquery join alias must not collide with
     * the outer query's table alias, which would cause
     * "Column 'X._id' in SELECT is ambiguous" errors.
     */
    public function testSubqueryWithSelfReferentialAssociation(): void
    {
        $Categories = $this->getCollectionLocator()->get('Categories');
        $Categories->hasMany('ChildCategories', [
            'className' => 'Categories',
            'foreignKey' => 'parent_id',
            'strategy' => Association::STRATEGY_LOOKUP,
        ]);

        $Categories->ChildCategories->hasMany('ChildCategories', [
            'className' => 'Categories',
            'foreignKey' => 'parent_id',
            'strategy' => Association::STRATEGY_LOOKUP,
        ]);

        $result = $Categories->find()
            ->where(['Categories.parent_id' => null])
            ->contain('ChildCategories.ChildCategories')
            ->toArray();

        $this->assertNotEmpty($result);
        foreach ($result as $category) {
            $this->assertIsArray($category->child_categories);
        }

        $nestedPropertyLoaded = false;
        foreach ($result as $category) {
            foreach ($category->child_categories as $childCategory) {
                if (isset($childCategory->child_categories)) {
                    $this->assertIsArray($childCategory->child_categories);
                    $nestedPropertyLoaded = true;
                }

                if (!empty($childCategory->child_categories)) {
                    $nestedPropertyLoaded = true;
                }
            }
        }

        $this->assertTrue($nestedPropertyLoaded);
    }

    /**
     * Subquery strategy with a self-referential HasMany association whose source
     * alias already ends in `_subquery`.
     *
     * The generated alias for the derived table must not collide with the outer
     * table alias or SQLite will see ambiguous references.
     */
    public function testSubqueryWithSelfReferentialAssociationAliasAlreadyUsingSubquerySuffix(): void
    {
        $Categories = $this->getCollectionLocator()->get('Categories');
        $Categories->hasMany('Categories_subquery', [
            'className' => 'Categories',
            'foreignKey' => 'parent_id',
            'strategy' => Association::STRATEGY_SUBQUERY,
        ]);

        $Categories->Categories_subquery->hasMany('Categories_subquery', [
            'className' => 'Categories',
            'foreignKey' => 'parent_id',
            'strategy' => Association::STRATEGY_SUBQUERY,
        ]);

        $result = $Categories->find()
            ->where(['Categories.parent_id' => null])
            ->contain('Categories_subquery.Categories_subquery')
            ->toArray();

        $this->assertNotEmpty($result);
        foreach ($result as $category) {
            $this->assertIsArray($category->categories_subquery);
            foreach ($category->categories_subquery as $childCategory) {
                $this->assertTrue(isset($childCategory->categories_subquery));
                $this->assertIsArray($childCategory->categories_subquery);
            }
        }
    }

    /**
     * Assertion method for order by clause contents.
     *
     * @param array $expected The expected join clause.
     * @param \Cake\ORM\Query\SelectQuery $query The query to check.
     */
    protected function assertJoin($expected, $query): void
    {
        $this->assertEquals($expected, array_values($query->clause('join')));
    }

    /**
     * Assertion method for where clause contents.
     *
     * @param \Cake\Database\QueryExpression $expected The expected where clause.
     * @param \Cake\ORM\Query\SelectQuery $query The query to check.
     */
    protected function assertWhereClause($expected, $query): void
    {
        $this->assertEquals($expected, $query->clause('where'));
    }

    /**
     * Assertion method for order by clause contents.
     *
     * @param \Cake\Database\QueryExpression $expected The expected where clause.
     * @param \Cake\ORM\Query\SelectQuery $query The query to check.
     */
    protected function assertOrderClause($expected, $query): void
    {
        $this->assertEquals($expected, $query->clause('order'));
    }

    /**
     * Assertion method for select clause contents.
     *
     * @param array $expected Array of expected fields.
     * @param \Cake\ORM\Query\SelectQuery $query The query to check.
     */
    protected function assertSelectClause($expected, $query): void
    {
        $this->assertEquals($expected, $query->clause('select'));
    }

    /**
     * Tests that unlinking calls the right methods
     */
    public function testUnlinkSuccess(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $assoc = $this->author->Articles;

        $document = $this->author->get('000000000000000000000001', ...['contain' => 'Articles']);
        $initial = $document->articles;
        $this->assertCount(2, $initial);

        $assoc->unlink($document, $document->articles);
        $this->assertEmpty($document->get('articles'), 'Property should be empty');

        $new = $this->author->get('000000000000000000000002', ...['contain' => 'Articles']);
        $this->assertCount(0, $new->articles, 'DB should be clean');
        $this->assertSame(4, $this->author->find()->count(), 'Authors should still exist');
        $this->assertSame(3, $articles->find()->count(), 'Articles should still exist');
    }

    /**
     * Tests that unlink with an empty array does nothing
     */
    public function testUnlinkWithEmptyArray(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $assoc = $this->author->Articles;

        $document = $this->author->get('000000000000000000000001', ...['contain' => 'Articles']);
        $initial = $document->articles;
        $this->assertCount(2, $initial);

        $assoc->unlink($document, []);

        $new = $this->author->get('000000000000000000000001', ...['contain' => 'Articles']);
        $this->assertCount(2, $new->articles, 'Articles should remain linked');
        $this->assertSame(4, $this->author->find()->count(), 'Authors should still exist');
        $this->assertSame(3, $articles->find()->count(), 'Articles should still exist');
    }

    /**
     * Tests that link only uses a single database transaction
     */
    public function testLinkUsesSingleTransaction(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $assoc = $this->author->Articles;

        // Ensure author in fixture has zero associated articles
        $document = $this->author->get('000000000000000000000002', ...['contain' => 'Articles']);
        $initial = $document->articles;
        $this->assertCount(0, $initial);

        // Ensure that after each model is saved, we are still within a transaction.
        $listenerAfterSave = function ($e, $document, $options) use ($articles): void {
            $this->assertTrue(
                $articles->getConnection()->inTransaction(),
                'Multiple transactions used to save associated models.',
            );
        };
        $articles->getEventManager()->on('Collection.afterSave', $listenerAfterSave);

        $options = ['atomic' => false];
        $assoc->link($document, $articles->find('all')->toArray(), $options);

        // Ensure that link was successful.
        $new = $this->author->get('000000000000000000000002', ...['contain' => 'Articles']);
        $this->assertCount(3, $new->articles);
    }

    /**
     * Test that saveAssociated() fails on non-empty, non-iterable value
     */
    public function testSaveAssociatedNotEmptyNotIterable(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Could not save comments, it cannot be traversed');
        $articles = $this->getCollectionLocator()->get('Articles');
        $association = $articles->hasMany('Comments', [
            'saveStrategy' => HasMany::SAVE_APPEND,
        ]);

        $document = $articles->newEmptyDocument();
        $document->set('comments', 'oh noes');

        $association->saveAssociated($document);
    }

    /**
     * Data provider for empty values.
     *
     * @return array
     */
    public static function emptySetDataProvider(): array
    {
        return [
            [''],
            [false],
            [null],
            [[]],
        ];
    }

    /**
     * Test that saving empty sets with the `append` strategy does not
     * affect the associated records for not yet persisted parent entities.
     *
     * @param mixed $value Empty value.
     */
    #[DataProvider('emptySetDataProvider')]
    public function testSaveAssociatedEmptySetWithAppendStrategyDoesNotAffectAssociatedRecordsOnCreate(string|bool|array|null $value): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $association = $articles->hasMany('Comments', [
            'saveStrategy' => HasMany::SAVE_APPEND,
        ]);

        $comments = $association->find();
        $this->assertNotEmpty($comments);

        $document = $articles->newEmptyDocument();
        $document->set('comments', $value);

        $this->assertSame($document, $association->saveAssociated($document));
        $this->assertEquals($value, $document->get('comments'));
        $this->assertEquals($comments, $association->find());
    }

    /**
     * Test that saving empty sets with the `append` strategy does not
     * affect the associated records for already persisted parent entities.
     *
     * @param mixed $value Empty value.
     */
    #[DataProvider('emptySetDataProvider')]
    public function testSaveAssociatedEmptySetWithAppendStrategyDoesNotAffectAssociatedRecordsOnUpdate(string|bool|array|null $value): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $association = $articles->hasMany('Comments', [
            'saveStrategy' => HasMany::SAVE_APPEND,
        ]);

        $document = $articles->get('000000000000000000000001', ...[
            'contain' => ['Comments'],
        ]);
        $comments = $document->get('comments');
        $this->assertNotEmpty($comments);

        $document->set('comments', $value);
        $this->assertSame($document, $association->saveAssociated($document));
        $this->assertEquals($value, $document->get('comments'));

        $document = $articles->get('000000000000000000000001', ...[
            'contain' => ['Comments'],
        ]);
        $this->assertEquals($comments, $document->get('comments'));
    }

    /**
     * Test that saving empty sets with the `replace` strategy does not
     * affect the associated records for not yet persisted parent entities.
     *
     * @param mixed $value Empty value.
     */
    #[DataProvider('emptySetDataProvider')]
    public function testSaveAssociatedEmptySetWithReplaceStrategyDoesNotAffectAssociatedRecordsOnCreate(string|bool|array|null $value): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $association = $articles->hasMany('Comments', [
            'saveStrategy' => HasMany::SAVE_REPLACE,
        ]);

        $comments = $association->find();
        $this->assertNotEmpty($comments);

        $document = $articles->newEmptyDocument();
        $document->set('comments', $value);

        $this->assertSame($document, $association->saveAssociated($document));
        $this->assertEquals($value, $document->get('comments'));
        $this->assertEquals($comments, $association->find());
    }

    /**
     * Test that saving empty sets with the `replace` strategy does remove
     * the associated records for already persisted parent entities.
     *
     * @param mixed $value Empty value.
     */
    #[DataProvider('emptySetDataProvider')]
    public function testSaveAssociatedEmptySetWithReplaceStrategyRemovesAssociatedRecordsOnUpdate(string|bool|array|null $value): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $association = $articles->hasMany('Comments', [
            'saveStrategy' => HasMany::SAVE_REPLACE,
        ]);

        $document = $articles->get('000000000000000000000001', ...[
            'contain' => ['Comments'],
        ]);
        $comments = $document->get('comments');
        $this->assertNotEmpty($comments);

        $document->set('comments', $value);
        $this->assertSame($document, $association->saveAssociated($document));
        $this->assertEquals([], $document->get('comments'));

        $document = $articles->get('000000000000000000000001', ...[
            'contain' => ['Comments'],
        ]);
        $this->assertEmpty($document->get('comments'));
    }

    /**
     * Test that the associated entities are not saved when there's any rule
     * that fail on them and the errors are correctly set on the original entity.
     */
    public function testSaveAssociatedWithFailedRuleOnAssociated(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $articles->hasMany('Comments');

        $comments = $this->getCollectionLocator()->get('Comments');
        $comments->belongsTo('Users');

        $rules = $comments->rulesChecker();
        $rules->add($rules->existsIn('user_id', 'Users'));

        $article = $articles->newDocument([
            'title' => 'Bakeries are sky rocketing',
            'body' => 'All because of cake',
            'comments' => [
                [
                    'user_id' => '000000000000000000000001',
                    'comment' => 'That is true!',
                ],
                [
                    'user_id' => '000000000000000000000999', // This rule will fail because the user doesn't exist
                    'comment' => 'Of course',
                ],
            ],
        ], ['associated' => ['Comments']]);
        $this->assertFalse($article->hasErrors());
        $this->assertFalse($articles->save($article, ['associated' => ['Comments']]));
        $this->assertTrue($article->hasErrors());
        $this->assertFalse($article->comments[0]->hasErrors());
        $this->assertTrue($article->comments[1]->hasErrors());
        $this->assertNotEmpty($article->comments[1]->getErrors());
        $expected = [
            'user_id' => [
                'existsIn' => __('This value does not exist'),
            ],
        ];
        $this->assertEquals($expected, $article->comments[1]->getErrors());
    }

    /**
     * Tests that providing an invalid strategy throws an exception
     */
    public function testInvalidSaveStrategy(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $articles = $this->getCollectionLocator()->get('Articles');

        $association = $articles->hasMany('Comments');
        $association->setSaveStrategy('anotherThing');
    }

    /**
     * Tests saveStrategy
     */
    public function testSetSaveStrategy(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');

        $association = $articles->hasMany('Comments');
        $this->assertSame($association, $association->setSaveStrategy(HasMany::SAVE_REPLACE));
        $this->assertSame(HasMany::SAVE_REPLACE, $association->getSaveStrategy());
    }

    /**
     * Test that save works with replace saveStrategy and are not deleted once they are not null
     */
    public function testSaveReplaceSaveStrategy(): void
    {
        $authors = $this->getCollectionLocator()->get('Authors');
        $authors->Articles->setSaveStrategy(HasMany::SAVE_REPLACE);

        $document = $authors->newDocument([
            'name' => 'mylux',
            'articles' => [
                ['title' => 'One Random Post', 'body' => 'The cake is not a lie'],
                ['title' => 'Another Random Post', 'body' => 'The cake is nice'],
                ['title' => 'One more random post', 'body' => 'The cake is forever'],
            ],
        ], ['associated' => ['Articles']]);

        $document = $authors->save($document, ['associated' => ['Articles']]);

        $sizeArticles = count($document->articles);
        $this->assertSame($sizeArticles, $authors->Articles->find('all')->where(['author_id' => $document['_id']])->count());

        $articleId = $document->articles[0]->getId();
        unset($document->articles[0]);
        $document->setDirty('articles', true);

        $authors->save($document, ['associated' => ['Articles']]);

        $this->assertSame($sizeArticles - 1, $authors->Articles->find('all')->where(['author_id' => $document['_id']])->count());
        $this->assertTrue($authors->Articles->exists(['_id' => $articleId]));
    }

    /**
     * Test that save works with replace saveStrategy conditions
     */
    public function testSaveReplaceSaveStrategyClosureConditions(): void
    {
        $authors = $this->getCollectionLocator()->get('Authors');
        $authors->Articles
            ->setDependent(true)
            ->setSaveStrategy('replace')
            ->setConditions(fn(): array => ['published' => 'Y']);

        $document = $authors->newDocument([
            'name' => 'mylux',
            'articles' => [
                ['title' => 'Not matching conditions', 'body' => '', 'published' => 'N'],
                ['title' => 'Random Post', 'body' => 'The cake is nice', 'published' => 'Y'],
                ['title' => 'Another Random Post', 'body' => 'The cake is yummy', 'published' => 'Y'],
                ['title' => 'One more random post', 'body' => 'The cake is forever', 'published' => 'Y'],
            ],
        ], ['associated' => ['Articles']]);

        $document = $authors->save($document, ['associated' => ['Articles']]);

        $sizeArticles = count($document->articles);
        // Should be one fewer because of conditions.
        $this->assertSame($sizeArticles - 1, $authors->Articles->find('all')->where(['author_id' => $document['_id']])->count());

        $articleId = $document->articles[0]->getId();
        unset($document->articles[0], $document->articles[1]);
        $document->setDirty('articles', true);

        $authors->save($document, ['associated' => ['Articles']]);

        $this->assertSame($sizeArticles - 2, $authors->Articles->find('all')->where(['author_id' => $document['_id']])->count());

        // Should still exist because it doesn't match the association conditions.
        $articles = $this->getCollectionLocator()->get('Articles');
        $this->assertTrue($articles->exists(['_id' => $articleId]));
    }

    /**
     * Test that save works with replace saveStrategy, replacing the already persisted entities even if no new entities are passed
     */
    public function testSaveReplaceSaveStrategyNotAdding(): void
    {
        $authors = $this->getCollectionLocator()->get('Authors');
        $authors->Articles->setSaveStrategy('replace');

        $document = $authors->newDocument([
            'name' => 'mylux',
            'articles' => [
                ['title' => 'One Random Post', 'body' => 'The cake is not a lie'],
                ['title' => 'Another Random Post', 'body' => 'The cake is nice'],
                ['title' => 'One more random post', 'body' => 'The cake is forever'],
            ],
        ], ['associated' => ['Articles']]);

        $document = $authors->save($document, ['associated' => ['Articles']]);

        $sizeArticles = count($document->articles);
        $this->assertCount($sizeArticles, $authors->Articles->find('all')->where(['author_id' => $document['_id']]));

        $document->set('articles', []);

        $document = $authors->save($document, ['associated' => ['Articles']]);

        $this->assertCount(0, $authors->Articles->find('all')->where(['author_id' => $document['_id']]));
    }

    /**
     * Test that save works with append saveStrategy not deleting or setting null anything
     */
    public function testSaveAppendSaveStrategy(): void
    {
        $authors = $this->getCollectionLocator()->get('Authors');
        $authors->Articles->setSaveStrategy('append');

        $document = $authors->newDocument([
            'name' => 'mylux',
            'articles' => [
                ['title' => 'One Random Post', 'body' => 'The cake is not a lie'],
                ['title' => 'Another Random Post', 'body' => 'The cake is nice'],
                ['title' => 'One more random post', 'body' => 'The cake is forever'],
            ],
        ], ['associated' => ['Articles']]);

        $document = $authors->save($document, ['associated' => ['Articles']]);

        $sizeArticles = count($document->articles);

        $this->assertSame($sizeArticles, $authors->Articles->find('all')->where(['author_id' => $document['_id']])->count());

        $articleId = $document->articles[0]->getId();
        unset($document->articles[0]);
        $document->setDirty('articles', true);

        $authors->save($document, ['associated' => ['Articles']]);

        $this->assertSame($sizeArticles, $authors->Articles->find('all')->where(['author_id' => $document['_id']])->count());
        $this->assertTrue($authors->Articles->exists(['_id' => $articleId]));
    }

    /**
     * Test that save has append as the default save strategy
     */
    public function testSaveDefaultSaveStrategy(): void
    {
        $authors = $this->getCollectionLocator()->get('Authors');
        $authors->Articles->setSaveStrategy(HasMany::SAVE_APPEND);
        $this->assertSame(HasMany::SAVE_APPEND, $authors->getAssociation('Articles')->getSaveStrategy());
    }

    /**
     * Test that the associated entities are unlinked and deleted when they are dependent
     */
    public function testSaveReplaceSaveStrategyDependent(): void
    {
        $authors = $this->getCollectionLocator()->get('Authors');
        $authors->Articles->setSaveStrategy(HasMany::SAVE_REPLACE)
            ->setDependent(true);

        $document = $authors->newDocument([
            'name' => 'mylux',
            'articles' => [
                ['title' => 'One Random Post', 'body' => 'The cake is not a lie'],
                ['title' => 'Another Random Post', 'body' => 'The cake is nice'],
                ['title' => 'One more random post', 'body' => 'The cake is forever'],
            ],
        ], ['associated' => ['Articles']]);

        $document = $authors->save($document, ['associated' => ['Articles']]);

        $sizeArticles = count($document->articles);
        $this->assertSame($sizeArticles, $authors->Articles->find('all')->where(['author_id' => $document['_id']])->count());

        $articleId = $document->articles[0]->getId();
        unset($document->articles[0]);
        $document->setDirty('articles', true);

        $authors->save($document, ['associated' => ['Articles']]);

        $this->assertSame($sizeArticles - 1, $authors->Articles->find('all')->where(['author_id' => $document['_id']])->count());
        $this->assertFalse($authors->Articles->exists(['_id' => $articleId]));
    }

    /**
     * Test that the associated entities are unlinked and deleted when they are dependent
     * when associated entities array is indexed by string keys
     */
    public function testSaveReplaceSaveStrategyDependentWithStringKeys(): void
    {
        $authors = $this->getCollectionLocator()->get('Authors');
        $authors->Articles->setSaveStrategy(HasMany::SAVE_REPLACE)
            ->setDependent(true);

        $document = $authors->newDocument([
            'name' => 'mylux',
            'articles' => [
                ['title' => 'One Random Post', 'body' => 'The cake is not a lie'],
                ['title' => 'Another Random Post', 'body' => 'The cake is nice'],
                ['title' => 'One more random post', 'body' => 'The cake is forever'],
            ],
        ], ['associated' => ['Articles']]);

        $document = $authors->saveOrFail($document, ['associated' => ['Articles']]);

        $sizeArticles = count($document->articles);
        $this->assertSame($sizeArticles, $authors->Articles->find('all')->where(['author_id' => $document['_id']])->count());

        $articleId = $document->articles[0]->getId();
        $document->articles = [
            'one' => $document->articles[1],
            'two' => $document->articles[2],
        ];

        $authors->saveOrFail($document, ['associated' => ['Articles']]);

        $this->assertSame($sizeArticles - 1, $authors->Articles->find('all')->where(['author_id' => $document['_id']])->count());
        $this->assertFalse($authors->Articles->exists(['_id' => $articleId]));
    }

    /**
     * Test that the associated entities are unlinked and deleted when they are dependent
     *
     * In the future this should change and apply the finder.
     */
    public function testSaveReplaceSaveStrategyDependentWithConditions(): void
    {
        $this->getCollectionLocator()->clear();
        $this->setAppNamespace('TestApp');

        $authors = $this->getCollectionLocator()->get('Authors');
        $authors->Articles->setSaveStrategy(HasMany::SAVE_REPLACE)
            ->setDependent(true)
            ->setFinder('published');
        $articles = $authors->Articles->getTarget();

        // Remove an article from the association finder scope
        $articles->updateAll(['published' => 'N'], ['author_id' => '000000000000000000000001', 'title' => 'Third Article']);

        $document = $authors->get('000000000000000000000001', ...['contain' => ['Articles']]);
        $data = [
            'name' => 'updated',
            'articles' => [
                ['title' => 'New First', 'body' => 'New First', 'published' => 'Y'],
            ],
        ];
        $document = $authors->patchDocument($document, $data, ['associated' => ['Articles']]);
        $document = $authors->save($document, ['associated' => ['Articles']]);

        // Should only have one article left as we 'replaced' the others.
        $this->assertCount(1, $document->articles);

        // No additional records in db.
        $this->assertCount(
            1,
            $authors->Articles->find()->where(['author_id' => '000000000000000000000001'])->toArray(),
        );

        $others = $articles->find('all')
            ->where(['Articles.author_id' => '000000000000000000000001', 'published' => 'N'])
            ->orderByAsc('title')
            ->toArray();
        $this->assertCount(
            1,
            $others,
            'Record not matching association condition should stay',
        );
        $this->assertSame('Third Article', $others[0]->title);
    }

    /**
     * Test that the associated entities are unlinked and deleted when they have a not nullable foreign key
     */
    public function testSaveReplaceSaveStrategyNotNullable(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $articles->hasMany('Comments', ['saveStrategy' => HasMany::SAVE_REPLACE]);

        $article = $articles->newDocument([
            'title' => 'Bakeries are sky rocketing',
            'body' => 'All because of cake',
            'comments' => [
                [
                    'user_id' => '000000000000000000000001',
                    'comment' => 'That is true!',
                ],
                [
                    'user_id' => '000000000000000000000002',
                    'comment' => 'Of course',
                ],
            ],
        ], ['associated' => ['Comments']]);

        $article = $articles->save($article, ['associated' => ['Comments']]);

        $commentId = $article->comments[0]->getId();
        $sizeComments = count($article->comments);

        $this->assertSame($sizeComments, $articles->Comments->find('all')->where(['article_id' => $article->getId()])->count());
        $this->assertTrue($articles->Comments->exists(['_id' => $commentId]));

        unset($article->comments[0]);
        $article->setDirty('comments', true);
        $article = $articles->save($article, ['associated' => ['Comments']]);

        $this->assertSame($sizeComments - 1, $articles->Comments->find('all')->where(['article_id' => $article->getId()])->count());
        $this->assertFalse($articles->Comments->exists(['_id' => $commentId]));
    }

    /**
     * Test that the associated entities are unlinked and deleted when they have a not nullable foreign key
     */
    public function testSaveReplaceSaveStrategyAdding(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $articles->hasMany('Comments', ['saveStrategy' => HasMany::SAVE_REPLACE]);

        $article = $articles->newDocument([
            'title' => 'Bakeries are sky rocketing',
            'body' => 'All because of cake',
            'comments' => [
                [
                    'user_id' => '000000000000000000000001',
                    'comment' => 'That is true!',
                ],
                [
                    'user_id' => '000000000000000000000002',
                    'comment' => 'Of course',
                ],
            ],
        ], ['associated' => ['Comments']]);

        $article = $articles->save($article, ['associated' => ['Comments']]);

        $commentId = $article->comments[0]->getId();
        $sizeComments = count($article->comments);
        $articleId = $article->getId();

        $this->assertSame($sizeComments, $articles->Comments->find('all')->where(['article_id' => $article->getId()])->count());
        $this->assertTrue($articles->Comments->exists(['_id' => $commentId]));

        unset($article->comments[0]);
        $article->comments[] = $articles->Comments->newDocument([
            'user_id' => '000000000000000000000001',
            'comment' => 'new comment',
        ]);

        $article->setDirty('comments', true);
        $article = $articles->save($article, ['associated' => ['Comments']]);

        $this->assertSame($sizeComments, $articles->Comments->find('all')->where(['article_id' => $article->getId()])->count());
        $this->assertFalse($articles->Comments->exists(['_id' => $commentId]));
        $this->assertTrue($articles->Comments->exists(['comment' => 'new comment', 'article_id' => $articleId]));
    }

    /**
     * Tests that dependent, non-cascading deletes are using the association
     * conditions for deleting associated records.
     */
    public function testHasManyNonCascadingUnlinkDeleteUsesAssociationConditions(): void
    {
        $Articles = $this->getCollectionLocator()->get('Articles');
        $Comments = $Articles->hasMany('Comments', [
            'dependent' => true,
            'cascadeCallbacks' => false,
            'saveStrategy' => HasMany::SAVE_REPLACE,
            'conditions' => [
                'Comments.published' => 'Y',
            ],
        ]);

        $article = $Articles->newDocument([
            'title' => 'Title',
            'body' => 'Body',
            'comments' => [
                [
                    'user_id' => '000000000000000000000001',
                    'comment' => 'First comment',
                    'published' => 'Y',
                ],
                [
                    'user_id' => '000000000000000000000001',
                    'comment' => 'Second comment',
                    'published' => 'Y',
                ],
            ],
        ]);
        $article = $Articles->save($article);
        $this->assertNotEmpty($article);

        $comment3 = $Comments->getTarget()->newDocument([
            'article_id' => $article->get('_id'),
            'user_id' => '000000000000000000000001',
            'comment' => 'Third comment',
            'published' => 'N',
        ]);
        $comment3 = $Comments->getTarget()->save($comment3);
        $this->assertNotEmpty($comment3);

        $this->assertSame(3, $Comments->getTarget()->find()->where(['Comments.article_id' => $article->get('_id')])->count());

        unset($article->comments[1]);
        $article->setDirty('comments', true);

        $article = $Articles->save($article);
        $this->assertNotEmpty($article);

        // Given the association condition of `'Comments.published' => 'Y'`,
        // it is expected that only one of the three linked comments are
        // actually being deleted, as only one of them matches the
        // association condition.
        $this->assertSame(2, $Comments->getTarget()->find()->where(['Comments.article_id' => $article->get('_id')])->count());
    }

    /**
     * Tests that non-dependent, non-cascading deletes are using the association
     * conditions for updating associated records.
     */
    public function testHasManyNonDependentNonCascadingUnlinkUpdateUsesAssociationConditions(): void
    {
        $Authors = $this->getCollectionLocator()->get('Authors');
        $Authors->associations()->removeAll();
        $Articles = $Authors->hasMany('Articles', [
            'dependent' => false,
            'cascadeCallbacks' => false,
            'saveStrategy' => HasMany::SAVE_REPLACE,
            'conditions' => [
                'Articles.published' => 'Y',
            ],
        ]);

        $author = $Authors->newDocument([
            'name' => 'Name',
            'articles' => [
                [
                    'title' => 'First article',
                    'body' => 'First article',
                    'published' => 'Y',
                ],
                [
                    'title' => 'Second article',
                    'body' => 'Second article',
                    'published' => 'Y',
                ],
            ],
        ]);
        $author = $Authors->save($author);
        $this->assertNotEmpty($author);

        $article3 = $Articles->getTarget()->newDocument([
            'author_id' => $author->get('_id'),
            'title' => 'Third article',
            'body' => 'Third article',
            'published' => 'N',
        ]);
        $article3 = $Articles->getTarget()->save($article3);
        $this->assertNotEmpty($article3);

        $this->assertSame(3, $Articles->getTarget()->find()->where(['Articles.author_id' => $author->get('_id')])->count());

        $article2 = $author->articles[1];
        unset($author->articles[1]);
        $author->setDirty('articles', true);

        $author = $Authors->save($author);
        $this->assertNotEmpty($author);

        // Given the association condition of `'Articles.published' => 'Y'`,
        // it is expected that only one of the three linked articles are
        // actually being unlinked (nulled), as only one of them matches the
        // association condition.
        $this->assertSame(2, $Articles->getTarget()->find()->where(['Articles.author_id' => $author->get('_id')])->count());
        $this->assertNull($Articles->get($article2->get('_id'))->get('author_id'));
        $this->assertEquals($author->get('_id'), $Articles->get($article3->get('_id'))->get('author_id'));
    }

    public function testEagerLoaderConnectionRole(): void
    {
        $connection = ConnectionManager::get('test_mongo');
        $articles = $this->getCollectionLocator()->get('Articles')->setConnection($connection);
        $articles->hasMany('UniqueItems')->setStrategy('select');

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

        $target = $articles->getAssociation('UniqueItems')->getTarget();
        $child = $target->find();
        $loader = new SelectLoader([
            'finder' => fn() => $child,
            'foreignKey' => 'article_id',
            'bindingKey' => '_id',
            'associationType' => 'oneToMany',
            'nestKey' => 'unique_items',
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

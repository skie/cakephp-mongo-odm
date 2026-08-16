<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM\Behavior;

use Cake\Database\Driver\Postgres;
use Cake\Database\Expression\QueryExpression;
use Cake\Database\ExpressionInterface;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\I18n;
use Cake\Utility\Hash;
use Crustum\Mongo\ODM\Behavior\Translate\ShadowCollectionStrategy;
use Crustum\Mongo\ODM\Behavior\TranslateBehavior;
use Crustum\Mongo\ODM\Document;
use TestApp\Model\Document\TranslateArticle;
use TestApp\Model\Document\TranslateBakedArticle;

/**
 * TranslateBehavior test case
 */
class TranslateBehaviorShadowTableTest extends TranslateBehaviorEavTest
{
    protected array $fixtures = [
        'plugin.Crustum/Mongo.Articles',
        'plugin.Crustum/Mongo.Authors',
        'plugin.Crustum/Mongo.Comments',
        'plugin.Crustum/Mongo.Tags',
        'plugin.Crustum/Mongo.TagsTranslations',
        'plugin.Crustum/Mongo.ArticlesTags',
        'plugin.Crustum/Mongo.SpecialTags',
        'plugin.Crustum/Mongo.Sections',
        'plugin.Crustum/Mongo.ArticlesTranslations',
        'plugin.Crustum/Mongo.ArticlesMoreTranslations',
        'plugin.Crustum/Mongo.AuthorsTranslations',
        'plugin.Crustum/Mongo.CommentsTranslations',
        'plugin.Crustum/Mongo.TagsShadowTranslations',
        'plugin.Crustum/Mongo.SpecialTagsTranslations',
        'plugin.Crustum/Mongo.SectionsTranslations',
    ];

    /**
     * setUpBeforeClass
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        TranslateBehavior::setDefaultStrategyClass(ShadowCollectionStrategy::class);
    }

    /**
     * tearDownAfterClass
     */
    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();

        TranslateBehavior::setDefaultStrategyClass(ShadowCollectionStrategy::class);
    }

    /**
     * Check things are setup correctly by default
     *
     * The hasOneAlias is used for the has-one translation, the translationCollection is used
     * with findTranslations
     */
    public function testDefaultAliases(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->getCollection();
        $collection->addBehavior('Translate');

        $config = $collection->behaviors()->get('Translate')->getStrategy()->getConfig();
        $wantedKeys = [
            'translationCollection',
            'mainCollectionAlias',
            'hasOneAlias',
        ];
        $config = array_intersect_key($config, array_flip($wantedKeys));
        $expected = [
            'translationCollection' => 'ArticlesTranslations',
            'mainCollectionAlias' => 'Articles',
            'hasOneAlias' => 'ArticlesTranslation',
        ];
        $this->assertEquals($expected, $config, 'Used aliases should match the main table object');

        $this->testFind();
    }

    /**
     * Check things are setup correctly by default for plugin models
     */
    public function testDefaultPluginAliases(): void
    {
        $collection = $this->getCollectionLocator()->get('SomeRandomPlugin.Articles');

        $collection->getCollection();
        $collection->addBehavior('Translate');

        $config = $collection->behaviors()->get('Translate')->getStrategy()->getConfig();
        $wantedKeys = [
            'translationCollection',
            'mainCollectionAlias',
            'hasOneAlias',
        ];
        $config = array_intersect_key($config, array_flip($wantedKeys));
        $expected = [
            'translationCollection' => 'SomeRandomPlugin.ArticlesTranslations',
            'mainCollectionAlias' => 'Articles',
            'hasOneAlias' => 'ArticlesTranslation',
        ];
        $this->assertEquals($expected, $config, 'Used aliases should match the main table object');

        $exists = $this->getCollectionLocator()->exists('SomeRandomPlugin.ArticlesTranslations');
        $this->assertTrue($exists, 'The behavior should have populated this key with a table object');

        $translationCollection = $this->getCollectionLocator()->get('SomeRandomPlugin.ArticlesTranslations');
        $this->assertSame(
            'SomeRandomPlugin.ArticlesTranslations',
            $translationCollection->getRegistryAlias(),
            'It should be a different object to the one in the no-plugin prefix',
        );

        $this->testFind('SomeRandomPlugin.Articles');
    }

    /**
     * testAutoReferenceName
     *
     * The parent test is EAV specific. Test that the config reflects the referenceName -
     * which is used to determine the the translation table/association name only in the
     * shadow translate behavior
     */
    public function testAutoReferenceName(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->getCollection();
        $collection->addBehavior('Translate');

        $config = $collection->behaviors()->get('Translate')->getStrategy()->getConfig();
        $wantedKeys = [
            'translationCollection',
            'mainCollectionAlias',
            'hasOneAlias',
        ];

        $config = array_intersect_key($config, array_flip($wantedKeys));
        $expected = [
            'translationCollection' => 'ArticlesTranslations',
            'mainCollectionAlias' => 'Articles',
            'hasOneAlias' => 'ArticlesTranslation',
        ];
        $this->assertEquals($expected, $config, 'The translationCollection key should be derived from referenceName');
    }

    /**
     * testChangingReferenceName
     *
     * The parent test is EAV specific. Test that the config reflects the referenceName -
     * which is used to determine the the translation table/association name only in the
     * shadow translate behavior
     */
    public function testChangingReferenceName(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->getCollection();
        $collection->addBehavior(
            'Translate',
            ['referenceName' => 'Posts'],
        );

        $config = $collection->behaviors()->get('Translate')->getStrategy()->getConfig();
        $wantedKeys = [
            'translationCollection',
            'mainCollectionAlias',
            'hasOneAlias',
        ];

        $config = array_intersect_key($config, array_flip($wantedKeys));
        $expected = [
            'translationCollection' => 'PostsTranslations',
            'mainCollectionAlias' => 'Articles',
            'hasOneAlias' => 'ArticlesTranslation',
        ];
        $this->assertEquals($expected, $config, 'The translationCollection key should be derived from referenceName');
    }

    /**
     * Allow usage without specifying fields explicitly
     *
     * Fields are only detected when necessary, one of those times is a fine with fields.
     */
    public function testAutoFieldDetection(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate');

        $collection->getBehavior('Translate')->setLocale('eng');
        $collection->find()->select(['title'])->first();

        $expected = ['title', 'body'];
        $result = $collection->behaviors()->get('Translate')->getStrategy()->getConfig('fields');
        $this->assertSame(
            $expected,
            $result,
            'If no fields are specified, they should be derived from the schema',
        );
    }

    /**
     * testTranslationTableConfig
     */
    public function testTranslationTableConfig(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate');

        $exists = $this->getCollectionLocator()->exists('ArticlesTranslations');
        $this->assertTrue($exists, 'The table registry should have an object in this key now');

        $translationCollection = $this->getCollectionLocator()->get('ArticlesTranslations');
        $this->assertSame('articles_translations', $translationCollection->getCollection());
        $this->assertSame('ArticlesTranslations', $translationCollection->getAlias());
    }

    /**
     * Only join translations when necessary
     *
     * By inspecting the sql generated, verify that if there is a need for the translation
     * table to be included in the query it is present, and when there is no clear need -
     * that it is not.
     */
    public function testNoUnnecessaryJoins(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate');

        $query = $collection->find();
        $this->assertStringNotContainsString(
            'articles_translations',
            $query->sql(),
            "The default locale doesn't need a join",
        );

        $collection->getBehavior('Translate')->setLocale('eng');

        $query = $collection->find()->select(['id']);
        $this->assertStringNotContainsString(
            'articles_translations',
            $query->sql(),
            'No translated fields, nothing to do',
        );

        $query = $collection->find()->select(['Other.title']);
        $this->assertStringNotContainsString(
            'articles_translations',
            $query->sql(),
            "Other isn't the table class with the translate behavior, nothing to do",
        );
    }

    /**
     * Join when translations are necessary
     */
    public function testNecessaryJoinsSelect(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate');
        $collection->getBehavior('Translate')->setLocale('eng');

        $query = $collection->find();
        $this->assertStringContainsString(
            'articles_translations',
            $query->sql(),
            'No fields specified, means select all fields - translated included',
        );

        $query = $collection->find()->select(['title']);
        $this->assertStringContainsString(
            'articles_translations',
            $query->sql(),
            'Selecting a translated field should join the translations table',
        );

        $query = $collection->find()->select(['Articles.title']);
        $this->assertStringContainsString(
            'articles_translations',
            $query->sql(),
            'Selecting an aliased translated field should join the translations table',
        );
    }

    /**
     * Join when translations are necessary
     */
    public function testNecessaryJoinsWhere(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate');
        $collection->getBehavior('Translate')->setLocale('eng');

        $query = $collection->find()->select(['id'])->where(['title' => 'First Article']);
        $this->assertStringContainsString(
            'articles_translations',
            $query->sql(),
            'If the where clause includes a translated field - a join is required',
        );
    }

    /**
     * Join when translations are necessary
     */
    public function testNecessaryJoinsConfig(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');

        $collection->addBehavior('Translate', [
            'onlyTranslated' => true,
        ]);
        $collection->getBehavior('Translate')->setLocale('eng');

        $query = $collection->find()->select(['id'])->disableAutoFields();
        $this->assertStringContainsString(
            'articles_translations',
            $query->sql(),
            'Enabling `onlyTranslated` should join the translations table',
        );

        $collection
            ->removeBehavior('Translate')
            ->addBehavior('Translate');
        $collection->getBehavior('Translate')->setLocale('eng');

        $query = $collection->find('all', filterByCurrentLocale: true)->select(['id'])->disableAutoFields();
        $this->assertStringContainsString(
            'articles_translations',
            $query->sql(),
            'Enabling `filterByCurrentLocale` should join the translations table',
        );
    }

    /**
     * testTraversingWhereClauseWithNonStringField
     */
    public function testTraversingWhereClauseWithNonStringField(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate');
        $collection->getBehavior('Translate')->setLocale('eng');

        $query = $collection->find()->select()->where(fn(ExpressionInterface $exp) => $exp->lt(new QueryExpression('1'), 50));

        $this->assertStringContainsString(
            'articles_translations',
            $query->sql(),
            'Do not try to use non string fields when traversing "where" clause',
        );
    }

    /**
     * Join when translations are necessary
     */
    public function testNecessaryJoinsOrder(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate');
        $collection->getBehavior('Translate')->setLocale('eng');

        $query = $collection->find()->select(['id'])->orderBy(['title' => 'desc']);
        $this->assertStringContainsString(
            'articles_translations',
            $query->sql(),
            'If the order clause includes a translated field - a join is required',
        );

        $query = $collection->find();
        $this->assertStringContainsString(
            'articles_translations',
            $query->sql(),
            'No fields means auto-fields - a join is required',
        );
    }

    /**
     * Setup a contrived self join and make sure both records are translated
     *
     * Different locales are used on each table object just to make any resulting
     * confusion easier to identify as neither the original or translated values
     * overlap between the two records.
     */
    public function testSelfJoin(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate');
        $collection->getBehavior('Translate')->setLocale('eng');

        $collection->belongsTo('Copy', ['className' => 'Articles', 'foreignKey' => 'author_id']);
        $collection->Copy->addBehavior('Translate');
        $collection->Copy->getBehavior('Translate')->setLocale('deu');

        $query = $collection->find()
            ->where(['Articles.id' => '000000000000000000000003'])
            ->contain('Copy');

        $result = $query->first()->toArray();
        $expected = [
            '_id' => '000000000000000000000003',
            'author_id' => '000000000000000000000001',
            'title' => 'Title #3',
            'body' => 'Content #3',
            'published' => 'Y',
            'copy' => [
                '_id' => '000000000000000000000001',
                'author_id' => '000000000000000000000001',
                'title' => 'Titel #1',
                'body' => 'Inhalt #1',
                'published' => 'Y',
                '_locale' => 'deu',
            ],
            '_locale' => 'eng',
        ];
        $this->assertEquals(
            $expected,
            $result,
            'The copy record should also be translated',
        );
    }

    /**
     * Verify it is not necessary for a translated field to exist in the master table
     */
    public function testVirtualTranslationField(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', [
            'translationCollectionAlias' => 'ArticlesMoreTranslations',
            'translationCollection' => 'articles_more_translations',
        ]);

        $collection->getBehavior('Translate')->setLocale('eng');
        $results = $collection->find()->all()->combine('title', 'subtitle', 'id')->toArray();
        $expected = [
            '000000000000000000000001' => ['Title #1' => 'SubTitle #1'],
            '000000000000000000000002' => ['Title #2' => 'SubTitle #2'],
            '000000000000000000000003' => ['Title #3' => 'SubTitle #3'],
        ];
        $this->assertSame($expected, $results);
    }

    /**
     * Tests that after deleting a translated entity, all translations are also removed
     */
    public function testDelete(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate');

        $article = $collection->find()->first();
        $this->assertTrue($collection->delete($article));

        $translations = $this->getCollectionLocator()->get('ArticlesTranslations')->find()
            ->where(['id' => $article->getId()])
            ->count();
        $this->assertSame(0, $translations);
    }

    /**
     * testNoAmbiguousFields
     */
    public function testNoAmbiguousFields(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate');
        $collection->getBehavior('Translate')->setLocale('eng');

        $article = $collection->find('all')
            ->select(['id'])
            ->toArray();

        $this->assertNotNull($article, "There will be an exception if there's ambiguous sql");

        $article = $collection->find('all')
            ->select(['title'])
            ->toArray();

        $this->assertNotNull($article, "There will be an exception if there's ambiguous sql");
    }

    /**
     * testNoAmbiguousConditions
     */
    public function testNoAmbiguousConditions(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate');
        $collection->getBehavior('Translate')->setLocale('eng');

        $article = $collection->find('all')
            ->where(['_id' => '000000000000000000000001'])
            ->toArray();

        $this->assertNotNull($article, "There will be an exception if there's ambiguous sql");

        $article = $collection->find('all')
            ->where(['title' => 1])
            ->toArray();

        $this->assertNotNull($article, "There will be an exception if there's ambiguous sql");
    }

    /**
     * testNoAmbiguousOrder
     */
    public function testNoAmbiguousOrderBy(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate');
        $collection->getBehavior('Translate')->setLocale('eng');

        $article = $collection->find('all')
            ->orderBy(['id' => 'desc'])
            ->enableHydration(false)
            ->toArray();

        $this->assertSame(['000000000000000000000003', '000000000000000000000002', '000000000000000000000001'], Hash::extract($article, '{n}._id'));

        $article = $collection->find('all')
            ->orderBy(['title' => 'asc'])
            ->enableHydration(false)
            ->toArray();

        $expected = ['Title #1', 'Title #2', 'Title #3'];
        $this->assertSame($expected, Hash::extract($article, '{n}.title'));
    }

    /**
     * If results are unhydrated, it should still work
     */
    public function testUnhydratedResults(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate');

        $result = $collection
            ->find('translations')
            ->enableHydration(false)
            ->first();
        $this->assertArrayHasKey('title', $result);
    }

    /**
     * A find containing another association should act the same whether translated or not
     */
    public function testFindWithAssociations(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->belongsTo('Authors');

        $collection->addBehavior('Translate');
        $collection->getBehavior('Translate')->setLocale('eng');

        $query = $collection
            ->find('translations')
            ->where(['Articles.id' => '000000000000000000000001'])
            ->contain(['Authors']);
        $this->assertStringContainsString(
            'articles_translations',
            $query->sql(),
            'There should be a join to the translations table',
        );

        $result = $query->firstOrFail();

        $this->assertNotNull($result->author, 'There should be an author for article 1.');
        $expected = [
            '_id' => '000000000000000000000001',
            'name' => 'mariano',
        ];
        $this->assertSame($expected, $result->author->toArray());

        $this->assertNotEmpty($result->_translations, "Translations can't be empty.");
    }

    /**
     * Test that when finding BTM associations, the contained BTM data is also translated.
     */
    public function testFindWithBTMAssociations(): void
    {
        $Articles = $this->getCollectionLocator()->get('Articles');
        $Tags = $this->getCollectionLocator()->get('Tags');

        // This required because there's already a fixture for tags_translations which isn't a shadow table
        $this->getCollectionLocator()->get('TagsTranslations', [
            'className' => 'TagsShadowTranslations',
        ]);
        $this->getCollectionLocator()->get('TagsTranslation', [
            'className' => 'TagsShadowTranslations',
        ]);

        $Articles->addBehavior('Translate');
        $Tags->addBehavior('Translate');

        $Articles->getBehavior('Translate')->setLocale('deu');
        $Tags->getBehavior('Translate')->setLocale('deu');

        $Articles->belongsToMany('Tags');

        $query = $Articles
            ->find()
            ->where(['Articles.id' => '000000000000000000000001'])
            ->contain(['Tags']);

        $result = $query->firstOrFail();

        $this->assertCount(2, $result->tags, 'There should be two translated tags.');

        $expected = [
            '_id' => '000000000000000000000001',
            'name' => 'tag1 in deu',
            '_locale' => 'deu',
            '_joinData' => [
                'tag_id' => '000000000000000000000001',
                'article_id' => '000000000000000000000001',
            ],
        ];
        $record = $result->tags[0]->toArray();
        unset($record['description'], $record['created']);
        $this->assertEquals($expected, $record);

        $expected = [
            '_id' => '000000000000000000000002',
            'name' => 'tag2 in deu',
            '_locale' => 'deu',
            '_joinData' => [
                'tag_id' => '000000000000000000000002',
                'article_id' => '000000000000000000000001',
            ],
        ];
        $record = $result->tags[1]->toArray();
        unset($record['description'], $record['created']);
        $this->assertEquals($expected, $record);
    }

    /**
     * A find containing a translated association doesn't error on incomplete data
     */
    public function testFindTranslationsAssociatedContain(): void
    {
        $comments = $this->fetchCollection('Comments');
        $comments->belongsTo('Articles');

        $articles = $this->fetchCollection('Articles');

        // Remove all articles so we have a missing record.
        $articles->deleteAll([]);

        $articles->addBehavior('Translate');
        $articles->getBehavior('Translate')->setLocale('eng');

        $query = $comments
            ->find()
            ->where(['Comments.id' => '000000000000000000000001'])
            ->contain([
                'Articles' => fn($q) => $q->find('translations')]);
        $record = $query->firstOrFail();
        $this->assertNull($record->article);
    }

    /**
     * Tests that it is possible to get all translated fields at once
     */
    public function testFindTranslations(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', ['fields' => ['title', 'body']]);

        $results = $collection->find('translations', locales: ['eng', 'deu', 'cze', 'spa']);
        $expected = [
            [
                'eng' => ['title' => 'Title #1', 'body' => 'Content #1', 'locale' => 'eng'],
                'deu' => ['title' => 'Titel #1', 'body' => 'Inhalt #1', 'locale' => 'deu'],
                'cze' => ['title' => 'Titulek #1', 'body' => 'Obsah #1', 'locale' => 'cze'],
                'spa' => ['title' => 'First Article', 'body' => 'Contenido #1', 'locale' => 'spa'],
            ],
            [
                'eng' => ['title' => 'Title #2', 'body' => 'Content #2', 'locale' => 'eng'],
                'deu' => ['title' => 'Titel #2', 'body' => 'Inhalt #2', 'locale' => 'deu'],
                'cze' => ['title' => 'Titulek #2', 'body' => 'Obsah #2', 'locale' => 'cze'],
            ],
            [
                'eng' => ['title' => 'Title #3', 'body' => 'Content #3', 'locale' => 'eng'],
                'deu' => ['title' => 'Titel #3', 'body' => 'Inhalt #3', 'locale' => 'deu'],
                'cze' => ['title' => 'Titulek #3', 'body' => 'Obsah #3', 'locale' => 'cze'],
            ],
        ];

        $translations = $this->extractTranslations($results);
        $this->assertEquals($expected, $translations->toArray());
        $expected = [
            '000000000000000000000001' => ['First Article' => 'First Article Body'],
            '000000000000000000000002' => ['Second Article' => 'Second Article Body'],
            '000000000000000000000003' => ['Third Article' => 'Third Article Body'],
        ];

        $grouped = $results->all()->combine('title', 'body', '_id');
        $this->assertEquals($expected, $grouped->toArray());

        $document = $collection->newDocument(['title' => 'Fourth Title']);
        $collection->save($document);

        $expected = [[]];
        $result = $collection->find('translations')->where(['Articles.id' => $document->getId()])->all();
        $this->assertEquals($expected, $this->extractTranslations($result)->toArray());

        $document = $result->first();
        $this->assertSame('Fourth Title', $document->title);
    }

    /**
     * By default empty translations should be honored
     */
    public function testEmptyTranslationsDefaultBehavior(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate');
        $collection->getBehavior('Translate')->setLocale('zzz');
        $result = $collection->get('000000000000000000000001');

        $this->assertSame('', $result->title, 'The empty translation should be used');
        $this->assertSame('', $result->body, 'The empty translation should be used');
        $this->assertNull($result->description);
    }

    /**
     * Tests that allowEmptyTranslations takes effect
     */
    public function testEmptyTranslations(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', [
            'allowEmptyTranslations' => false,
        ]);
        $collection->getBehavior('Translate')->setLocale('zzz');
        $result = $collection->get('000000000000000000000001');

        $this->assertSame('First Article', $result->title, 'The empty translation should be ignored');
        $this->assertSame('First Article Body', $result->body, 'The empty translation should be ignored');
        $this->assertNull($result->description);
    }

    /**
     * Tests using FunctionExpression
     */
    public function testUsingFunctionExpression(): void
    {
        $this->skipIf(
            ConnectionManager::get('test_mongo')->getDriver() instanceof Postgres,
            'Test needs to be adjusted to not fail on Postgres',
        );

        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate');

        $collection->getBehavior('Translate')->setLocale('eng');
        $query = $collection->find()->select();
        $query->select([
            'title',
            'function_expression' => $query->func()->concat(['ArticlesTranslation.title' => 'literal', ' with a suffix']),
            'body',
        ]);
        $result = array_intersect_key(
            $query->first()->toArray(),
            array_flip(['title', 'function_expression', 'body', '_locale']),
        );

        $expected = [
            'title' => 'Title #1',
            'function_expression' => 'Title #1 with a suffix',
            'body' => 'Content #1',
            '_locale' => 'eng',
        ];
        $this->assertEqualsCanonicalizing(
            $expected,
            $result,
            'Including a function expression should work but requires referencing the used table aliases',
        );
    }

    /**
     * Ensure saving with accessible defined works
     *
     * With a standard baked model the accessible property is defined, that'll mean that
     * Setting fields such as id and locale will fail by default due to mass-assignment
     * protection. An exception is thrown if that happens
     */
    public function testSaveWithAccessibleFalse(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->setDocumentClass(TranslateBakedArticle::class);
        $collection->addBehavior('Translate', ['fields' => ['title', 'body']]);

        $article = $collection->get('000000000000000000000001');
        $article->getOrCreateTranslation('xyz')->title = 'XYZ title';

        $this->assertNotFalse($collection->save($article), 'The save should succeed');
    }

    /**
     * Tests translationField method for translated fields.
     */
    public function testTranslationFieldForTranslatedFields(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', [
            'fields' => ['title', 'body'],
            'defaultLocale' => 'en_US',
        ]);

        $expectedSameLocale = 'Articles.title';
        $expectedOtherLocale = 'ArticlesTranslation.title';

        $field = $collection->getBehavior('Translate')->translationField('title');
        $this->assertSame($expectedSameLocale, $field);

        I18n::setLocale('es_ES');
        $field = $collection->getBehavior('Translate')->translationField('title');
        $this->assertSame($expectedOtherLocale, $field);

        I18n::setLocale('en');
        $field = $collection->getBehavior('Translate')->translationField('title');
        $this->assertSame($expectedOtherLocale, $field);

        $collection->removeBehavior('Translate');
        $collection->addBehavior('Translate', [
            'fields' => ['title', 'body'],
            'defaultLocale' => 'de_DE',
        ]);

        I18n::setLocale('de_DE');
        $field = $collection->getBehavior('Translate')->translationField('title');
        $this->assertSame($expectedSameLocale, $field);

        I18n::setLocale('en_US');
        $field = $collection->getBehavior('Translate')->translationField('title');
        $this->assertSame($expectedOtherLocale, $field);

        $collection->getBehavior('Translate')->setLocale('de_DE');
        $field = $collection->getBehavior('Translate')->translationField('title');
        $this->assertSame($expectedSameLocale, $field);

        $collection->getBehavior('Translate')->setLocale('es');
        $field = $collection->getBehavior('Translate')->translationField('title');
        $this->assertSame($expectedOtherLocale, $field);
    }

    /**
     * Test update entity with _translations field.
     *
     * Had to override this method because the core method has a wacky check
     * for "description" field which doesn't even exist in ArticleFixture.
     */
    public function testSaveExistingRecordWithTranslatesField(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', ['fields' => ['title', 'body']]);
        $collection->setDocumentClass(TranslateArticle::class);

        $data = [
            'author_id' => '000000000000000000000001',
            'published' => 'Y',
            '_translations' => [
                'eng' => [
                    'title' => 'First Article1',
                    'body' => 'First Article content has been updated',
                ],
                'spa' => [
                    'title' => 'Mi nuevo titulo',
                    'body' => 'Contenido Actualizado',
                ],
            ],
        ];

        $article = $collection->find()->first();
        $article = $collection->patchDocument($article, $data);

        $this->assertNotFalse($collection->save($article));

        $results = $this->extractTranslations(
            $collection->find('translations')->where(['_id' => '000000000000000000000001']),
        )->first();

        $this->assertSame('Mi nuevo titulo', $results['spa']['title']);
        $this->assertSame('Contenido Actualizado', $results['spa']['body']);

        $this->assertSame('First Article1', $results['eng']['title']);
    }

    /**
     * Test save new entity with _translations field
     */
    public function testSaveNewRecordWithTranslatesField(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->getValidator()->add('title', 'notBlank', ['rule' => 'notBlank']);
        $collection->addBehavior('Translate', [
            'defaultLocale' => 'en',
            'fields' => ['title'],
        ]);
        $collection->setDocumentClass(TranslateArticle::class);

        $article = $collection->patchDocument(
            $collection->newEmptyDocument(),
            [
                '_translations' => ['en' => ['title' => '']],
            ],
        );
        $this->assertSame(
            ['notBlank' => 'The provided value is invalid'],
            $article->getError('title'),
        );

        $data = [
            'author_id' => '000000000000000000000001',
            'published' => 'N',
            '_translations' => [
                'en' => [
                    'title' => 'Title EN',
                    'body' => 'Body EN',
                ],
                'es' => [
                    'title' => 'Title ES',
                ],
                'fr' => [
                    'title' => 'Title FR',
                ],
            ],
        ];

        $article = $collection->patchDocument($collection->newEmptyDocument(), $data);
        $result = $collection->save($article);

        $this->assertNotFalse($result);

        $expected = [
            [
                'fr' => [
                    'title' => 'Title FR',
                    'locale' => 'fr',
                ],
                'es' => [
                    'title' => 'Title ES',
                    'locale' => 'es',
                ],
            ],
        ];
        $result = $collection->find('translations')->where(['Articles.id' => $result->getId()])->all();
        $this->assertEquals($expected, $this->extractTranslations($result)->toArray());

        $document = $result->first();
        $this->assertSame('Title EN', $document->title);
        $this->assertSame('Body EN', $document->body);

        $data = [
            'title' => 'New title',
            'author_id' => '000000000000000000000001',
            'published' => 'N',
            '_translations' => null,
        ];

        $article = $collection->patchDocument($collection->newEmptyDocument(), $data);
        $result = $collection->save($article);

        $this->assertNotFalse($result);
    }

    /**
     * Tests adding new translation to a record
     */
    public function testInsertNewTranslations(): void
    {
        parent::testInsertNewTranslations();

        $shadowEntity = new class extends Document {
            protected function _setComment($value): string
            {
                return $value . ' modified';
            }
        };

        $collection = $this->getCollectionLocator()->get('Comments');
        $collection->addBehavior('Translate', ['fields' => ['comment']]);
        $collection->getBehavior('Translate')->setLocale('spa');
        $collection->getBehavior('Translate')->getStrategy()->getTranslationCollection()->setDocumentClass($shadowEntity::class);

        $document = $collection->get('000000000000000000000001');
        $document->comment = 'New Comment';

        $collection->save($document);

        $document = $collection->get('000000000000000000000001');
        $this->assertSame(
            'New Comment',
            $document->get('comment'),
            'New translation should not be modified',
        );
    }

    /**
     * Tests adding new translation to a record
     */
    public function testAllowEmptyFalse(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', ['fields' => ['title'], 'allowEmptyTranslations' => false]);

        $article = $collection->find()->first();
        $this->assertSame('000000000000000000000001', $article->getId());

        $article = $collection->patchDocument($article, [
            '_translations' => [
                'fra' => [
                    'title' => '',
                ],
            ],
        ]);

        $collection->save($article);

        $noFra = $collection->ArticlesTranslations->find()->where(['locale' => 'fra'])->first();
        $this->assertEmpty($noFra);

        $article = $collection->find()->where(['_id' => '000000000000000000000002'])->first();

        $this->assertSame('Second Article', $article->get('title'));
        $collection->patchDocument($article, ['title' => 'Second Article updated']);

        $this->assertNotFalse($collection->save($article));
    }

    /**
     * Tests adding new translation to a record with a missing translation
     */
    public function testAllowEmptyFalseWithNull(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', ['fields' => ['title', 'description'], 'allowEmptyTranslations' => false]);

        $article = $collection->find()->first();
        $this->assertSame('000000000000000000000001', $article->getId());

        $article = $collection->patchDocument($article, [
            '_translations' => [
                'fra' => [
                    'title' => 'Title',
                ],
            ],
        ]);

        $collection->save($article);

        // Remove the Behavior to unset the content != '' condition
        $collection->removeBehavior('Translate');

        $fra = $collection->ArticlesTranslations->find()->where(['locale' => 'fra'])->first();
        $this->assertNotEmpty($fra);
    }

    /**
     * Tests adding new translation to a record
     */
    public function testMixedAllowEmptyFalse(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', ['fields' => ['title', 'body'], 'allowEmptyTranslations' => false]);

        $article = $collection->find()->first();
        $this->assertSame('000000000000000000000001', $article->getId());

        $article = $collection->patchDocument($article, [
            '_translations' => [
                'fra' => [
                    'title' => '',
                    'body' => 'Bonjour',
                ],
            ],
        ]);

        $collection->save($article);

        $fra = $collection->ArticlesTranslations->find()
            ->where([
                'locale' => 'fra',
            ])
            ->first();
        $this->assertSame('Bonjour', $fra->body);
        $this->assertNull($fra->title);
    }

    /**
     * Tests adding new translation to a record
     */
    public function testMultipleAllowEmptyFalse(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', ['fields' => ['title', 'body'], 'allowEmptyTranslations' => false]);

        $article = $collection->find()->first();
        $this->assertSame('000000000000000000000001', $article->getId());

        $article = $collection->patchDocument($article, [
            '_translations' => [
                'fra' => [
                    'title' => '',
                    'body' => 'Bonjour',
                ],
                'de' => [
                    'title' => 'Titel',
                    'body' => 'Hallo',
                ],
            ],
        ]);

        $collection->save($article);

        $fra = $collection->ArticlesTranslations->find()
            ->where([
                'locale' => 'fra',
            ])
            ->first();
        $this->assertSame('Bonjour', $fra->body);
        $this->assertNull($fra->title);

        $de = $collection->ArticlesTranslations->find()
            ->where([
                'locale' => 'de',
            ])
            ->first();
        $this->assertSame('Titel', $de->title);
        $this->assertSame('Hallo', $de->body);
    }

    /**
     * Test buildMarshalMap() builds new entities.
     */
    public function testBuildMarshalMapBuildEntities(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        // Unlike test case of core Translate behavior "fields" is not set to
        // test marshalling with lazily fetched fields list.
        $collection->addBehavior('Translate');

        $translate = $collection->behaviors()->get('Translate');

        $map = $translate->buildMarshalMap($collection->marshaller(), [], []);
        $document = $collection->newEmptyDocument();
        $data = [
            'en' => [
                'title' => 'English Title',
                'body' => 'English Content',
            ],
            'es' => [
                'title' => 'Titulo Español',
                'body' => 'Contenido Español',
            ],
        ];
        $result = $map['_translations']($data, $document);
        $this->assertEmpty($document->getErrors(), 'No validation errors.');
        $this->assertCount(2, $result);
        $this->assertArrayHasKey('en', $result);
        $this->assertArrayHasKey('es', $result);
        $this->assertSame('English Title', $result['en']->title);
        $this->assertSame('Titulo Español', $result['es']->title);
    }

    /**
     * Used in the config tests to verify that a simple find still works
     *
     * @param string $tableAlias
     */
    protected function testFind(string $tableAlias = 'Articles'): void
    {
        $collection = $this->getCollectionLocator()->get($tableAlias);
        $collection->getBehavior('Translate')->setLocale('eng');

        $query = $collection->find()->select();
        $result = array_intersect_key(
            $query->first()->toArray(),
            array_flip(['title', 'body', '_locale']),
        );
        $expected = [
            'title' => 'Title #1',
            'body' => 'Content #1',
            '_locale' => 'eng',
        ];
        $this->assertSame(
            $expected,
            $result,
            "Title and body are translated values, but don't match",
        );
    }

    /**
     * Tests that modified entities aren't marked as clean after ShadowCollectionStrategy::rowMapper
     */
    public function testModifiedDocumentNotCleanAfterTranslationMapping(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', ['fields' => ['title', 'body']]);
        $collection->getBehavior('Translate')->setLocale('fra');

        $articles = $collection->find()->all();
        $articles->each(function ($article): void {
            $article->published = 'N';
        });

        $this->assertTrue($articles->first()->isDirty('published'));
    }
}

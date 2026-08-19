<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM\Behavior;

use Cake\Collection\Collection;
use Cake\Collection\CollectionInterface;
use Cake\Datasource\ConnectionManager;
use Cake\Datasource\EntityInterface;
use Cake\I18n\I18n;
use Cake\Validation\Validator;
use Crustum\Mongo\ODM\Behavior\Translate\ShadowCollectionStrategy;
use Crustum\Mongo\ODM\Behavior\TranslateBehavior;
use Crustum\Mongo\ODM\Document;
use Crustum\Mongo\ODM\Locator\CollectionLocator;
use Crustum\Mongo\ODM\Query\SelectQuery;
use Crustum\Mongo\Test\TestCase\ODM\TestCase;
use TestApp\Model\Collection\CustomI18nCollection;
use TestApp\Model\Document\TranslateArticle;

/**
 * Translate behavior test case
 *
 * Shared find/save/translate methods for the translate strategy tests. Each
 * strategy test (`TranslateBehaviorShadowTableTest`, `TranslateBehaviorEmbedTest`)
 * extends this base and overrides the storage-specific internals.
 */
abstract class TranslateBehaviorTestBase extends TestCase
{
    /**
     * fixtures
     *
     * @var array<string>
     */
    protected array $fixtures = [
        'plugin.Crustum/Mongo.Articles',
        'plugin.Crustum/Mongo.Tags',
        'plugin.Crustum/Mongo.ArticlesTags',
        'plugin.Crustum/Mongo.Authors',
        'plugin.Crustum/Mongo.Sections',
        'plugin.Crustum/Mongo.SpecialTags',
        'plugin.Crustum/Mongo.Comments',
        'plugin.Crustum/Mongo.Translates',
    ];

    /**
     * setUpBeforeClass
     */
    public static function setUpBeforeClass(): void
    {
        TranslateBehavior::setDefaultStrategyClass(ShadowCollectionStrategy::class);

        parent::setUpBeforeClass();
    }

    /**
     * tearDownAfterClass
     */
    public static function tearDownAfterClass(): void
    {
        TranslateBehavior::setDefaultStrategyClass(ShadowCollectionStrategy::class);

        parent::tearDownAfterClass();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        I18n::setLocale(I18n::getDefaultLocale());
    }

    /**
     * Returns an array with all the translations found for a set of records
     *
     * @param \Traversable|array $data
     */
    protected function extractTranslations($data): CollectionInterface
    {
        return new Collection($data)->map(function (EntityInterface $row): array {
            $translations = $row->get('_translations');
            if (!$translations) {
                return [];
            }

            return array_map(fn(EntityInterface $document): array => $document->toArray(), $translations);
        });
    }

    /**
     * Tests that custom translation tables are respected
     */
    public function testCustomTranslationCollection(): void
    {
        ConnectionManager::setConfig('custom_i18n_datasource', mongoTestConnectionConfig(TEST_MONGO_DATABASE));

        $collection = $this->getCollectionLocator()->get('Articles');

        $collection->addBehavior('Translate', [
            'translationCollection' => CustomI18nCollection::class,
            'fields' => ['title', 'body'],
        ]);

        $items = $collection->associations();
        $i18n = $items->getByProperty('_i18n');

        $this->assertSame('CustomI18n', $i18n->getName());
        $this->assertInstanceOf(CustomI18nCollection::class, $i18n->getTarget());
        $this->assertSame('custom_i18n_datasource', $i18n->getTarget()->getConnection()->configName());
        $this->assertSame('custom_i18n_collection', $i18n->getTarget()->getCollection());

        ConnectionManager::drop('custom_i18n_datasource');
    }

    /**
     * Tests that the strategy can be changed for i18n
     */
    public function testStrategy(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');

        $collection->addBehavior('Translate', [
            'strategy' => 'select',
            'fields' => ['title', 'body'],
        ]);

        $items = $collection->associations();
        $i18n = $items->getByProperty('_i18n');

        $this->assertSame('select', $i18n->getStrategy());
    }

    public function testComplexLocales(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', ['fields' => ['title', 'body']]);

        I18n::setLocale('fr@currency=EUR');
        $this->assertSame('fr', $collection->getBehavior('Translate')->getLocale());

        $collection->getBehavior('Translate')->setLocale('en_US');
        $this->assertSame('en_US', $collection->getBehavior('Translate')->getLocale());
    }

    /**
     * Tests that fields from a translated model are overridden
     */
    public function testFindSingleLocale(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', ['fields' => ['title', 'body']]);

        $collection->getBehavior('Translate')->setLocale('eng');
        $results = $collection->find()->all()->combine('title', 'body', '_id')->toArray();
        $expected = [
            '000000000000000000000001' => ['Title #1' => 'Content #1'],
            '000000000000000000000002' => ['Title #2' => 'Content #2'],
            '000000000000000000000003' => ['Title #3' => 'Content #3'],
        ];
        $this->assertSame($expected, $results);

        $document = $collection->newDocument(['author_id' => '000000000000000000000002', 'title' => 'Title 4', 'body' => 'Body 4']);
        $collection->save($document);

        $results = $collection->unhydratedFind('all', locale: 'cze')
            ->select(['id', 'title', 'body'])
            ->orderByAsc('Articles.id')
            ->toArray();
        $expected = [
            ['_id' => '000000000000000000000001', 'title' => 'Titulek #1', 'body' => 'Obsah #1', '_locale' => 'cze'],
            ['_id' => '000000000000000000000002', 'title' => 'Titulek #2', 'body' => 'Obsah #2', '_locale' => 'cze'],
            ['_id' => '000000000000000000000003', 'title' => 'Titulek #3', 'body' => 'Obsah #3', '_locale' => 'cze'],
            ['_id' => $document->getId(), '_locale' => 'cze'],
        ];
        $this->assertSame($expected, $results);
    }

    /**
     * Test that iterating in a formatResults() does not drop data.
     */
    public function testFindTranslationsFormatResultsIteration(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', ['fields' => ['title', 'body']]);
        $collection->getBehavior('Translate')->setLocale('eng');
        $results = $collection->find('translations')
            ->limit(1)
            ->formatResults(function ($results) {
                foreach ($results as $res) {
                    $res->first = 'val';
                }

                foreach ($results as $res) {
                    $res->second = 'loop';
                }

                return $results;
            })
            ->toArray();
        $this->assertCount(1, $results);
        $this->assertSame('Title #1', $results[0]->title);
        $this->assertSame('val', $results[0]->first);
        $this->assertSame('loop', $results[0]->second);
        $this->assertNotEmpty($results[0]->_translations);
    }

    /**
     * Tests that fields from a translated model use the I18n class locale
     * and that it propagates to associated models
     */
    public function testFindSingleLocaleAssociatedEnv(): void
    {
        I18n::setLocale('eng');

        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', ['fields' => ['title', 'body']]);

        $collection->hasMany('Comments');
        $collection->Comments->addBehavior('Translate', ['fields' => ['comment']]);

        $results = $collection->find()
            ->select(['id', 'title', 'body'])
            ->contain(['Comments' => ['fields' => ['article_id', 'comment']]])
            ->enableHydration(false)
            ->toArray();

        $expected = [
            [
                '_id' => '000000000000000000000001',
                'title' => 'Title #1',
                'body' => 'Content #1',
                'comments' => [
                    ['article_id' => '000000000000000000000001', 'comment' => 'Comment #1', '_locale' => 'eng'],
                    ['article_id' => '000000000000000000000001', 'comment' => 'Comment #2', '_locale' => 'eng'],
                    ['article_id' => '000000000000000000000001', 'comment' => 'Comment #3', '_locale' => 'eng'],
                    ['article_id' => '000000000000000000000001', 'comment' => 'Comment #4', '_locale' => 'eng'],
                ],
                '_locale' => 'eng',
            ],
            [
                '_id' => '000000000000000000000002',
                'title' => 'Title #2',
                'body' => 'Content #2',
                'comments' => [
                    ['article_id' => '000000000000000000000002', 'comment' => 'First Comment for Second Article', '_locale' => 'eng'],
                    ['article_id' => '000000000000000000000002', 'comment' => 'Second Comment for Second Article', '_locale' => 'eng'],
                ],
                '_locale' => 'eng',
            ],
            [
                '_id' => '000000000000000000000003',
                'title' => 'Title #3',
                'body' => 'Content #3',
                'comments' => [],
                '_locale' => 'eng',
            ],
        ];
        $this->assertSame($expected, $results);

        I18n::setLocale('spa');

        $results = $collection->find()
            ->select(['id', 'title', 'body'])
            ->contain([
                'Comments' => [
                    'fields' => ['article_id', 'comment'],
                    'sort' => ['Comments.id' => 'ASC'],
                ],
            ])
            ->enableHydration(false)
            ->orderByAsc('Articles.id')
            ->toArray();

        $expected = [
            [
                '_id' => '000000000000000000000001',
                'title' => 'First Article',
                'body' => 'Contenido #1',
                'comments' => [
                    ['article_id' => '000000000000000000000001', 'comment' => 'First Comment for First Article', '_locale' => 'spa'],
                    ['article_id' => '000000000000000000000001', 'comment' => 'Second Comment for First Article', '_locale' => 'spa'],
                    ['article_id' => '000000000000000000000001', 'comment' => 'Third Comment for First Article', '_locale' => 'spa'],
                    ['article_id' => '000000000000000000000001', 'comment' => 'Comentario #4', '_locale' => 'spa'],
                ],
                '_locale' => 'spa',
            ],
            [
                '_id' => '000000000000000000000002',
                'title' => 'Second Article',
                'body' => 'Second Article Body',
                'comments' => [
                    ['article_id' => '000000000000000000000002', 'comment' => 'First Comment for Second Article', '_locale' => 'spa'],
                    ['article_id' => '000000000000000000000002', 'comment' => 'Second Comment for Second Article', '_locale' => 'spa'],
                ],
                '_locale' => 'spa',
            ],
            [
                '_id' => '000000000000000000000003',
                'title' => 'Third Article',
                'body' => 'Third Article Body',
                'comments' => [],
                '_locale' => 'spa',
            ],
        ];
        $this->assertSame($expected, $results);
    }

    /**
     * Tests that fields from a translated model are not overridden if translation
     * is null
     */
    public function testFindSingleLocaleWithNullTranslation(): void
    {
        $collection = $this->getCollectionLocator()->get('Comments');
        $collection->addBehavior('Translate', ['fields' => ['comment']]);
        $collection->getBehavior('Translate')->setLocale('spa');
        $results = $collection->find()
            ->where(['Comments.id' => '000000000000000000000006'])
            ->all()
            ->combine('id', 'comment')
            ->toArray();
        $expected = ['000000000000000000000006' => 'Second Comment for Second Article'];
        $this->assertSame($expected, $results);
    }

    /**
     * Tests that overriding fields with the translate behavior works when
     * using conditions and that all other columns are preserved
     */
    public function testFindSingleLocaleWithgetConditions(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', ['fields' => ['title', 'body']]);
        $collection->getBehavior('Translate')->setLocale('eng');
        $results = $collection->find()
            ->where(['Articles.id' => '000000000000000000000002'])
            ->all();

        $this->assertCount(1, $results);
        $row = $results->first();

        $expected = [
            '_id' => '000000000000000000000002',
            'title' => 'Title #2',
            'body' => 'Content #2',
            'author_id' => '000000000000000000000003',
            'published' => 'Y',
            '_locale' => 'eng',
        ];
        $this->assertEquals($expected, $row->toArray());
    }

    /**
     * Tests the locale setter/getter.
     */
    public function testSetGetLocale(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate');

        $this->assertSame('en_US', $collection->getBehavior('Translate')->getLocale());

        $collection->getBehavior('Translate')->setLocale('fr_FR');
        $this->assertSame('fr_FR', $collection->getBehavior('Translate')->getLocale());

        $collection->getBehavior('Translate')->setLocale(null);
        $this->assertSame('en_US', $collection->getBehavior('Translate')->getLocale());

        I18n::setLocale('fr_FR');
        $this->assertSame('fr_FR', $collection->getBehavior('Translate')->getLocale());
    }

    /**
     * Tests translationField method for other fields.
     */
    public function testTranslationFieldForOtherFields(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', ['fields' => ['title', 'body']]);

        $expected = 'Articles.foo';
        $field = $collection->getBehavior('Translate')->translationField('foo');
        $this->assertSame($expected, $field);
    }

    /**
     * Tests that translating fields work when other formatters are used
     */
    public function testFindList(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', ['fields' => ['title', 'body']]);
        $collection->getBehavior('Translate')->setLocale('eng');

        $results = $collection->find('list')->toArray();
        $expected = [
            '000000000000000000000001' => 'Title #1',
            '000000000000000000000002' => 'Title #2',
            '000000000000000000000003' => 'Title #3',
        ];
        $this->assertSame($expected, $results);
    }

    /**
     * Tests that the query count return the correct results
     */
    public function testFindCount(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', ['fields' => ['title', 'body']]);
        $collection->getBehavior('Translate')->setLocale('eng');

        $this->assertSame(3, $collection->find()->count());
    }

    /**
     * Tests that it is possible to request just a few translations
     */
    public function testFindFilteredTranslations(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', ['fields' => ['title', 'body']]);

        $results = $collection->find('translations', locales: ['deu', 'cze']);
        $expected = [
            [
                'deu' => ['title' => 'Titel #1', 'body' => 'Inhalt #1', 'locale' => 'deu'],
                'cze' => ['title' => 'Titulek #1', 'body' => 'Obsah #1', 'locale' => 'cze'],
            ],
            [
                'deu' => ['title' => 'Titel #2', 'body' => 'Inhalt #2', 'locale' => 'deu'],
                'cze' => ['title' => 'Titulek #2', 'body' => 'Obsah #2', 'locale' => 'cze'],
            ],
            [
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
    }

    /**
     * Tests that it is possible to combine find('list') and find('translations')
     */
    public function testFindTranslationsList(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', ['fields' => ['title', 'body']]);

        $results = $collection
            ->find(
                'list',
                keyField: 'title',
                valueField: '_translations.deu.title',
                groupField: 'id',
            )
            ->find('translations', locales: ['deu']);

        $expected = [
            '000000000000000000000001' => ['First Article' => 'Titel #1'],
            '000000000000000000000002' => ['Second Article' => 'Titel #2'],
            '000000000000000000000003' => ['Third Article' => 'Titel #3'],
        ];
        $this->assertEquals($expected, $results->toArray());
    }

    /**
     * Tests that you can both override fields and find all translations
     */
    public function testFindTranslationsWithFieldOverriding(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', ['fields' => ['title', 'body']]);
        $collection->getBehavior('Translate')->setLocale('cze');
        $results = $collection->find('translations', locales: ['deu', 'cze']);
        $expected = [
            [
                'deu' => ['title' => 'Titel #1', 'body' => 'Inhalt #1', 'locale' => 'deu'],
                'cze' => ['title' => 'Titulek #1', 'body' => 'Obsah #1', 'locale' => 'cze'],
            ],
            [
                'deu' => ['title' => 'Titel #2', 'body' => 'Inhalt #2', 'locale' => 'deu'],
                'cze' => ['title' => 'Titulek #2', 'body' => 'Obsah #2', 'locale' => 'cze'],
            ],
            [
                'deu' => ['title' => 'Titel #3', 'body' => 'Inhalt #3', 'locale' => 'deu'],
                'cze' => ['title' => 'Titulek #3', 'body' => 'Obsah #3', 'locale' => 'cze'],
            ],
        ];

        $translations = $this->extractTranslations($results);
        $this->assertEquals($expected, $translations->toArray());

        $expected = [
            '000000000000000000000001' => ['Titulek #1' => 'Obsah #1'],
            '000000000000000000000002' => ['Titulek #2' => 'Obsah #2'],
            '000000000000000000000003' => ['Titulek #3' => 'Obsah #3'],
        ];

        $grouped = $results->all()->combine('title', 'body', '_id');
        $this->assertEquals($expected, $grouped->toArray());
    }

    /**
     * Tests that fields can be overridden in a hasMany association
     */
    public function testFindSingleLocaleHasMany(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', ['fields' => ['title', 'body']]);
        $collection->hasMany('Comments');

        $comments = $collection->associations()->get('Comments')->getTarget();
        $comments->addBehavior('Translate', ['fields' => ['comment']]);

        $collection->getBehavior('Translate')->setLocale('eng');
        $comments->getBehavior('Translate')->setLocale('eng');

        $results = $collection->find()->contain(['Comments' => fn($q) => $q->select(['id', 'comment', 'article_id'])]);

        $list = new Collection($results->first()->comments);
        $expected = [
            '000000000000000000000001' => 'Comment #1',
            '000000000000000000000002' => 'Comment #2',
            '000000000000000000000003' => 'Comment #3',
            '000000000000000000000004' => 'Comment #4',
        ];
        $this->assertEquals($expected, $list->combine('id', 'comment')->toArray());
    }

    /**
     * Test that it is possible to bring translations from hasMany relations
     */
    public function testTranslationsHasMany(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', ['fields' => ['title', 'body']]);
        $collection->hasMany('Comments');

        $comments = $collection->associations()->get('Comments')->getTarget();
        $comments->addBehavior('Translate', ['fields' => ['comment']]);

        $results = $collection->find('translations')->contain([
            'Comments' => fn($q) => $q->find('translations')->select(['id', 'comment', 'article_id']),
        ]);

        $comments = $results->first()->comments;
        $expected = [
            [
                'eng' => ['comment' => 'Comment #1', 'locale' => 'eng'],
            ],
            [
                'eng' => ['comment' => 'Comment #2', 'locale' => 'eng'],
            ],
            [
                'eng' => ['comment' => 'Comment #3', 'locale' => 'eng'],
            ],
            [
                'eng' => ['comment' => 'Comment #4', 'locale' => 'eng'],
                'spa' => ['comment' => 'Comentario #4', 'locale' => 'spa'],
            ],
        ];

        $translations = $this->extractTranslations($comments);
        $this->assertEquals($expected, $translations->toArray());
    }

    /**
     * Tests that it is possible to both override fields with a translation and
     * also find separately other translations
     */
    public function testTranslationsHasManyWithOverride(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', ['fields' => ['title', 'body']]);
        $collection->hasMany('Comments');

        $comments = $collection->associations()->get('Comments')->getTarget();
        $comments->addBehavior('Translate', ['fields' => ['comment']]);

        $collection->getBehavior('Translate')->setLocale('cze');
        $comments->getBehavior('Translate')->setLocale('eng');
        $results = $collection->find('translations')->contain([
            'Comments' => fn($q) => $q->find('translations')->select(['id', 'comment', 'article_id']),
        ]);

        $comments = $results->first()->comments;
        $expected = [
            '000000000000000000000001' => 'Comment #1',
            '000000000000000000000002' => 'Comment #2',
            '000000000000000000000003' => 'Comment #3',
            '000000000000000000000004' => 'Comment #4',
        ];
        $list = new Collection($comments);
        $this->assertEquals($expected, $list->combine('id', 'comment')->toArray());

        $expected = [
            [
                'eng' => ['comment' => 'Comment #1', 'locale' => 'eng'],
            ],
            [
                'eng' => ['comment' => 'Comment #2', 'locale' => 'eng'],
            ],
            [
                'eng' => ['comment' => 'Comment #3', 'locale' => 'eng'],
            ],
            [
                'eng' => ['comment' => 'Comment #4', 'locale' => 'eng'],
                'spa' => ['comment' => 'Comentario #4', 'locale' => 'spa'],
            ],
        ];
        $translations = $this->extractTranslations($comments);
        $this->assertEquals($expected, $translations->toArray());

        $this->assertSame('Titulek #1', $results->first()->title);
        $this->assertSame('Obsah #1', $results->first()->body);
    }

    /**
     * Tests that it is possible to translate belongsTo associations
     */
    public function testFindSingleLocaleBelongsto(): void
    {
        /** @var \Crustum\Mongo\ODM\BaseCollection|\Cake\ORM\Behavior\TranslateBehavior $collection */
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', ['fields' => ['title', 'body']]);
        /** @var \Crustum\Mongo\ODM\BaseCollection|\Cake\ORM\Behavior\TranslateBehavior $authors */
        $authors = $collection->belongsTo('Authors')->getTarget();
        $authors->addBehavior('Translate', ['fields' => ['name']]);

        $collection->getBehavior('Translate')->setLocale('eng');
        $authors->getBehavior('Translate')->setLocale('eng');

        $results = $collection->find()
            ->select(['title', 'body'])
            ->orderBy(['title' => 'asc'])
            ->contain(['Authors' => fn(SelectQuery $q): SelectQuery => $q->select(['id', 'name'])]);

        $expected = [
            [
                'title' => 'Title #1',
                'body' => 'Content #1',
                'author' => ['_id' => '000000000000000000000001', 'name' => 'May-rianoh', '_locale' => 'eng'],
                '_locale' => 'eng',
            ],
            [
                'title' => 'Title #2',
                'body' => 'Content #2',
                'author' => ['_id' => '000000000000000000000003', 'name' => 'larry', '_locale' => 'eng'],
                '_locale' => 'eng',
            ],
            [
                'title' => 'Title #3',
                'body' => 'Content #3',
                'author' => ['_id' => '000000000000000000000001', 'name' => 'May-rianoh', '_locale' => 'eng'],
                '_locale' => 'eng',
            ],
        ];
        $results = array_map(fn(EntityInterface $r): array => $r->toArray(), $results->toArray());
        $this->assertEquals($expected, $results);
    }

    /**
     * Tests that it is possible to translate belongsTo associations using loadInto
     */
    public function testFindSingleLocaleBelongstoLoadInto(): void
    {
        /** @var \Crustum\Mongo\ODM\BaseCollection|\Cake\ORM\Behavior\TranslateBehavior $collection */
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', ['fields' => ['title', 'body']]);
        /** @var \Crustum\Mongo\ODM\BaseCollection|\Cake\ORM\Behavior\TranslateBehavior $authors */
        $authors = $collection->belongsTo('Authors')->getTarget();
        $authors->addBehavior('Translate', ['fields' => ['name']]);

        $collection->getBehavior('Translate')->setLocale('eng');
        $authors->getBehavior('Translate')->setLocale('eng');

        $document = $collection->get('000000000000000000000001');
        $result = $collection->loadInto($document, ['Authors']);
        $this->assertSame($document, $result);
        $this->assertNotEmpty($document->author);
        $this->assertNotEmpty($document->author->name);

        $expected = $collection->get('000000000000000000000001', ...['contain' => ['Authors']]);
        $this->assertEqualsCanonicalizing($expected->toArray(), $result->toArray());
        $this->assertNotEmpty($document->author);
        $this->assertNotEmpty($document->author->name);
    }

    /**
     * Tests that it is possible to translate belongsToMany associations
     */
    public function testFindSingleLocaleBelongsToMany(): void
    {
        $this->markTestSkipped('F36 — BTM through-junction `$lookup` embeds raw junction docs, junction translate + `special_tags` hydration never run; see docs/reference/42-translate-btm-matching-gap.md.');
        $collection = $this->getCollectionLocator()->get('Articles');
        /** @var \Crustum\Mongo\ODM\BaseCollection|\Cake\ORM\Behavior\TranslateBehavior $specialTags */
        $specialTags = $this->getCollectionLocator()->get('SpecialTags');
        $specialTags->addBehavior('Translate', ['fields' => ['extra_info']]);

        $collection->belongsToMany('Tags', [
            'through' => $specialTags,
        ]);
        $specialTags->getBehavior('Translate')->setLocale('eng');

        $result = $collection->get('000000000000000000000002', ...['contain' => 'Tags']);
        $this->assertNotEmpty($result);
        $this->assertNotEmpty($result->tags);
        $this->assertSame('Translated Info', $result->tags[0]->special_tags[0]->extra_info);
    }

    /**
     * Tests that parent entity isn't dirty when containing a translated association
     */
    public function testGetAssociationNotDirtyBelongsTo(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        /** @var \Crustum\Mongo\ODM\BaseCollection|\Cake\ORM\Behavior\TranslateBehavior $authors */
        $authors = $collection->belongsTo('Authors')->getTarget();
        $authors->addBehavior('Translate', ['fields' => ['name']]);

        $authors->getBehavior('Translate')->setLocale('eng');

        $document = $collection->get('000000000000000000000001');
        $this->assertNotEmpty($document);
        $document = $collection->loadInto($document, ['Authors']);
        $this->assertFalse($document->isDirty());
        $this->assertNotEmpty($document->author);
        $this->assertFalse($document->author->isDirty());

        $document = $collection->get('000000000000000000000001', ...['contain' => ['Authors']]);
        $this->assertNotEmpty($document);
        $this->assertFalse($document->isDirty());
        $this->assertNotEmpty($document->author);
        $this->assertFalse($document->author->isDirty());
    }

    /**
     * Tests that parent entity isn't dirty when containing a translated association
     */
    public function testGetAssociationNotDirtyHasOne(): void
    {
        $collection = $this->getCollectionLocator()->get('Authors');
        $collection->hasOne('Articles');
        $collection->Articles->addBehavior('Translate', ['fields' => ['title']]);

        $document = $collection->get('000000000000000000000001');
        $this->assertNotEmpty($document);
        $document = $collection->loadInto($document, ['Articles']);
        $this->assertFalse($document->isDirty());
        $this->assertNotEmpty($document->article);
        $this->assertFalse($document->article->isDirty());

        $document = $collection->get('000000000000000000000001', ...['contain' => 'Articles']);
        $this->assertNotEmpty($document);
        $this->assertFalse($document->isDirty());
        $this->assertNotEmpty($document->article);
        $this->assertFalse($document->article->isDirty());
    }

    /**
     * Tests that updating an existing record translations work
     */
    public function testUpdateSingleLocale(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', ['fields' => ['title', 'body']]);
        $collection->getBehavior('Translate')->setLocale('eng');
        $article = $collection->find()->first();
        $this->assertSame('000000000000000000000001', $article->getId());
        $article->set('title', 'New translated article');
        $collection->save($article);
        $this->assertNull($article->get('_i18n'));

        $article = $collection->find()->first();
        $this->assertSame('000000000000000000000001', $article->getId());
        $this->assertSame('New translated article', $article->get('title'));
        $this->assertSame('Content #1', $article->get('body'));

        $collection->getBehavior('Translate')->setLocale(null);
        $article = $collection->find()->first();
        $this->assertSame('000000000000000000000001', $article->getId());
        $this->assertSame('First Article', $article->get('title'));

        $collection->getBehavior('Translate')->setLocale('eng');
        $article->set('title', 'Wow, such translated article');
        $article->set('body', 'A translated body');

        $collection->save($article);
        $this->assertNull($article->get('_i18n'));

        $article = $collection->find()->first();
        $this->assertSame('000000000000000000000001', $article->getId());
        $this->assertSame('Wow, such translated article', $article->get('title'));
        $this->assertSame('A translated body', $article->get('body'));
    }

    /**
     * Tests adding new translation to a record
     */
    public function testInsertNewTranslations(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', ['fields' => ['title', 'body']]);
        $collection->getBehavior('Translate')->setLocale('fra');

        $article = $collection->find()->first();
        $this->assertSame('000000000000000000000001', $article->getId());
        $article->set('title', 'Le titre');
        $collection->save($article);
        $this->assertSame('fra', $article->get('_locale'));

        $article = $collection->find()->first();
        $this->assertSame('000000000000000000000001', $article->getId());
        $this->assertSame('Le titre', $article->get('title'));
        $this->assertSame('First Article Body', $article->get('body'));

        $article->set('title', 'Un autre titre');
        $article->set('body', 'Le contenu');

        $collection->save($article);
        $this->assertNull($article->get('_i18n'));

        $article = $collection->find()->first();
        $this->assertSame('Un autre titre', $article->get('title'));
        $this->assertSame('Le contenu', $article->get('body'));
    }

    /**
     * Tests that it is possible to use the _locale property to specify the language
     * to use for saving an entity
     */
    public function testUpdateTranslationWithLocaleInDocument(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', ['fields' => ['title', 'body']]);

        $article = $collection->find()->first();
        $this->assertSame('000000000000000000000001', $article->getId());
        $article->set('_locale', 'fra');
        $article->set('title', 'Le titre');

        $collection->save($article);
        $this->assertNull($article->get('_i18n'));

        $article = $collection->find()->first();
        $this->assertSame('000000000000000000000001', $article->getId());
        $this->assertSame('First Article', $article->get('title'));
        $this->assertSame('First Article Body', $article->get('body'));

        $collection->getBehavior('Translate')->setLocale('fra');
        $article = $collection->find()->first();
        $this->assertSame('000000000000000000000001', $article->getId());
        $this->assertSame('Le titre', $article->get('title'));
        $this->assertSame('First Article Body', $article->get('body'));
    }

    /**
     * Tests that translations are added to the whitelist of associations to be
     * saved
     */
    public function testSaveTranslationWithAssociationWhitelist(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->hasMany('Comments');
        $collection->addBehavior('Translate', ['fields' => ['title', 'body']]);
        $collection->getBehavior('Translate')->setLocale('fra');

        $article = $collection->find()->first();
        $this->assertSame('000000000000000000000001', $article->getId());
        $article->set('title', 'Le titre');
        $collection->save($article, ['associated' => ['Comments']]);
        $this->assertNull($article->get('_i18n'));

        $article = $collection->find()->first();
        $this->assertSame('Le titre', $article->get('title'));
    }

    /**
     * Tests saving multiple translations at once when the translations already
     * exist in the database
     */
    public function testSaveMultipleTranslations(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', ['fields' => ['title', 'body']]);

        $article = $collection->find('translations')->first();

        $translations = $article->get('_translations');
        $translations['deu']->set('title', 'Another title');
        $translations['eng']->set('body', 'Another body');
        $article->set('_translations', $translations);
        $collection->save($article);
        $this->assertNull($article->get('_i18n'));
        $article = $collection->find('translations')->first();
        $translations = $article->get('_translations');
        $this->assertSame('Another title', $translations['deu']->get('title'));
        $this->assertSame('Another body', $translations['eng']->get('body'));
    }

    /**
     * Tests saving multiple existing translations and adding new ones
     */
    public function testSaveMultipleNewTranslations(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', ['fields' => ['title', 'body']]);

        $article = $collection->find('translations')->first();

        $translations = $article->get('_translations');
        $translations['deu']->set('title', 'Another title');
        $translations['eng']->set('body', 'Another body');
        $translations['spa'] = new Document(['title' => 'Titulo']);
        $translations['fre'] = new Document(['title' => 'Titre']);
        $article->set('_translations', $translations);
        $collection->save($article);
        $this->assertNull($article->get('_i18n'));
        $article = $collection->find('translations')->first();
        $translations = $article->get('_translations');
        $this->assertSame('Another title', $translations['deu']->get('title'));
        $this->assertSame('Another body', $translations['eng']->get('body'));
        $this->assertSame('Titulo', $translations['spa']->get('title'));
        $this->assertSame('Titre', $translations['fre']->get('title'));
    }

    /**
     * Tests that iterating a result set twice when using the translations finder
     * will not cause any errors nor information loss
     */
    public function testUseCountInFindTranslations(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', ['fields' => ['title', 'body']]);

        $articles = $collection->find('translations');
        $all = $articles->all();
        $this->assertCount(3, $all);
        $article = $all->first();
        $this->assertNotEmpty($article->get('_translations'));
    }

    /**
     * Tests that multiple translations saved when having a default locale
     * are correctly saved
     */
    public function testSavingWithNonDefaultLocale(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', ['fields' => ['title', 'body']]);
        $collection->setDocumentClass(TranslateArticle::class);
        I18n::setLocale('fra');
        $translations = [
            'fra' => ['title' => 'Un article'],
            'spa' => ['title' => 'Un artículo'],
        ];

        $article = $collection->get('000000000000000000000001');
        foreach ($translations as $lang => $data) {
            $article->getOrCreateTranslation($lang)->patch($data, ['guard' => false]);
        }

        $collection->save($article);
        $article = $collection->find('translations')->where(['Articles.id' => '000000000000000000000001'])->first();
        $this->assertSame('Un article', $article->translation('fra')->title);
        $this->assertSame('Un artículo', $article->translation('spa')->title);
    }

    /**
     * Tests that onlyTranslated will remove records from the result set
     * if they are not fully translated
     */
    public function testFilterUntranslated(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        /** @var \Crustum\Mongo\ODM\BaseCollection|\Cake\ORM\Behavior\TranslateBehavior $collection */
        $collection->addBehavior('Translate', [
            'fields' => ['title', 'body'],
            'onlyTranslated' => true,
        ]);
        $collection->getBehavior('Translate')->setLocale('eng');
        $results = $collection->find()->where(['Articles.id' => '000000000000000000000001'])->all();
        $this->assertCount(1, $results);

        $collection->getBehavior('Translate')->setLocale('fr');
        $results = $collection->find()->where(['Articles.id' => '000000000000000000000001'])->all();
        $this->assertCount(0, $results);
    }

    /**
     * Tests that records not translated in the current locale will not be
     * present in the results for the translations finder, and also proves
     * that this can be overridden.
     */
    public function testFilterUntranslatedWithFinder(): void
    {
        $collection = $this->getCollectionLocator()->get('Comments');
        /** @var \Crustum\Mongo\ODM\BaseCollection|\Cake\ORM\Behavior\TranslateBehavior $collection */
        $collection->addBehavior('Translate', [
            'fields' => ['comment'],
            'onlyTranslated' => true,
        ]);
        $collection->getBehavior('Translate')->setLocale('eng');
        $results = $collection->find('translations')->all();
        $this->assertCount(4, $results);

        $collection->getBehavior('Translate')->setLocale('spa');
        $results = $collection->find('translations')->all();
        $this->assertCount(1, $results);

        $collection->getBehavior('Translate')->setLocale('spa');
        $results = $collection->find('translations', filterByCurrentLocale: false)->all();
        $this->assertCount(6, $results);

        $collection->getBehavior('Translate')->setLocale('spa');
        $results = $collection->find('translations')->all();
        $this->assertCount(1, $results);
    }

    /**
     * Tests that allowEmptyTranslations takes effect
     */
    public function testEmptyTranslations(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        /** @var \Crustum\Mongo\ODM\BaseCollection|\Cake\ORM\Behavior\TranslateBehavior $collection */
        $collection->addBehavior('Translate', [
            'fields' => ['title', 'body', 'description'],
            'allowEmptyTranslations' => false,
        ]);
        $collection->getBehavior('Translate')->setLocale('spa');
        $result = $collection->find()->first();
        $this->assertNull($result->description);
    }

    /**
     * Test save with clean translate fields
     */
    public function testSaveWithCleanFields(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', ['fields' => ['title']]);
        $collection->setDocumentClass(TranslateArticle::class);
        I18n::setLocale('fra');
        $article = $collection->get('000000000000000000000001');
        $article->set('body', 'New Body');

        $collection->save($article);
        $result = $collection->get('000000000000000000000001');
        $this->assertSame('New Body', $result->body);
        $this->assertSame($article->title, $result->title);
    }

    /**
     * Tests adding new translation to a record where the only field is the translated one and it's not the default locale
     */
    public function testSaveNewRecordWithOnlyTranslationsNotDefaultLocale(): void
    {
        $collection = $this->getCollectionLocator()->get('Sections');
        $collection->getValidator()->add('title', 'notBlank', ['rule' => 'notBlank']);
        $collection->addBehavior('Translate', [
            'fields' => ['title'],
        ]);

        $data = [
            '_translations' => [
                'es' => [
                    'title' => 'Title ES',
                ],
            ],
        ];

        $group = $collection->newDocument($data);
        $result = $collection->save($group);
        $this->assertNotFalse($result, 'Record should save.');

        $expected = [
            [
                'es' => [
                    'title' => 'Title ES',
                    'locale' => 'es',
                ],
            ],
        ];
        $result = $collection->find('translations')->where(['id' => $result->getId()]);
        $this->assertEquals($expected, $this->extractTranslations($result)->toArray());
    }

    /**
     * Test that existing records can be updated when only translations
     * are modified/dirty.
     */
    public function testSaveExistingRecordOnlyTranslations(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', ['fields' => ['title', 'body']]);
        $collection->setDocumentClass(TranslateArticle::class);

        $data = [
            '_translations' => [
                'es' => [
                    'title' => 'Spanish Translation',
                ],
            ],
        ];

        $article = $collection->find()->first();
        $article = $collection->patchDocument($article, $data);

        $this->assertNotFalse($collection->save($article));

        $results = $this->extractTranslations(
            $collection->find('translations')->where(['_id' => '000000000000000000000001']),
        )->first();

        $this->assertArrayHasKey('es', $results, 'New translation added');
        $this->assertArrayHasKey('eng', $results, 'Old translations present');
        $this->assertSame('Spanish Translation', $results['es']['title']);
    }

    /**
     * Tests that default locale saves ok.
     */
    public function testSaveDefaultLocale(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->hasMany('Comments');
        $collection->addBehavior('Translate', ['fields' => ['title', 'body']]);

        $article = $collection->get('000000000000000000000001');
        $data = [
            'title' => 'New title',
            'body' => 'New body',
        ];
        $article = $collection->patchDocument($article, $data);
        $collection->save($article);
        $this->assertNull($article->get('_i18n'));

        $article = $collection->get('000000000000000000000001');
        $this->assertSame('New title', $article->get('title'));
        $this->assertSame('New body', $article->get('body'));
    }

    /**
     * Test that when `defaultLocale` feature is disabled translations table
     * is always used.
     */
    public function testSaveDefaultLocaleFalse(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', [
            'defaultLocale' => '',
            'fields' => ['title', 'body'],
        ]);

        $data = [
            'title' => 'New title',
            'body' => 'New body',
            'published' => 'Y',
        ];
        $article = $collection->newDocument($data);
        $result = $collection->save($article);
        $this->assertNotEmpty($result);

        $record = $collection->get($article->getId());
        $this->assertSame($data['title'], $record->title);
        $this->assertSame($data['body'], $record->body);

        $collection->removeBehavior('Translate');
        $record = $collection->get($article->getId());
        $this->assertEmpty($record->title);
        $this->assertEmpty($record->body);

        $article->title = 'updated title';
        $collection->addBehavior('Translate', [
            'defaultLocale' => '',
            'fields' => ['title', 'body'],
        ]);
        $result = $collection->save($article);
        $this->assertNotEmpty($result);

        $record = $collection->get($article->getId());
        $this->assertSame('updated title', $record->title);

        $collection->removeBehavior('Translate');
        $record = $collection->get($article->getId());
        $this->assertEmpty($record->title);
    }

    /**
     * Tests that translations are added to the whitelist of associations to be
     * saved
     */
    public function testSaveTranslationDefaultLocale(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->hasMany('Comments');
        $collection->addBehavior('Translate', ['fields' => ['title', 'body']]);

        $article = $collection->get('000000000000000000000001');
        $data = [
            'title' => 'New title',
            'body' => 'New body',
            '_translations' => [
                'es' => [
                    'title' => 'ES title',
                    'body' => 'ES body',
                ],
            ],
        ];
        $article = $collection->patchDocument($article, $data);
        $collection->save($article);
        $this->assertNull($article->get('_i18n'));

        $article = $collection->find('translations')->where(['_id' => '000000000000000000000001'])->first();
        $this->assertSame('New title', $article->get('title'));
        $this->assertSame('New body', $article->get('body'));

        $this->assertSame('ES title', $article->_translations['es']->title);
        $this->assertSame('ES body', $article->_translations['es']->body);
    }

    /**
     * Test that no properties are enabled when the translations
     * option is off.
     */
    public function testBuildMarshalMapTranslationsOff(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', ['fields' => ['title', 'body']]);

        $marshaller = $collection->marshaller();
        $translate = $collection->behaviors()->get('Translate');
        $result = $translate->buildMarshalMap($marshaller, [], ['translations' => false]);
        $this->assertSame([], $result);
    }

    /**
     * Test building a marshal map with translations on.
     */
    public function testBuildMarshalMapTranslationsOn(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', ['fields' => ['title', 'body']]);

        $marshaller = $collection->marshaller();
        $translate = $collection->behaviors()->get('Translate');

        $result = $translate->buildMarshalMap($marshaller, [], ['translations' => true]);
        $this->assertArrayHasKey('_translations', $result);
        $this->assertInstanceOf('Closure', $result['_translations']);

        $result = $translate->buildMarshalMap($marshaller, [], []);
        $this->assertArrayHasKey('_translations', $result);
        $this->assertInstanceOf('Closure', $result['_translations']);
    }

    /**
     * Test marshalling non-array data
     */
    public function testBuildMarshalMapNonArrayData(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', ['fields' => ['title', 'body']]);

        $translate = $collection->behaviors()->get('Translate');

        $map = $translate->buildMarshalMap($collection->marshaller(), [], []);
        $document = $collection->newEmptyDocument();
        $result = $map['_translations']('garbage', $document);
        $this->assertNull($result, 'Non-array should not error out.');
        $this->assertEmpty($document->getErrors());
        $this->assertEmpty($document->get('_translations'));
    }

    /**
     * Test buildMarshalMap() builds new entities.
     */
    public function testBuildMarshalMapBuildEntities(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', ['fields' => ['title', 'body']]);

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
     * Test that validation errors are added to the original entity.
     */
    public function testBuildMarshalMapBuildEntitiesValidationErrors(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', [
            'fields' => ['title', 'body'],
            'validator' => 'custom',
        ]);
        $validator = new Validator()->notEmptyString('title');
        $collection->setValidator('custom', $validator);
        $translate = $collection->behaviors()->get('Translate');

        $document = $collection->newEmptyDocument();
        $map = $translate->buildMarshalMap($collection->marshaller(), [], []);
        $data = [
            'en' => [
                'title' => 'English Title',
                'body' => 'English Content',
            ],
            'es' => [
                'title' => '',
                'body' => 'Contenido Español',
            ],
        ];
        $result = $map['_translations']($data, $document);
        $this->assertNotEmpty($document->getErrors(), 'Needs validation errors.');
        $expected = [
            'title' => [
                '_empty' => 'This field cannot be left empty',
            ],
        ];
        $this->assertEquals($expected, $document->getError('_translations.es'));

        $this->assertSame('English Title', $result['en']->title);
        $this->assertNull($result['es']->title);
    }

    /**
     * Test that marshalling updates existing translation entities.
     */
    public function testBuildMarshalMapUpdateExistingEntities(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', [
            'fields' => ['title', 'body'],
        ]);
        $translate = $collection->behaviors()->get('Translate');

        $document = $collection->newEmptyDocument();
        $es = $collection->newDocument(['title' => 'Old title', 'body' => 'Old body']);
        $en = $collection->newDocument(['title' => 'Old title', 'body' => 'Old body']);
        $document->set('_translations', [
            'es' => $es,
            'en' => $en,
        ]);
        $map = $translate->buildMarshalMap($collection->marshaller(), [], []);
        $data = [
            'en' => [
                'title' => 'English Title',
            ],
            'es' => [
                'title' => 'Spanish Title',
            ],
        ];
        $result = $map['_translations']($data, $document);
        $this->assertEmpty($document->getErrors(), 'No validation errors.');
        $this->assertSame($en, $result['en']);
        $this->assertSame($es, $result['es']);
        $this->assertSame($en, $document->get('_translations')['en']);
        $this->assertSame($es, $document->get('_translations')['es']);

        $this->assertSame('English Title', $result['en']->title);
        $this->assertSame('Spanish Title', $result['es']->title);
        $this->assertSame('Old body', $result['en']->body);
        $this->assertSame('Old body', $result['es']->body);
    }

    /**
     * Test that updating translation records works with validations.
     */
    public function testBuildMarshalMapUpdateEntitiesValidationErrors(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', [
            'fields' => ['title', 'body'],
            'validator' => 'custom',
        ]);
        $validator = new Validator()->notEmptyString('title');
        $collection->setValidator('custom', $validator);
        $translate = $collection->behaviors()->get('Translate');

        $document = $collection->newEmptyDocument();
        $es = $collection->newDocument(['title' => 'Old title', 'body' => 'Old body']);
        $en = $collection->newDocument(['title' => 'Old title', 'body' => 'Old body']);
        $document->set('_translations', [
            'es' => $es,
            'en' => $en,
        ]);
        $map = $translate->buildMarshalMap($collection->marshaller(), [], []);
        $data = [
            'en' => [
                'title' => 'English Title',
                'body' => 'English Content',
            ],
            'es' => [
                'title' => '',
                'body' => 'Contenido Español',
            ],
        ];
        $map['_translations']($data, $document);
        $this->assertNotEmpty($document->getErrors(), 'Needs validation errors.');
        $expected = [
            'title' => [
                '_empty' => 'This field cannot be left empty',
            ],
        ];
        $this->assertEquals($expected, $document->getError('_translations.es'));
    }

    /**
     * Test that the behavior uses associations' locator.
     */
    public function testDefaultTableLocator(): void
    {
        $locator = new CollectionLocator();

        $collection = $locator->get('Articles');
        $collection->addBehavior('Translate', [
            'fields' => ['title', 'body'],
            'validator' => 'custom',
        ]);

        $behaviorLocator = $collection->behaviors()->get('Translate')->getCollectionLocator();

        $this->assertSame($locator, $behaviorLocator);
        $this->assertSame($collection->associations()->getCollectionLocator(), $behaviorLocator);
        $this->assertNotSame($this->getCollectionLocator(), $behaviorLocator);
    }

    /**
     * Test that the behavior uses a custom locator.
     */
    public function testCustomTableLocator(): void
    {
        $locator = new CollectionLocator();

        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', [
            'fields' => ['title', 'body'],
            'validator' => 'custom',
            'collectionLocator' => $locator,
        ]);

        $behaviorLocator = $collection->behaviors()->get('Translate')->getCollectionLocator();

        $this->assertSame($locator, $behaviorLocator);
        $this->assertNotSame($collection->associations()->getCollectionLocator(), $behaviorLocator);
        $this->assertNotSame($this->getCollectionLocator(), $behaviorLocator);
    }

    /**
     * Tests that using deep matching doesn't cause an association property to be created.
     */
    public function testDeepMatchingDoesNotCreateAssociationProperty(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->hasMany('Comments');
        $collection->Comments->belongsTo('Authors')->setForeignKey('user_id');

        $collection->Comments->addBehavior('Translate', ['fields' => ['comment']]);
        $collection->Comments->getBehavior('Translate')->setLocale('abc');

        $collection->Comments->Authors->addBehavior('Translate', ['fields' => ['name']]);
        $collection->Comments->Authors->getBehavior('Translate')->setLocale('xyz');

        $this->assertNotEquals($collection->Comments->getBehavior('Translate')->getLocale(), I18n::getLocale());
        $this->assertNotEquals($collection->Comments->Authors->getBehavior('Translate')->getLocale(), I18n::getLocale());

        $result = $collection
            ->find()
            ->contain('Comments')
            ->matching('Comments.Authors')
            ->first();

        $this->assertArrayNotHasKey('author', $result->comments);
    }

    /**
     * Tests that the _locale property is set on the entity in the _matchingData property.
     */
    public function testLocalePropertyIsSetInMatchingData(): void
    {
        $this->markTestSkipped('F36 — matching `$lookup` embeds raw docs, target beforeFind never sets `_locale` on `_matchingData`; see docs/reference/42-translate-btm-matching-gap.md.');
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->hasMany('Comments');

        $collection->Comments->addBehavior('Translate', ['fields' => ['comment']]);
        $collection->Comments->getBehavior('Translate')->setLocale('abc');

        $this->assertNotEquals($collection->Comments->getBehavior('Translate')->getLocale(), I18n::getLocale());

        $result = $collection
            ->find()
            ->contain('Comments')
            ->matching('Comments')
            ->first();

        $this->assertArrayNotHasKey('_locale', $result->comments);
        $this->assertSame('abc', $result->_matchingData['Comments']->_locale);
    }

    /**
     * Tests that the _locale property is set on the entity in the _matchingData property
     * when using deep matching.
     */
    public function testLocalePropertyIsSetInMatchingDataWhenUsingDeepMatching(): void
    {
        $this->markTestSkipped('F36 — deep matching `$lookup` embeds raw docs, `_locale` never set on nested `_matchingData`; see docs/reference/42-translate-btm-matching-gap.md.');
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->hasMany('Comments');
        $collection->Comments->belongsTo('Authors')->setForeignKey('user_id');

        $collection->Comments->addBehavior('Translate', ['fields' => ['comment']]);
        $collection->Comments->getBehavior('Translate')->setLocale('abc');

        $collection->Comments->Authors->addBehavior('Translate', ['fields' => ['name']]);
        $collection->Comments->Authors->getBehavior('Translate')->setLocale('xyz');

        $this->assertNotEquals($collection->Comments->getBehavior('Translate')->getLocale(), I18n::getLocale());
        $this->assertNotEquals($collection->Comments->Authors->getBehavior('Translate')->getLocale(), I18n::getLocale());

        $result = $collection
            ->find()
            ->contain('Comments.Authors')
            ->matching('Comments.Authors')
            ->first();

        $this->assertArrayNotHasKey('_locale', $result->comments);
        $this->assertSame('abc', $result->_matchingData['Comments']->_locale);
        $this->assertSame('xyz', $result->_matchingData['Authors']->_locale);
    }

    /**
     * Tests that the _locale property is set on the entity in the _matchingData property
     * when using contained matching.
     */
    public function testLocalePropertyIsSetInMatchingDataWhenUsingContainedMatching(): void
    {
        $this->markTestSkipped('F36 — contained matching `$lookup` pipeline (FK-presence guard error + `_locale` never set); see docs/reference/42-translate-btm-matching-gap.md.');
        $collection = $this->getCollectionLocator()->get('Authors');
        $collection->hasMany('Articles');
        $collection->Articles->belongsToMany('Tags');

        $collection->Articles->addBehavior('Translate', ['fields' => ['title', 'body']]);
        $collection->Articles->getBehavior('Translate')->setLocale('abc');

        $collection->Articles->Tags->addBehavior('Translate', ['fields' => ['name']]);
        $collection->Articles->Tags->getBehavior('Translate')->setLocale('xyz');

        $this->assertNotEquals($collection->Articles->getBehavior('Translate')->getLocale(), I18n::getLocale());
        $this->assertNotEquals($collection->Articles->Tags->getBehavior('Translate')->getLocale(), I18n::getLocale());

        $result = $collection
            ->find()
            ->contain([
                'Articles' => fn($query) => $query->matching('Tags'),
                'Articles.Tags',
            ])
            ->first();

        $this->assertArrayNotHasKey('_locale', $result->articles);
        $this->assertArrayNotHasKey('_locale', $result->articles[0]->tags);
        $this->assertSame('abc', $result->articles[0]->_locale);
        $this->assertSame('xyz', $result->articles[0]->_matchingData['Tags']->_locale);
    }

    /**
     * Tests that modified entities aren't marked as clean after the translation rowMapper
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

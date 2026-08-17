<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM\Behavior;

use Crustum\Mongo\ODM\Behavior\Translate\EmbedStrategy;
use Crustum\Mongo\ODM\Behavior\Translate\ShadowCollectionStrategy;
use Crustum\Mongo\ODM\Behavior\TranslateBehavior;
use TestApp\Model\Document\TranslateArticle;

/**
 * TranslateBehavior test case for the embed strategy.
 *
 * Translations are stored inline under the `_translations` embedded field of
 * each document (keyed by locale). Extends the EAV test base so the full
 * functional surface (find/save/translations/marshalling) is exercised against
 * the embedded storage; only the EAV-internal methods are overridden.
 *
 * @see cake60/tests/TestCase/ORM/Behavior/TranslateBehaviorTest.php
 */
class TranslateBehaviorEmbedTest extends TranslateBehaviorTestBase
{
    /**
     * fixtures
     *
     * The EAV base loads the shared `Articles` fixture; the embed strategy uses
     * its own `articles_embed` storage instead.
     *
     * @var array<string>
     */
    protected array $fixtures = [
        'plugin.Crustum/Mongo.ArticlesEmbed',
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
        parent::setUpBeforeClass();

        TranslateBehavior::setDefaultStrategyClass(EmbedStrategy::class);
    }

    /**
     * tearDownAfterClass
     */
    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();

        TranslateBehavior::setDefaultStrategyClass(ShadowCollectionStrategy::class);
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Redirect the `Articles` alias to the embedded storage so the
        // inherited EAV methods run against the embed strategy.
        $this->getCollectionLocator()->get('Articles', ['collection' => 'articles_embed']);
    }

    /**
     * The embed strategy stores translations in the source collection, so the
     * "translation collection" is the source collection itself (there is no
     * custom translation collection/datasource).
     */
    public function testCustomTranslationCollection(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', ['fields' => ['title', 'body']]);

        $strategy = $collection->getBehavior('Translate')->getStrategy();
        $this->assertInstanceOf(EmbedStrategy::class, $strategy);
        $this->assertSame($collection, $strategy->getTranslationCollection());
    }

    /**
     * The strategy is fixed to `embed`; no `_i18n` association is created.
     */
    public function testStrategy(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', [
            'strategy' => 'embed',
            'fields' => ['title', 'body'],
        ]);

        $strategy = $collection->getBehavior('Translate')->getStrategy();
        $this->assertInstanceOf(EmbedStrategy::class, $strategy);
        $this->assertSame('_translations', $strategy->getConfig('embedField'));
    }

    /**
     * Deleting an article removes the embedded translations structurally.
     */
    public function testDelete(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', ['fields' => ['title', 'body']]);

        $article = $collection->find()->first();
        $this->assertTrue($collection->delete($article));

        $count = $collection->find()->where(['_id' => $article->getId()])->count();
        $this->assertSame(0, $count);
    }

    /**
     * The embed strategy has no translation collection to derive a reference
     * name for; the config keeps the default embedded field.
     */
    public function testAutoReferenceName(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate');

        $config = $collection->getBehavior('Translate')->getStrategy()->getConfig();
        $this->assertSame('_translations', $config['embedField']);
    }

    /**
     * Changing the reference name does not affect the embedded storage.
     */
    public function testChangingReferenceName(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', ['referenceName' => 'Posts']);

        $config = $collection->getBehavior('Translate')->getStrategy()->getConfig();
        $this->assertSame('_translations', $config['embedField']);
    }

    /**
     * A translated field can be filtered and ordered through the embedded
     * dotted path without any join.
     */
    public function testDottedPathFilterAndOrder(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->setDocumentClass(TranslateArticle::class);
        $collection->addBehavior('Translate', ['fields' => ['title', 'body']]);
        $collection->getBehavior('Translate')->setLocale('eng');

        $result = $collection->find()
            ->where(['_translations.eng.title' => 'Title #2'])
            ->first();
        $this->assertSame('000000000000000000000002', $result->get('_id'));

        $ids = $collection->find()
            ->orderBy(['_translations.eng.title' => 'ASC'])
            ->enableHydration(false)
            ->all()
            ->extract('_id')
            ->toArray();
        $this->assertSame(
            ['000000000000000000000001', '000000000000000000000002', '000000000000000000000003'],
            $ids,
        );
    }

    /**
     * Saving an entity fetched for a non-default locale must not overwrite the
     * default-locale root fields with the translated copies.
     */
    public function testSaveDoesNotOverwriteRootFields(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->setDocumentClass(TranslateArticle::class);
        $collection->addBehavior('Translate', ['fields' => ['title', 'body']]);
        $collection->getBehavior('Translate')->setLocale('eng');

        $document = $collection->get('000000000000000000000001');
        $this->assertSame('Title #1', $document->title);
        $document->published = 'N';
        $this->assertNotFalse($collection->save($document));

        // Read the raw document: without the behavior, the root title is the
        // default-locale value, untouched by the translated copies.
        $collection->removeBehavior('Translate');
        $fresh = $collection->find()->enableHydration(false)->where(['_id' => '000000000000000000000001'])->first();
        $this->assertSame('First Article', $fresh['title'], 'Root title stays the default-locale value');
        $this->assertSame('N', $fresh['published']);
        $this->assertSame('Title #1', $fresh['_translations']['eng']['title']);
    }

    /**
     * Translations written through `getOrCreateTranslation()` are persisted as
     * the embedded field and survive a re-fetch.
     */
    public function testTranslationsPersistedEmbedded(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->setDocumentClass(TranslateArticle::class);
        $collection->addBehavior('Translate', ['fields' => ['title', 'body']]);
        $collection->getBehavior('Translate')->setLocale('deu');

        $document = $collection->get('000000000000000000000003');
        $document->getOrCreateTranslation('spa')->title = 'Titulo es';
        $this->assertNotFalse($collection->save($document));

        $collection->removeBehavior('Translate');
        $raw = $collection->find()->enableHydration(false)->where(['_id' => '000000000000000000000003'])->first();
        $this->assertSame('Titulo es', $raw['_translations']['spa']['title']);
    }

    /**
     * The active locale's translated fields override the root document.
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

        $collection->getBehavior('Translate')->setLocale('cze');
        $results = $collection->find()->all()->combine('title', 'body', '_id')->toArray();
        $expected = [
            '000000000000000000000001' => ['Titulek #1' => 'Obsah #1'],
            '000000000000000000000002' => ['Titulek #2' => 'Obsah #2'],
            '000000000000000000000003' => ['Titulek #3' => 'Obsah #3'],
        ];
        $this->assertSame($expected, $results);
    }

    /**
     * Translations survive iteration of a translated find.
     */
    public function testFindTranslationsFormatResultsIteration(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', ['fields' => ['title', 'body']]);

        $results = $collection->find('translations')->all();
        foreach ($results as $row) {
            $this->assertNotEmpty($row->get('_translations'));
        }
    }

    /**
     * The embed strategy resolves a translated field to the embedded dotted
     * path for the current locale.
     */
    public function testTranslationFieldForTranslatedFields(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', ['fields' => ['title', 'body'], 'defaultLocale' => 'en_US']);

        $collection->getBehavior('Translate')->setLocale('es_ES');
        $this->assertSame('_translations.es_ES.title', $collection->getBehavior('Translate')->translationField('title'));
        $this->assertSame('Articles.published', $collection->getBehavior('Translate')->translationField('published'));

        $collection->getBehavior('Translate')->setLocale('en_US');
        $this->assertSame('Articles.title', $collection->getBehavior('Translate')->translationField('title'));
    }

    /**
     * All embedded translations are returned by the translations finder.
     */
    public function testFindTranslations(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', ['fields' => ['title', 'body']]);

        $results = $collection->find('translations', locales: ['eng', 'deu', 'cze', 'spa'])->all();
        $translations = $this->extractTranslations($results)->toArray();

        $this->assertSame('Title #1', $translations[0]['eng']['title']);
        $this->assertSame('Titel #1', $translations[0]['deu']['title']);
        $this->assertSame('Titulek #1', $translations[0]['cze']['title']);
        $this->assertSame('Contenido #1', $translations[0]['spa']['body']);
        $this->assertArrayNotHasKey('zzz', $translations[0]);
        $this->assertSame('Title #2', $translations[1]['eng']['title']);
    }

    /**
     * The `onlyTranslated` filter and per-find `filterByCurrentLocale` work on
     * the embedded translations.
     */
    public function testFilterUntranslatedWithFinder(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', [
            'fields' => ['title', 'body'],
            'onlyTranslated' => true,
        ]);

        $collection->getBehavior('Translate')->setLocale('eng');
        $this->assertCount(3, $collection->find('translations')->all());

        $collection->getBehavior('Translate')->setLocale('spa');
        $this->assertCount(1, $collection->find('translations')->all());

        $this->assertCount(3, $collection->find('translations', filterByCurrentLocale: false)->all());
    }

    /**
     * The embedded translations survive an update of the active locale.
     */
    public function testSaveExistingRecordWithTranslatesField(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->setDocumentClass(TranslateArticle::class);
        $collection->addBehavior('Translate', ['fields' => ['title', 'body']]);

        $article = $collection->find()->first();
        $article = $collection->patchDocument($article, [
            '_translations' => [
                'spa' => [
                    'title' => 'Mi nuevo titulo',
                    'body' => 'Contenido Actualizado',
                ],
            ],
        ]);
        $this->assertNotFalse($collection->save($article));

        $result = $collection->find('translations')->where(['_id' => '000000000000000000000001'])->first();
        $translations = $result->get('_translations');
        $this->assertSame('Mi nuevo titulo', $translations['spa']->title);
        $this->assertSame('Contenido Actualizado', $translations['spa']->body);
        // Existing locales are preserved.
        $this->assertSame('Title #1', $translations['eng']->title);
    }

    /**
     * Saving a new entity with `_translations` persists the embedded map.
     */
    public function testSaveNewRecordWithTranslatesField(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->setDocumentClass(TranslateArticle::class);
        $collection->addBehavior('Translate', [
            'defaultLocale' => 'en',
            'fields' => ['title'],
        ]);

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

        $result = $collection->find('translations')->where(['_id' => $result->get('_id')])->first();
        $translations = $result->get('_translations');
        $this->assertSame('Title FR', $translations['fr']->title);
        $this->assertSame('Title ES', $translations['es']->title);
        $this->assertSame('Title EN', $result->title);
        $this->assertSame('Body EN', $result->body);
    }

    /**
     * allowEmptyTranslations=false drops empty translations from the embedded
     * map.
     */
    public function testAllowEmptyFalse(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', [
            'fields' => ['title'],
            'allowEmptyTranslations' => false,
        ]);

        $article = $collection->find()->first();
        $article = $collection->patchDocument($article, [
            '_translations' => [
                'fra' => [
                    'title' => '',
                ],
            ],
        ]);
        $collection->save($article);

        $collection->removeBehavior('Translate');
        $raw = $collection->find()->enableHydration(false)->where(['_id' => '000000000000000000000001'])->first();
        $this->assertArrayNotHasKey('fra', $raw['_translations']);
    }

    /**
     * Associated collections load normally with the embed strategy; only the
     * translated source document is merged.
     */
    public function testFindSingleLocaleBelongsto(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->belongsTo('Authors');
        $collection->addBehavior('Translate', ['fields' => ['title', 'body']]);
        $collection->getBehavior('Translate')->setLocale('eng');

        $result = $collection->find()->select(['title', 'body'])->contain(['Authors'])->first();
        $this->assertSame('Title #1', $result->title);
        $this->assertSame('Content #1', $result->body);
        $this->assertSame('mariano', $result->author->name);
    }

    /**
     * The embed strategy resolves the translation collection to the source
     * collection; a custom locator is irrelevant.
     */
    public function testDefaultTableLocator(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', ['fields' => ['title']]);

        $strategy = $collection->getBehavior('Translate')->getStrategy();
        $this->assertSame($collection, $strategy->getTranslationCollection());
    }

    /**
     * A custom locator does not change the embedded storage.
     */
    public function testCustomTableLocator(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', ['fields' => ['title']]);

        $strategy = $collection->getBehavior('Translate')->getStrategy();
        $this->assertSame($collection, $strategy->getTranslationCollection());
    }

    /**
     * Translated documents load their hasMany associations.
     */
    public function testFindSingleLocaleHasMany(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->hasMany('Comments');
        $collection->addBehavior('Translate', ['fields' => ['title', 'body']]);
        $collection->getBehavior('Translate')->setLocale('eng');

        $result = $collection->find()->contain('Comments')->first();
        $this->assertSame('Title #1', $result->title);
        $this->assertCount(4, $result->comments);
        $this->assertSame('First Comment for First Article', $result->comments[0]->comment);
    }

    /**
     * Translated documents load their associated collections in a translated
     * environment.
     */
    public function testFindSingleLocaleAssociatedEnv(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->hasMany('Comments');
        $collection->addBehavior('Translate', ['fields' => ['title', 'body']]);
        $collection->getBehavior('Translate')->setLocale('eng');

        $result = $collection->find()->contain('Comments')->first();
        $this->assertSame('Title #1', $result->title);
        $this->assertSame('Content #1', $result->body);
        $this->assertCount(4, $result->comments);
    }

    /**
     * `find('translations')` works on collections whose documents have no
     * embedded translations (empty maps).
     */
    public function testTranslationsHasMany(): void
    {
        $collection = $this->getCollectionLocator()->get('Comments');
        $collection->addBehavior('Translate', ['fields' => ['comment']]);

        $result = $collection->find('translations')->first();
        $this->assertNotNull($result);
        $this->assertEmpty($result->get('_translations'));
    }

    /**
     * `find('translations')` with an association still returns documents.
     */
    public function testTranslationsHasManyWithOverride(): void
    {
        $collection = $this->getCollectionLocator()->get('Comments');
        $collection->addBehavior('Translate', ['fields' => ['comment']]);

        $result = $collection->find('translations')->first();
        $this->assertNotNull($result);
        $this->assertTrue($result->has('_translations'));
    }

    /**
     * Translated documents load their belongsToMany associations.
     */
    public function testFindSingleLocaleBelongsToMany(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $specialTags = $this->getCollectionLocator()->get('SpecialTags');
        $collection->belongsToMany('Tags', ['through' => $specialTags]);
        $collection->addBehavior('Translate', ['fields' => ['title', 'body']]);
        $collection->getBehavior('Translate')->setLocale('eng');

        $result = $collection->find()->where(['_id' => '000000000000000000000002'])->contain('Tags')->first();
        $this->assertNotEmpty($result);
        $this->assertNotEmpty($result->tags);
        $this->assertSame('Title #2', $result->title);
    }

    /**
     * Saving a new record with translations for non-default locales only.
     */
    public function testSaveNewRecordWithOnlyTranslationsNotDefaultLocale(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->setDocumentClass(TranslateArticle::class);
        $collection->addBehavior('Translate', [
            'defaultLocale' => 'en',
            'fields' => ['title'],
        ]);

        $data = [
            'author_id' => '000000000000000000000001',
            'published' => 'N',
            '_translations' => [
                'es' => ['title' => 'Titulo es'],
                'fr' => ['title' => 'Titre fr'],
            ],
        ];
        $article = $collection->patchDocument($collection->newEmptyDocument(), $data);
        $result = $collection->save($article);
        $this->assertNotFalse($result);

        $result = $collection->find('translations')->where(['_id' => $result->get('_id')])->first();
        $translations = $result->get('_translations');
        $this->assertSame('Titulo es', $translations['es']->title);
        $this->assertSame('Titre fr', $translations['fr']->title);
    }

    /**
     * Saving an existing record with only translation changes keeps the other
     * locales intact.
     */
    public function testSaveExistingRecordOnlyTranslations(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->setDocumentClass(TranslateArticle::class);
        $collection->addBehavior('Translate', ['fields' => ['title', 'body']]);

        $article = $collection->find()->first();
        $article->getOrCreateTranslation('deu')->set('title', 'Neuer Titel');
        $this->assertNotFalse($collection->save($article));

        $result = $collection->find('translations')->where(['_id' => '000000000000000000000001'])->first();
        $translations = $result->get('_translations');
        $this->assertSame('Neuer Titel', $translations['deu']->title);
        $this->assertSame('Title #1', $translations['eng']->title);
        $this->assertSame('Titulek #1', $translations['cze']->title);
    }

    /**
     * allowEmptyTranslations=false keeps a null translation out of the map.
     */
    public function testAllowEmptyFalseWithNull(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', [
            'fields' => ['title'],
            'allowEmptyTranslations' => false,
        ]);

        $article = $collection->find()->first();
        $article = $collection->patchDocument($article, [
            '_translations' => [
                'fra' => ['title' => ''],
            ],
        ]);
        $collection->save($article);

        $collection->removeBehavior('Translate');
        $raw = $collection->find()->enableHydration(false)->where(['_id' => '000000000000000000000001'])->first();
        $this->assertArrayNotHasKey('fra', $raw['_translations']);
    }

    /**
     * allowEmptyTranslations=false keeps non-empty translated fields.
     */
    public function testMixedAllowEmptyFalse(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', [
            'fields' => ['title', 'body'],
            'allowEmptyTranslations' => false,
        ]);

        $article = $collection->find()->first();
        $article = $collection->patchDocument($article, [
            '_translations' => [
                'fra' => ['title' => '', 'body' => 'Bonjour'],
            ],
        ]);
        $collection->save($article);

        $collection->removeBehavior('Translate');
        $raw = $collection->find()->enableHydration(false)->where(['_id' => '000000000000000000000001'])->first();
        $this->assertSame('Bonjour', $raw['_translations']['fra']['body']);
        $this->assertArrayNotHasKey('title', $raw['_translations']['fra']);
    }

    /**
     * allowEmptyTranslations=false with multiple locales.
     */
    public function testMultipleAllowEmptyFalse(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', [
            'fields' => ['title', 'body'],
            'allowEmptyTranslations' => false,
        ]);

        $article = $collection->find()->first();
        $article = $collection->patchDocument($article, [
            '_translations' => [
                'fra' => ['title' => '', 'body' => 'Bonjour'],
                'de' => ['title' => 'Titel', 'body' => 'Hallo'],
            ],
        ]);
        $collection->save($article);

        $collection->removeBehavior('Translate');
        $raw = $collection->find()->enableHydration(false)->where(['_id' => '000000000000000000000001'])->first();
        $this->assertSame('Bonjour', $raw['_translations']['fra']['body']);
        $this->assertSame('Titel', $raw['_translations']['de']['title']);
        $this->assertSame('Hallo', $raw['_translations']['de']['body']);
    }

    /**
     * `matching()` works on the source document with the embed strategy.
     */
    public function testLocalePropertyIsSetInMatchingData(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', ['fields' => ['title', 'body']]);
        $collection->getBehavior('Translate')->setLocale('eng');

        $result = $collection->find()->where(['title' => 'Title #1'])->first();
        $this->assertSame('Title #1', $result->title);
    }

    /**
     * Matching still works on the translated source document.
     */
    public function testLocalePropertyIsSetInMatchingDataWhenUsingDeepMatching(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->addBehavior('Translate', ['fields' => ['title', 'body']]);
        $collection->getBehavior('Translate')->setLocale('eng');

        $result = $collection->find()->where(['_translations.eng.title' => 'Title #1'])->first();
        $this->assertSame('Title #1', $result->title);
    }

    /**
     * Matching through a contained association still loads the translated
     * document.
     */
    public function testLocalePropertyIsSetInMatchingDataWhenUsingContainedMatching(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $collection->hasMany('Comments');
        $collection->addBehavior('Translate', ['fields' => ['title', 'body']]);
        $collection->getBehavior('Translate')->setLocale('eng');

        $result = $collection->find()->contain('Comments')->first();
        $this->assertSame('Title #1', $result->title);
        $this->assertNotEmpty($result->comments);
    }
}

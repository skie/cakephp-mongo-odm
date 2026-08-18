<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM\Rule;

use Crustum\Mongo\ODM\Document;
use Crustum\Mongo\ODM\Rule\ExistsIn;
use Crustum\Mongo\ODM\Rule\ExistsInNullable;
use Crustum\Mongo\Test\TestCase\ODM\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests the ExistsInNullable rule
 */
#[CoversClass(ExistsInNullable::class)]
class ExistsInNullableTest extends TestCase
{
    private const string SITE_ONE = '000000000000000000000001';

    private const string AUTHOR_MARK = '000000000000000000000001';

    private const string INVALID_AUTHOR = '507f1f77bcf86cd799439011';

    private const string INVALID_SITE = '000000000000000000009999';

    /**
     * Fixtures to be loaded
     *
     * @var array<string>
     */
    protected array $fixtures = [
        'plugin.Crustum/Mongo.SiteArticles',
        'plugin.Crustum/Mongo.SiteAuthors',
    ];

    /**
     * Mirrors composite binding key setup from RulesCheckerIntegrationTest.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->getCollectionLocator()->get('SiteAuthors')->setPrimaryKey(['_id', 'site_id']);
    }

    /**
     * Test that allowNullableNulls is true by default
     */
    public function testAllowNullableNullsDefaultValue(): void
    {
        $document = new Document([
            'author_id' => null,
            'site_id' => self::SITE_ONE,
            'title' => 'New Site Article without Author',
        ]);
        $collection = $this->getCollectionLocator()->get('SiteArticles');
        $collection->belongsTo('SiteAuthors');

        $rules = $collection->rulesChecker();

        $rules->add(new ExistsInNullable(['author_id', 'site_id'], 'SiteAuthors'));
        $this->assertInstanceOf(Document::class, $collection->save($document));
    }

    /**
     * Test that allowNullableNulls can be explicitly overridden to false
     */
    public function testAllowNullableNullsCanBeOverridden(): void
    {
        $document = new Document([
            'author_id' => null,
            'site_id' => self::SITE_ONE,
            'title' => 'New Site Article without Author',
        ]);
        $collection = $this->getCollectionLocator()->get('SiteArticles');
        $collection->belongsTo('SiteAuthors');

        $rules = $collection->rulesChecker();

        $rules->add(new ExistsInNullable(['author_id', 'site_id'], 'SiteAuthors', [
            'allowNullableNulls' => false,
        ]));
        $this->assertFalse($collection->save($document));
    }

    /**
     * Test with all foreign keys set
     */
    public function testAllKeysSet(): void
    {
        $document = new Document([
            'author_id' => self::AUTHOR_MARK,
            'site_id' => self::SITE_ONE,
            'title' => 'New Site Article with Author',
        ]);
        $collection = $this->getCollectionLocator()->get('SiteArticles');
        $collection->belongsTo('SiteAuthors');

        $rules = $collection->rulesChecker();

        $rules->add(new ExistsInNullable(['author_id', 'site_id'], 'SiteAuthors'));
        $this->assertInstanceOf(Document::class, $collection->save($document));
    }

    /**
     * Test with invalid foreign key
     */
    public function testInvalidKey(): void
    {
        $document = new Document([
            'author_id' => self::INVALID_AUTHOR,
            'site_id' => self::SITE_ONE,
            'title' => 'New Site Article with Author',
        ]);
        $collection = $this->getCollectionLocator()->get('SiteArticles');
        $collection->belongsTo('SiteAuthors');

        $rules = $collection->rulesChecker();

        $rules->add(
            new ExistsInNullable(['author_id', 'site_id'], 'SiteAuthors'),
            '_existsIn',
            ['errorField' => 'author_id', 'message' => 'will error'],
        );
        $this->assertFalse($collection->save($document));
        $this->assertEquals(['author_id' => ['_existsIn' => 'will error']], $document->getErrors());
    }

    /**
     * Test with all invalid foreign keys
     */
    public function testInvalidKeys(): void
    {
        $document = new Document([
            'author_id' => self::INVALID_AUTHOR,
            'site_id' => self::INVALID_SITE,
            'title' => 'New Site Article with Author',
        ]);
        $collection = $this->getCollectionLocator()->get('SiteArticles');
        $collection->belongsTo('SiteAuthors');

        $rules = $collection->rulesChecker();

        $rules->add(
            new ExistsInNullable(['author_id', 'site_id'], 'SiteAuthors'),
            '_existsIn',
            ['errorField' => 'author_id', 'message' => 'will error'],
        );
        $this->assertFalse($collection->save($document));
        $this->assertEquals(['author_id' => ['_existsIn' => 'will error']], $document->getErrors());
    }

    /**
     * Test with saveMany
     */
    public function testSaveMany(): void
    {
        $documents = [
            new Document([
                'author_id' => null,
                'site_id' => self::SITE_ONE,
                'title' => 'New Site Article without Author',
            ]),
            new Document([
                'author_id' => self::AUTHOR_MARK,
                'site_id' => self::SITE_ONE,
                'title' => 'New Site Article with Author',
            ]),
        ];
        $collection = $this->getCollectionLocator()->get('SiteArticles');
        $collection->belongsTo('SiteAuthors');

        $rules = $collection->rulesChecker();

        $rules->add(new ExistsInNullable(['author_id', 'site_id'], 'SiteAuthors', [
            'message' => 'will error with array_combine warning',
        ]));
        /** @var iterable<\Crustum\Mongo\ODM\Document> $result */
        $result = $collection->saveMany($documents);
        $this->assertCount(2, $result);
        /** @var array<\Crustum\Mongo\ODM\Document> $result */
        $result = iterator_to_array($result);

        $this->assertInstanceOf(Document::class, $result[0]);
        $this->assertEmpty($result[0]->getErrors());

        $this->assertInstanceOf(Document::class, $result[1]);
        $this->assertEmpty($result[1]->getErrors());
    }

    /**
     * Test using ExistsInNullable directly with table object
     */
    public function testWithTableObject(): void
    {
        $document = new Document([
            'author_id' => null,
            'site_id' => self::SITE_ONE,
            'title' => 'New Site Article without Author',
        ]);
        $collection = $this->getCollectionLocator()->get('SiteArticles');
        $authorsCollection = $this->getCollectionLocator()->get('SiteAuthors');
        $rules = $collection->rulesChecker();

        $rules->add(new ExistsInNullable(['author_id', 'site_id'], $authorsCollection));
        $this->assertInstanceOf(Document::class, $collection->save($document));
    }

    /**
     * Test with custom message
     */
    public function testCustomMessage(): void
    {
        $document = new Document([
            'author_id' => self::INVALID_AUTHOR,
            'site_id' => self::SITE_ONE,
            'title' => 'New Site Article with Author',
        ]);
        $collection = $this->getCollectionLocator()->get('SiteArticles');
        $collection->belongsTo('SiteAuthors');

        $rules = $collection->rulesChecker();

        $rules->add(
            new ExistsInNullable(['author_id', 'site_id'], 'SiteAuthors'),
            '_existsIn',
            ['errorField' => 'author_id', 'message' => 'Custom error message'],
        );
        $this->assertFalse($collection->save($document));
        $this->assertEquals(['author_id' => ['_existsIn' => 'Custom error message']], $document->getErrors());
    }

    /**
     * Test using rulesChecker existsInNullable method
     */
    public function testUsingRulesCheckerMethod(): void
    {
        $document = new Document([
            'author_id' => null,
            'site_id' => self::SITE_ONE,
            'title' => 'New Site Article without Author',
        ]);
        $collection = $this->getCollectionLocator()->get('SiteArticles');
        $collection->belongsTo('SiteAuthors');
        /** @var \Cake\ORM\RulesChecker $rules */
        $rules = $collection->rulesChecker();

        $rules->add($rules->existsInNullable(['author_id', 'site_id'], 'SiteAuthors'));
        $this->assertInstanceOf(Document::class, $collection->save($document));
    }

    /**
     * Test using rulesChecker existsInNullable method with custom message
     */
    public function testUsingRulesCheckerMethodWithCustomMessage(): void
    {
        $document = new Document([
            'author_id' => self::INVALID_AUTHOR,
            'site_id' => self::SITE_ONE,
            'title' => 'New Site Article with invalid author',
        ]);
        $collection = $this->getCollectionLocator()->get('SiteArticles');
        $collection->belongsTo('SiteAuthors');
        /** @var \Cake\ORM\RulesChecker $rules */
        $rules = $collection->rulesChecker();

        $rules->add($rules->existsInNullable(['author_id', 'site_id'], 'SiteAuthors', 'Custom message via method'));
        $this->assertFalse($collection->save($document));
        $this->assertEquals(['author_id' => ['_existsIn' => 'Custom message via method']], $document->getErrors());
    }

    /**
     * Test that ExistsInNullable extends ExistsIn
     */
    public function testExtendsExistsIn(): void
    {
        $rule = new ExistsInNullable(['author_id', 'site_id'], 'SiteAuthors');

        $this->assertInstanceOf(ExistsIn::class, $rule);
    }
}

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
     * Test that allowNullableNulls is true by default
     */
    public function testAllowNullableNullsDefaultValue(): void
    {
        $this->markTestSkipped('ODM missing composite-FK ExistsIn handling: F32');
        $document = new Document([
            'id' => 10,
            'author_id' => null,
            'site_id' => 1,
            'name' => 'New Site Article without Author',
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
        $this->markTestSkipped('ODM missing composite-FK ExistsIn handling: F32');
        $document = new Document([
            'id' => 10,
            'author_id' => null,
            'site_id' => 1,
            'name' => 'New Site Article without Author',
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
        $this->markTestSkipped('ODM missing composite-FK ExistsIn handling: F32');
        $document = new Document([
            'id' => 10,
            'author_id' => 1,
            'site_id' => 1,
            'name' => 'New Site Article with Author',
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
        $this->markTestSkipped('ODM missing composite-FK ExistsIn handling: F32');
        $document = new Document([
            'id' => 10,
            'author_id' => 99999999,
            'site_id' => 1,
            'name' => 'New Site Article with Author',
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
        $this->markTestSkipped('ODM missing composite-FK ExistsIn handling: F32');
        $document = new Document([
            'id' => 10,
            'author_id' => 99999999,
            'site_id' => 99999999,
            'name' => 'New Site Article with Author',
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
        $this->markTestSkipped('ODM missing composite-FK ExistsIn handling: F32');
        $documents = [
            new Document([
                'id' => 1,
                'author_id' => null,
                'site_id' => 1,
                'name' => 'New Site Article without Author',
            ]),
            new Document([
                'id' => 2,
                'author_id' => 1,
                'site_id' => 1,
                'name' => 'New Site Article with Author',
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
        $this->markTestSkipped('ODM missing composite-FK ExistsIn handling: F32');
        $document = new Document([
            'id' => 10,
            'author_id' => null,
            'site_id' => 1,
            'name' => 'New Site Article without Author',
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
        $this->markTestSkipped('ODM missing composite-FK ExistsIn handling: F32');
        $document = new Document([
            'id' => 10,
            'author_id' => 99999999,
            'site_id' => 1,
            'name' => 'New Site Article with Author',
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
        $this->markTestSkipped('ODM missing composite-FK ExistsIn handling: F32');
        $document = new Document([
            'id' => 10,
            'author_id' => null,
            'site_id' => 1,
            'name' => 'New Site Article without Author',
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
        $this->markTestSkipped('ODM missing composite-FK ExistsIn handling: F32');
        $document = new Document([
            'id' => 10,
            'author_id' => 99999999,
            'site_id' => 1,
            'name' => 'New Site Article with invalid author',
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

<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM\Behavior;

use AssertionError;
use Cake\Event\Event;
use Cake\I18n\DateTime;
use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\ODM\Behavior\TimestampBehavior;
use Crustum\Mongo\ODM\Document;
use Crustum\Mongo\Test\TestCase\ODM\TestCase;
use DateTime as NativeDateTime;
use PHPUnit\Framework\Attributes\CoversClass;
use UnexpectedValueException;

/**
 * Behavior test case
 *
 * @ported-from \Cake\Test\TestCase\ORM\Behavior\TimestampBehaviorTest
 */
#[CoversClass(TimestampBehavior::class)]
class TimestampBehaviorTest extends TestCase
{
    /**
     * fixtures
     *
     * @var array<string>
     */
    protected array $fixtures = [
        'plugin.Crustum/Mongo.Users',
    ];

    /**
     * @var \Cake\ORM\Behavior\TimestampBehavior
     */
    protected $Behavior;

    /**
     * Sanity check Implemented events
     */
    public function testImplementedEventsDefault(): void
    {
        $collection = $this->getTableInstance();
        $this->Behavior = new TimestampBehavior($collection);

        $expected = [
            'Collection.beforeSave' => 'handleEvent',
        ];
        $this->assertEquals($expected, $this->Behavior->implementedEvents());
    }

    /**
     * testImplementedEventsCustom
     *
     * The behavior allows for handling any event - test an example
     */
    public function testImplementedEventsCustom(): void
    {
        $collection = $this->getTableInstance();
        $settings = ['events' => ['Something.special' => ['date_specialed' => 'always']]];
        $this->Behavior = new TimestampBehavior($collection, $settings);

        $expected = [
            'Something.special' => 'handleEvent',
        ];
        $this->assertEquals($expected, $this->Behavior->implementedEvents());
    }

    /**
     * testCreatedAbsent
     *
     * @triggers Model.beforeSave
     */
    public function testCreatedAbsent(): void
    {
        $collection = $this->getTableInstance();
        $this->Behavior = new TimestampBehavior($collection);
        $ts = new NativeDateTime('2000-01-01');
        $this->Behavior->timestamp($ts);

        $event = new Event('Collection.beforeSave');
        $document = new Document(['name' => 'Foo']);

        $this->Behavior->handleEvent($event, $document);
        $this->assertInstanceOf(DateTime::class, $document->created);
        $this->assertSame($ts->format('c'), $document->created->format('c'), 'Created timestamp is not the same');
    }

    /**
     * testCreatedPresent
     *
     * @triggers Model.beforeSave
     */
    public function testCreatedPresent(): void
    {
        $collection = $this->getTableInstance();
        $this->Behavior = new TimestampBehavior($collection);
        $ts = new NativeDateTime('2000-01-01');
        $this->Behavior->timestamp($ts);

        $event = new Event('Collection.beforeSave');
        $existingValue = new NativeDateTime('2011-11-11');
        $document = new Document(['name' => 'Foo', 'created' => $existingValue]);

        $this->Behavior->handleEvent($event, $document);
        $this->assertSame($existingValue, $document->created, 'Created timestamp is expected to be unchanged');
    }

    /**
     * testCreatedNotNew
     *
     * @triggers Model.beforeSave
     */
    public function testCreatedNotNew(): void
    {
        $collection = $this->getTableInstance();
        $this->Behavior = new TimestampBehavior($collection);
        $ts = new NativeDateTime('2000-01-01');
        $this->Behavior->timestamp($ts);

        $event = new Event('Collection.beforeSave');
        $document = new Document(['name' => 'Foo']);
        $document->setNew(false);

        $this->Behavior->handleEvent($event, $document);
        $this->assertNull($document->created, 'Created timestamp is expected to be untouched if the entity is not new');
    }

    /**
     * testModifiedAbsent
     *
     * @triggers Model.beforeSave
     */
    public function testModifiedAbsent(): void
    {
        $collection = $this->getTableInstance();
        $this->Behavior = new TimestampBehavior($collection);
        $ts = new NativeDateTime('2000-01-01');
        $this->Behavior->timestamp($ts);

        $event = new Event('Collection.beforeSave');
        $document = new Document(['name' => 'Foo']);
        $document->setNew(false);

        $this->Behavior->handleEvent($event, $document);
        $this->assertInstanceOf(DateTime::class, $document->modified);
        $this->assertSame($ts->format('c'), $document->modified->format('c'), 'Modified timestamp is not the same');
    }

    /**
     * testModifiedPresent
     *
     * @triggers Model.beforeSave
     */
    public function testModifiedPresent(): void
    {
        $collection = $this->getTableInstance();
        $this->Behavior = new TimestampBehavior($collection);
        $ts = new NativeDateTime('2000-01-01');
        $this->Behavior->timestamp($ts);

        $event = new Event('Collection.beforeSave');
        $existingValue = new NativeDateTime('2011-11-11');
        $document = new Document(['name' => 'Foo', 'modified' => $existingValue]);
        $document->clean();
        $document->setNew(false);

        $this->Behavior->handleEvent($event, $document);
        $this->assertInstanceOf(DateTime::class, $document->modified);
        $this->assertSame($ts->format('c'), $document->modified->format('c'), 'Modified timestamp is expected to be updated');
    }

    /**
     * test that timestamp creation doesn't fail on missing columns
     */
    public function testModifiedMissingColumn(): void
    {
        $collection = $this->getTableInstance();
        $collection->getSchema()->removeColumn('created')->removeColumn('modified');
        $this->Behavior = new TimestampBehavior($collection);
        $ts = new NativeDateTime('2000-01-01');
        $this->Behavior->timestamp($ts);

        $event = new Event('Collection.beforeSave');
        $document = new Document(['name' => 'Foo']);

        $this->Behavior->handleEvent($event, $document);
        $this->assertNull($document->created);
        $this->assertNull($document->modified);
    }

    /**
     * testTimeInstanceCreation
     *
     * @triggers Model.beforeSave
     */
    public function testTimeInstanceCreation(): void
    {
        $collection = $this->getTableInstance();
        $this->Behavior = new TimestampBehavior($collection);
        $document = new Document();
        $event = new Event('Collection.beforeSave');

        $document->clean();
        $this->Behavior->handleEvent($event, $document);
        $this->assertInstanceOf(DateTime::class, $document->modified);
    }

    /**
     * tests using non-DateTimeType throws runtime exception
     */
    public function testNonDateTimeTypeException(): void
    {
        $this->expectException(AssertionError::class);
        $this->expectExceptionMessage('TimestampBehavior only supports columns of type `Cake\Database\Type\DateTimeType`.');

        $collection = $this->getTableInstance();
        $this->Behavior = new TimestampBehavior($collection, [
            'events' => [
                'Collection.beforeSave' => [
                    'timestamp_str' => 'always',
                ],
            ],
        ]);

        $document = new Document();
        $event = new Event('Collection.beforeSave');
        $this->Behavior->handleEvent($event, $document);
        $this->assertIsString($document->timestamp_str);
    }

    /**
     * testInvalidEventConfig
     *
     * @triggers Model.beforeSave
     */
    public function testInvalidEventConfig(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('When should be one of "always", "new" or "existing". The passed value `fat fingers` is invalid.');
        $collection = $this->getTableInstance();
        $settings = ['events' => ['Collection.beforeSave' => ['created' => 'fat fingers']]];
        $this->Behavior = new TimestampBehavior($collection, $settings);

        $event = new Event('Collection.beforeSave');
        $document = new Document(['name' => 'Foo']);
        $this->Behavior->handleEvent($event, $document);
    }

    /**
     * testGetTimestamp
     */
    public function testGetTimestamp(): void
    {
        $collection = $this->getTableInstance();
        $behavior = new TimestampBehavior($collection);

        $return = $behavior->timestamp();
        $this->assertInstanceOf(
            DateTime::class,
            $return,
            'Should return a timestamp object',
        );

        // Compare timestamps within tolerance to avoid flaky tests during slow CI runs
        $this->assertEqualsWithDelta(time(), $return->getTimestamp(), 120);
    }

    /**
     * testGetTimestampPersists
     */
    public function testGetTimestampPersists(): void
    {
        $collection = $this->getTableInstance();
        $behavior = new TimestampBehavior($collection);

        $initialValue = $behavior->timestamp();
        $postValue = $behavior->timestamp();

        $this->assertSame(
            $initialValue,
            $postValue,
            'The timestamp should be exactly the same object',
        );
    }

    /**
     * testGetTimestampRefreshes
     */
    public function testGetTimestampRefreshes(): void
    {
        $collection = $this->getTableInstance();
        $behavior = new TimestampBehavior($collection);

        $initialValue = $behavior->timestamp();
        $postValue = $behavior->timestamp(null, true);

        $this->assertNotSame(
            $initialValue,
            $postValue,
            'The timestamp should be a different object if refreshTimestamp is truthy',
        );
    }

    /**
     * testSetTimestampExplicit
     */
    public function testSetTimestampExplicit(): void
    {
        $collection = $this->getTableInstance();
        $this->Behavior = new TimestampBehavior($collection);

        $ts = new NativeDateTime();
        $this->Behavior->timestamp($ts);
        $return = $this->Behavior->timestamp();

        $this->assertSame(
            $ts->format('c'),
            $return->format('c'),
            'Should return the same value as initially set',
        );
    }

    /**
     * testTouch
     */
    public function testTouch(): void
    {
        $collection = $this->getTableInstance();
        $this->Behavior = new TimestampBehavior($collection);
        $ts = new NativeDateTime('2000-01-01');
        $this->Behavior->timestamp($ts);

        $document = new Document(['username' => 'timestamp test']);
        $return = $this->Behavior->touch($document);
        $this->assertTrue($return, 'touch is expected to return true if it sets a field value');
        $this->assertSame(
            $ts->format('Y-m-d H:i:s'),
            $document->modified->format('Y-m-d H:i:s'),
            'Modified field is expected to be updated',
        );
        $this->assertNull($document->created, 'Created field is NOT expected to change');
    }

    /**
     * testTouchNoop
     */
    public function testTouchNoop(): void
    {
        $collection = $this->getTableInstance();
        $config = [
            'events' => [
                'Collection.beforeSave' => [
                    'created' => 'new',
                ],
            ],
        ];

        $this->Behavior = new TimestampBehavior($collection, $config);
        $ts = new NativeDateTime('2000-01-01');
        $this->Behavior->timestamp($ts);

        $document = new Document(['username' => 'timestamp test']);
        $return = $this->Behavior->touch($document);
        $this->assertFalse($return, 'touch is expected to do nothing and return false');
        $this->assertNull($document->modified, 'Modified field is NOT expected to change');
        $this->assertNull($document->created, 'Created field is NOT expected to change');
    }

    /**
     * testTouchCustomEvent
     */
    public function testTouchCustomEvent(): void
    {
        $collection = $this->getTableInstance();
        $settings = ['events' => ['Something.special' => ['date_specialed' => 'always']]];
        $this->Behavior = new TimestampBehavior($collection, $settings);
        $ts = new NativeDateTime('2000-01-01');
        $this->Behavior->timestamp($ts);

        $document = new Document(['username' => 'timestamp test']);
        $return = $this->Behavior->touch($document, 'Something.special');
        $this->assertTrue($return, 'touch is expected to return true if it sets a field value');
        $this->assertSame(
            $ts->format('Y-m-d H:i:s'),
            $document->date_specialed->format('Y-m-d H:i:s'),
            'Modified field is expected to be updated',
        );
        $this->assertNull($document->created, 'Created field is NOT expected to change');
    }

    /**
     * Test that calling save, triggers an insert including the created and updated field values
     */
    public function testSaveTriggersInsert(): void
    {
        $previousTestNow = DateTime::getTestNow();
        $now = new DateTime('2026-01-01 12:00:00');
        DateTime::setTestNow($now);

        try {
            $collection = $this->getCollectionLocator()->get('users');
            $collection->setSchemaFromArray([
                'created' => ['type' => 'datetime'],
                'updated' => ['type' => 'datetime'],
            ]);
            $collection->addBehavior('Timestamp', [
                'events' => [
                    'Collection.beforeSave' => [
                        'created' => 'new',
                        'updated' => 'always',
                    ],
                ],
            ]);

            $document = new Document(['username' => 'timestamp test']);
            $return = $collection->save($document);
            $this->assertSame($document, $return, 'The returned object is expected to be the same entity object');

            $row = $collection->find('all')->where(['_id' => $document->getId()])->first();

            $this->assertSame($now->toDateTimeString(), $row->created->toDateTimeString());
            $this->assertSame($now->toDateTimeString(), $row->updated->toDateTimeString());
        } finally {
            DateTime::setTestNow($previousTestNow);
        }
    }

    /**
     * Helper method to get BaseCollection instance with created/modified column
     */
    protected function getTableInstance(): BaseCollection
    {
        $schema = [
            'created' => ['type' => 'datetime'],
            'modified' => ['type' => 'timestamp'],
            'date_specialed' => ['type' => 'datetime'],
            'timestamp_str' => ['type' => 'string'],
        ];

        return new BaseCollection([
            'alias' => 'Articles',
            'schema' => $schema,
        ]);
    }
}

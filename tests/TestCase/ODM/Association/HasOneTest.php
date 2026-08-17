<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM\Association;

use ArrayObject;
use Cake\Database\Exception\DatabaseException;
use Cake\Database\Expression\IdentifierExpression;
use Cake\Database\Expression\QueryExpression;
use Cake\Database\TypeMap;
use Cake\Event\Event;
use Crustum\Mongo\ODM\Association\HasOne;
use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\ODM\Document;
use Crustum\Mongo\ODM\Query\SelectQuery;
use Crustum\Mongo\Test\TestCase\ODM\TestCase;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests HasOne class
 */
#[CoversClass(HasOne::class)]
class HasOneTest extends TestCase
{
    /**
     * Fixtures to load
     *
     * @var array<string>
     */
    protected array $fixtures = ['plugin.Crustum/Mongo.Articles', 'plugin.Crustum/Mongo.Authors', 'plugin.Crustum/Mongo.NullableAuthors', 'plugin.Crustum/Mongo.Users', 'plugin.Crustum/Mongo.Profiles'];

    /**
     * @var \Crustum\Mongo\ODM\BaseCollection
     */
    protected $user;

    /**
     * @var \Crustum\Mongo\ODM\BaseCollection
     */
    protected $profile;

    /**
     * @var bool
     */
    protected $listenerCalled = false;

    /**
     * Set up
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->getCollectionLocator()->get('Users');
        $this->profile = $this->getCollectionLocator()->get('Profiles');
        $this->listenerCalled = false;
    }

    /**
     * Tests that setForeignKey() returns the correct configured value
     */
    public function testSetForeignKey(): void
    {
        $assoc = new HasOne('Profiles', $this->user);
        $this->assertSame('user_id', $assoc->getForeignKey());
        $this->assertEquals($assoc, $assoc->setForeignKey('another_key'));
        $this->assertSame('another_key', $assoc->getForeignKey());
    }

    /**
     * Tests that the default foreign key condition generation can be disabled.
     */
    public function testDisableForeignKey(): void
    {
        $collection = $this->getCollectionLocator()->get('Users');
        $assoc = $collection
            ->hasOne('Profiles')
            ->setForeignKey('user_id');

        $user = $collection->find()->contain(['Profiles'])->orderByAsc('Users._id')->first();
        $this->assertSame('mariano', $user->profile->first_name);

        $assoc
            ->setForeignKey(false)
            ->setConditions([
                'Profiles.first_name' => 'larry',
            ]);

        $user = $collection->find()->contain(['Profiles'])->orderByAsc('Users._id')->first();
        $this->assertSame('larry', $user->profile->first_name);
    }

    /**
     * Tests that the association reports it can be joined
     */
    public function testCanBeJoined(): void
    {
        $this->markTestSkipped('ODM has no SQL joins; testCanBeJoined is SQL-only (F25).');
        $assoc = new HasOne('Test', $this->user);
        $this->assertTrue($assoc->canBeJoined());
    }

    /**
     * Tests that the correct join and fields are attached to a query depending on
     * the association config
     */
    public function testAttachTo(): void
    {
        $this->markTestSkipped('ODM has no SQL joins; testAttachTo is SQL-only (F25).');
        $config = [
            'target' => $this->profile,
            'property' => 'profile',
            'joinType' => 'INNER',
            'conditions' => ['Profiles.is_active' => true],
        ];
        $association = new HasOne('Profiles', $this->user, $config);
        $query = $this->user->find();
        $association->attachTo($query);

        $results = $query->orderBy('Users._id')->toArray();
        $this->assertCount(1, $results, 'Only one record because of conditions & join type');
        $this->assertSame('masters', $results[0]->Profiles['last_name']);
    }

    /**
     * Tests that it is possible to avoid fields inclusion for the associated table
     */
    public function testAttachToNoFields(): void
    {
        $this->markTestSkipped('ODM has no SQL joins; testAttachToNoFields is SQL-only (F25).');
        $config = [
            'target' => $this->profile,
            'conditions' => ['Profiles.is_active' => true],
        ];
        $association = new HasOne('Profiles', $this->user, $config);
        $query = $this->user->find();
        $association->attachTo($query, ['includeFields' => false]);
        $this->assertEmpty($query->clause('select'));
    }

    /**
     * Tests that using hasOne with a table having a multi column primary
     * key will work if the foreign key is passed
     */
    public function testAttachToMultiPrimaryKey(): void
    {
        $this->markTestSkipped('ODM has no SQL joins; testAttachToMultiPrimaryKey is SQL-only (F25).');
        $selectTypeMap = new TypeMap([
            'Profiles._id' => 'integer',
            '_id' => 'integer',
            'Profiles.first_name' => 'string',
            'first_name' => 'string',
            'Profiles.user_id' => 'integer',
            'user_id' => 'integer',
            'Profiles__first_name' => 'string',
            'Profiles__user_id' => 'integer',
            'Profiles__id' => 'integer',
            'Profiles__last_name' => 'string',
            'Profiles.last_name' => 'string',
            'last_name' => 'string',
            'Profiles__is_active' => 'boolean',
            'Profiles.is_active' => 'boolean',
            'is_active' => 'boolean',
        ]);
        $config = [
            'target' => $this->profile,
            'conditions' => ['Profiles.is_active' => true],
            'foreignKey' => ['user_id', 'user_site_id'],
        ];

        $this->user->setPrimaryKey(['_id', 'site_id']);
        $association = new HasOne('Profiles', $this->user, $config);

        $query = new SelectQuery($this->user);
        $field1 = new IdentifierExpression('Profiles.user_id');
        $field2 = new IdentifierExpression('Profiles.user_site_id');
        $expected = [
            'Profiles' => [
                'conditions' => new QueryExpression([
                    'Profiles.is_active' => true,
                    ['Users._id' => $field1, 'Users.site_id' => $field2],
                ], $selectTypeMap),
                'type' => 'LEFT',
                'collection' => 'profiles',
                'alias' => 'Profiles',
            ],
        ];
        $association->attachTo($query);
        $this->assertEquals($expected, $query->clause('join'));
    }

    /**
     * Tests that using hasOne with a table having a multi column primary
     * key will work if the foreign key is passed
     */
    public function testAttachToMultiPrimaryKeyMismatch(): void
    {
        $this->markTestSkipped('ODM has no SQL joins; testAttachToMultiPrimaryKeyMismatch is SQL-only (F25).');
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Cannot match provided foreignKey for `Profiles`, got `(user_id)` but expected foreign key for `(id, site_id)`');
        $query = new SelectQuery($this->user);
        $config = [
            'target' => $this->profile,
            'conditions' => ['Profiles.is_active' => true],
        ];
        $this->user->setPrimaryKey(['_id', 'site_id']);
        $association = new HasOne('Profiles', $this->user, $config);
        $association->attachTo($query, ['includeFields' => false]);
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
            'profile' => ['twitter' => '@cakephp'],
        ]);

        $association = new HasOne('Profiles', $this->user, $config);
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
        $association = new HasOne('Thing', $this->user, $config);
        $this->assertSame('thing_placeholder', $association->getProperty());
    }

    /**
     * Test that plugin names are omitted from property()
     */
    public function testPropertyNoPlugin(): void
    {
        $config = [
            'target' => $this->profile,
        ];
        $association = new HasOne('Contacts.Profiles', $this->user, $config);
        $this->assertSame('profile', $association->getProperty());
    }

    /**
     * Tests that attaching an association to a query will trigger beforeFind
     * for the target table
     */
    public function testAttachToBeforeFind(): void
    {
        $config = [
            'target' => $this->profile,
        ];
        $query = $this->user->find();

        $this->listenerCalled = false;
        $this->profile->getEventManager()->on('Collection.beforeFind', function ($event, $query, $options, bool $primary): void {
            $this->listenerCalled = true;
            $this->assertInstanceOf(Event::class, $event);
            $this->assertInstanceOf(SelectQuery::class, $query);
            $this->assertInstanceOf('ArrayObject', $options);
            $this->assertFalse($primary);
        });
        $association = new HasOne('Profiles', $this->user, $config);
        $association->attachTo($query);
        $this->assertTrue($this->listenerCalled, 'beforeFind event not fired.');
    }

    /**
     * Tests that attaching an association to a query will trigger beforeFind
     * for the target table
     */
    public function testAttachToBeforeFindExtraOptions(): void
    {
        $config = [
            'target' => $this->profile,
        ];
        $this->listenerCalled = false;
        $opts = new ArrayObject(['something' => 'more']);
        $this->profile->getEventManager()->on(
            'Collection.beforeFind',
            function ($event, $query, $options, bool $primary) use ($opts): void {
                $this->listenerCalled = true;
                $this->assertInstanceOf(Event::class, $event);
                $this->assertInstanceOf(SelectQuery::class, $query);
                $this->assertEquals($options, $opts);
                $this->assertFalse($primary);
            },
        );
        $association = new HasOne('Profiles', $this->user, $config);
        $query = $this->user->find();
        $association->attachTo($query, ['queryBuilder' => fn($q) => $q->applyOptions(['something' => 'more'])]);
        $this->assertTrue($this->listenerCalled, 'Event not fired');
    }

    /**
     * Test cascading deletes.
     */
    public function testCascadeDelete(): void
    {
        $config = [
            'dependent' => true,
            'target' => $this->profile,
            'conditions' => ['Profiles.is_active' => true],
            'cascadeCallbacks' => false,
        ];
        $association = new HasOne('Profiles', $this->user, $config);

        $this->profile->getEventManager()->on('Collection.beforeDelete', function (): void {
            $this->fail('Callbacks should not be triggered when callbacks do not cascade.');
        });

        $document = new Document(['_id' => '000000000000000000000001']);
        $association->cascadeDelete($document);

        $query = $this->profile->find()->where(['user_id' => '000000000000000000000001']);
        $this->assertSame(1, $query->count(), 'Left non-matching row behind');

        $query = $this->profile->find()->where(['user_id' => '000000000000000000000003']);
        $this->assertSame(1, $query->count(), 'other records left behind');

        $user = new Document(['_id' => '000000000000000000000003']);
        $this->assertTrue($association->cascadeDelete($user));
        $query = $this->profile->find()->where(['user_id' => '000000000000000000000003']);
        $this->assertSame(0, $query->count(), 'Matching record was deleted.');
    }

    /**
     * Tests cascading deletes on entities with null binding and foreign key.
     */
    public function testCascadeDeleteNullBindingNullForeign(): void
    {
        $Articles = $this->getCollectionLocator()->get('Articles');
        $Authors = $this->getCollectionLocator()->get('NullableAuthors');

        $config = [
            'dependent' => true,
            'target' => $Articles,
            'bindingKey' => 'author_id',
            'foreignKey' => 'author_id',
            'cascadeCallbacks' => false,
        ];
        $association = $Authors->hasOne('Articles', $config);

        // create article with null foreign key
        $document = new Document(['author_id' => null, 'title' => 'this has no author', 'body' => 'I am abandoned', 'published' => 'N']);
        $Articles->save($document);

        // get author with null binding key
        $document = $Authors->get('000000000000000000000002', ...['contain' => 'Articles']);
        $this->assertNull($document->article);
        $this->assertTrue($association->cascadeDelete($document));

        $query = $Articles->find();
        $this->assertSame(4, $query->count(), 'No articles should be deleted');
    }

    /**
     * Test cascading delete with has one.
     */
    public function testCascadeDeleteCallbacks(): void
    {
        $config = [
            'dependent' => true,
            'target' => $this->profile,
            'conditions' => ['Profiles.is_active' => true],
            'cascadeCallbacks' => true,
        ];
        $association = new HasOne('Profiles', $this->user, $config);

        $user = new Document(['_id' => '000000000000000000000001']);
        $this->assertTrue($association->cascadeDelete($user));

        $query = $this->profile->find()->where(['user_id' => '000000000000000000000001']);
        $this->assertSame(1, $query->count(), 'Left non-matching row behind');

        $query = $this->profile->find()->where(['user_id' => '000000000000000000000003']);
        $this->assertSame(1, $query->count(), 'other records left behind');

        $user = new Document(['_id' => '000000000000000000000003']);
        $this->assertTrue($association->cascadeDelete($user));
        $query = $this->profile->find()->where(['user_id' => '000000000000000000000003']);
        $this->assertSame(0, $query->count(), 'Matching record was deleted.');
    }

    /**
     * Test cascading delete with a rule preventing deletion
     */
    public function testCascadeDeleteCallbacksRuleFailure(): void
    {
        $config = [
            'dependent' => true,
            'target' => $this->profile,
            'cascadeCallbacks' => true,
        ];
        $association = new HasOne('Profiles', $this->user, $config);
        $profiles = $association->getTarget();
        $profiles->getEventManager()->on('Collection.buildRules', function ($event, $rules): void {
            $rules->addDelete(fn(): false => false);
        });

        $user = new Document(['_id' => '000000000000000000000001']);
        $this->assertFalse($association->cascadeDelete($user));
        $matching = $profiles->find()
            ->where(['Profiles.user_id' => $user->getId()])
            ->all();
        $this->assertGreaterThan(0, count($matching));
    }
}

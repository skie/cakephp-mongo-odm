<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM\Association;

use ArrayObject;
use Cake\Database\Exception\DatabaseException;
use Cake\Event\Event;
use Crustum\Mongo\ODM\Association\BelongsTo;
use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\ODM\Document;
use Crustum\Mongo\ODM\Query\SelectQuery;
use Crustum\Mongo\Test\TestCase\ODM\TestCase;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests BelongsTo class
 *
 * @ported-from \Cake\Test\TestCase\ORM\Association\BelongsToTest
 */
#[CoversClass(BelongsTo::class)]
class BelongsToTest extends TestCase
{
    /**
     * Fixtures to use.
     *
     * @var array<string>
     */
    protected array $fixtures = ['plugin.Crustum/Mongo.Articles', 'plugin.Crustum/Mongo.Authors', 'plugin.Crustum/Mongo.Comments'];

    /**
     * @var \Crustum\Mongo\ODM\BaseCollection
     */
    protected $company;

    /**
     * @var \Crustum\Mongo\ODM\BaseCollection
     */
    protected $client;

    /**
     * Set up
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->getCollectionLocator()->get('Companies', [
            'schema' => [
                '_id' => ['type' => 'integer'],
                'company_name' => ['type' => 'string'],
                '_constraints' => [
                    'primary' => ['type' => 'primary', 'columns' => ['_id']],
                ],
            ],
        ]);
        $this->client = $this->getCollectionLocator()->get('Clients', [
            'schema' => [
                '_id' => ['type' => 'integer'],
                'client_name' => ['type' => 'string'],
                'company_id' => ['type' => 'integer'],
                '_constraints' => [
                    'primary' => ['type' => 'primary', 'columns' => ['_id']],
                ],
            ],
        ]);
    }

    /**
     * Test that foreignKey generation
     */
    public function testSetForeignKey(): void
    {
        $assoc = new BelongsTo('Companies', $this->client, [
            'target' => $this->company,
        ]);
        $this->assertSame('company_id', $assoc->getForeignKey());
        $this->assertSame($assoc, $assoc->setForeignKey('another_key'));
        $this->assertSame('another_key', $assoc->getForeignKey());
    }

    /**
     * Tests that the default foreign key condition generation can be disabled.
     */
    public function testDisableForeignKey(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles');
        $assoc = $collection
            ->belongsTo('Authors')
            ->setForeignKey('author_id');

        $article = $collection->find()->contain(['Authors'])->orderByAsc('Articles._id')->first();
        $this->assertSame('mariano', $article->author->name);

        $assoc
            ->setForeignKey(false)
            ->setConditions([
                'Authors.name' => 'larry',
            ]);

        $article = $collection->find()->contain(['Authors'])->orderByAsc('Articles._id')->first();
        $this->assertSame('larry', $article->author->name);
    }

    /**
     * Test that foreignKey generation ignores database names in target table.
     */
    public function testForeignKeyIgnoreDatabaseName(): void
    {
        $this->company->setCollection('schema.companies');
        $this->client->setCollection('schema.clients');
        $assoc = new BelongsTo('Companies', $this->client, [
            'target' => $this->company,
        ]);
        $this->assertSame('company_id', $assoc->getForeignKey());
    }

    /**
     * Tests that the association reports it can be joined
     */
    public function testCanBeJoined(): void
    {
        $assoc = new BelongsTo('Test', new BaseCollection());
        $this->assertTrue($assoc->canBeJoined());
    }

    /**
     * Tests that the alias set on associations is actually on the Document
     */
    public function testCustomAlias(): void
    {
        $collection = $this->getCollectionLocator()->get('Articles', [
            'className' => 'TestPlugin.Articles',
        ]);
        $collection->addAssociations([
            'belongsTo' => [
                'FooAuthors' => ['className' => 'TestPlugin.Authors', 'foreignKey' => 'author_id'],
            ],
        ]);
        $article = $collection->find()->contain(['FooAuthors'])->first();

        $this->assertTrue(isset($article->foo_author));
        $this->assertEquals($article->foo_author->name, 'mariano');
        $this->assertNull($article->Authors);
    }

    /**
     * Tests that attachTo registers the association as an in-pipeline lookup
     * load (the ODM analog of a SQL join) and that the lookup pipeline is
     * built from the association config.
     */
    public function testAttachTo(): void
    {
        $config = [
            'foreignKey' => 'company_id',
            'conditions' => ['Companies.is_active' => true],
        ];
        $association = $this->client->belongsTo('Companies', $config + ['target' => $this->company]);
        $query = $this->client->selectQuery();
        $association->attachTo($query);

        $contain = $query->getEagerLoader()->getContain();
        $this->assertArrayHasKey('Companies', $contain);
        $this->assertSame('lookup', $contain['Companies']['strategy'] ?? null);
        $this->assertSame('company_id', $contain['Companies']['foreignKey'] ?? null);

        // Attach and dispatch: the pipeline must carry a $lookup on the target.
        $query->getEagerLoader()->attachAssociations($query, $this->client);
        $pipeline = $query->clause('pipeline');
        $this->assertNotEmpty($pipeline, 'attachTo should append a lookup pipeline.');
        $lookups = array_values(array_filter($pipeline, fn(array $stage): bool => isset($stage['$lookup'])));
        $this->assertNotEmpty($lookups, 'Pipeline should contain a $lookup stage.');
        $this->assertSame('companies', $lookups[0]['$lookup']['from'] ?? null);
        $this->assertSame('company_id', $lookups[0]['$lookup']['localField'] ?? null);
        $this->assertSame('_id', $lookups[0]['$lookup']['foreignField'] ?? null);
        $this->assertSame('company', $lookups[0]['$lookup']['as'] ?? null);
    }

    /**
     * Tests that it is possible to avoid fields inclusion for the associated table
     */
    public function testAttachToNoFields(): void
    {
        $config = [
            'target' => $this->company,
            'conditions' => ['Companies.is_active' => true],
        ];
        $query = $this->client->selectQuery();
        $association = $this->client->belongsTo('Companies', $config);
        $association->attachTo($query, ['fields' => false]);

        $contain = $query->getEagerLoader()->getContain();
        $this->assertArrayHasKey('Companies', $contain);
        $this->assertSame([], $contain['Companies']['fields'] ?? null, 'fields should not be added.');
    }

    /**
     * Tests that attachTo end-to-end loads the associated document through the
     * lookup pipeline into the association property.
     */
    public function testAttachToEndToEnd(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $association = $articles->belongsTo('Authors');

        $query = $articles->find()
            ->where(['Articles._id' => '000000000000000000000001'])
            ->contain('Authors');
        $association->attachTo($query);

        $result = $query->firstOrFail();
        $this->assertNotEmpty($result->author);
        $this->assertSame('mariano', $result->author->name);
    }

    /**
     * Tests that using belongsto with a table having a multi column primary
     * key will work if the foreign key is passed
     */
    public function testAttachToMultiPrimaryKey(): void
    {
        $this->company->setPrimaryKey(['_id', 'tenant_id']);
        $config = [
            'foreignKey' => ['company_id', 'company_tenant_id'],
            'target' => $this->company,
            'conditions' => ['Companies.is_active' => true],
        ];
        $association = new BelongsTo('Companies', $this->client, $config);
        $query = $this->client->selectQuery();
        $association->attachTo($query);
        $query->getEagerLoader()->attachAssociations($query, $this->client);

        $this->assertQueryLookupStage($query, [
            'from' => 'companies',
            'as' => 'company',
            'let' => [
                'bindingValue0' => '$company_id',
                'bindingValue1' => '$company_tenant_id',
            ],
            'pipeline' => [
                [
                    '$match' => [
                        '$expr' => [
                            '$and' => [
                                ['$ne' => ['$$bindingValue0', null]],
                                ['$eq' => ['$_id', '$$bindingValue0']],
                                ['$ne' => ['$$bindingValue1', null]],
                                ['$eq' => ['$tenant_id', '$$bindingValue1']],
                            ],
                        ],
                    ],
                ],
                ['$match' => ['is_active' => true]],
            ],
        ]);
    }

    /**
     * Tests that using belongsto with a table having a multi column primary
     * key will work if the foreign key is passed
     */
    public function testAttachToMultiPrimaryKeyMismatch(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Cannot match provided foreignKey for `Companies`, got `(company_id)` but expected foreign key for `(_id, tenant_id)`');
        $this->company->setPrimaryKey(['_id', 'tenant_id']);
        $query = $this->client->selectQuery();
        $config = [
            'foreignKey' => 'company_id',
            'target' => $this->company,
            'conditions' => ['Companies.is_active' => true],
        ];
        $association = new BelongsTo('Companies', $this->client, $config);
        $association->attachTo($query);
    }

    /**
     * Test the cascading delete of BelongsTo.
     */
    public function testCascadeDelete(): void
    {
        /** @var \Crustum\Mongo\ODM\BaseCollection&\Mockery\MockInterface $mock */
        $mock = Mockery::mock(BaseCollection::class);
        $config = [
            'target' => $mock,
        ];
        $mock->shouldReceive('find')->never();
        $mock->shouldReceive('delete')->never();

        $association = new BelongsTo('Companies', $this->client, $config);
        $document = new Document(['company_name' => 'CakePHP', '_id' => '000000000000000000000001']);
        $this->assertTrue($association->cascadeDelete($document));
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
            'title' => 'A Title',
            'body' => 'A body',
            'author' => ['name' => 'Jose'],
        ]);

        $association = new BelongsTo('Authors', $this->client, $config);
        $result = $association->saveAssociated($document);
        $this->assertSame($result, $document);
        $this->assertNull($document->author_id);

        $spy->shouldNotHaveReceived('saveAssociated');
    }

    /**
     * Tests that property is being set using the constructor options.
     */
    public function testPropertyOption(): void
    {
        $config = ['propertyName' => 'thing_placeholder'];
        $association = new BelongsTo('Thing', $this->client, $config);
        $this->assertSame('thing_placeholder', $association->getProperty());
    }

    /**
     * Test that plugin names are omitted from property()
     */
    public function testPropertyNoPlugin(): void
    {
        $config = [
            'target' => $this->company,
        ];
        $association = new BelongsTo('Contacts.Companies', $this->client, $config);
        $this->assertSame('company', $association->getProperty());
    }

    /**
     * Tests that attaching an association to a query will trigger beforeFind
     * for the target table
     */
    public function testAttachToBeforeFind(): void
    {
        $config = [
            'target' => $this->company,
        ];
        $called = false;
        $this->company->getEventManager()->on('Collection.beforeFind', function ($event, $query, $options) use (&$called): void {
            $this->assertInstanceOf(Event::class, $event);
            $this->assertInstanceOf(SelectQuery::class, $query);
            $this->assertInstanceOf(ArrayObject::class, $options);
            $called = true;
        });
        $association = new BelongsTo('Companies', $this->client, $config);
        $association->attachTo($this->client->selectQuery());
        $this->assertTrue($called, 'Listener should be called.');
    }

    /**
     * Tests that attaching an association to a query will trigger beforeFind
     * for the target table
     */
    public function testAttachToBeforeFindExtraOptions(): void
    {
        $config = [
            'target' => $this->company,
        ];
        $called = false;
        $this->company->getEventManager()->on('Collection.beforeFind', function ($event, $query, $options) use (&$called): void {
            $this->assertInstanceOf(ArrayObject::class, $options);
            $this->assertSame('more', $options['something']);
            $called = true;
        });
        $association = new BelongsTo('Companies', $this->client, $config);
        $query = $this->client->selectQuery();
        $association->attachTo($query, ['queryBuilder' => fn($q) => $q->applyOptions(['something' => 'more'])]);
        $this->assertTrue($called, 'Listener should be called.');
    }

    /**
     * Test that failing to add the foreignKey to the list of fields will
     * still attach associated data.
     */
    public function testAttachToNoFieldsSelected(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $articles->belongsTo('Authors');

        $query = $articles->find()
            ->select(['Authors.name'])
            ->where(['Articles._id' => '000000000000000000000001'])
            ->contain('Authors');
        $result = $query->firstOrFail();

        $this->assertNotEmpty($result->author);
        $this->assertSame('mariano', $result->author->name);
        $this->assertSame(['author'], array_keys($result->toArray()), 'No other properties included.');
    }

    /**
     * ODM auto-adds the belongsTo foreign key when it is omitted from select().
     *
     * Cake JOIN strategy throws if the FK is not selected; ODM injects it so
     * the external load still works.
     */
    public function testAttachToNoForeignKeySelect(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $articles->belongsTo('Authors')->setStrategy('select');

        $query = $articles->find()
            ->select(['title', 'author_id'])
            ->where(['_id' => '000000000000000000000001'])
            ->contain('Authors');
        $result = $query->firstOrFail();
        $this->assertNotEmpty($result->author);
        $this->assertSame('000000000000000000000001', $result->author->getId());

        $query = $articles->find()
            ->select(['title'])
            ->where(['_id' => '000000000000000000000001'])
            ->contain('Authors');
        $result = $query->firstOrFail();
        $this->assertNotEmpty($result->author);
        $this->assertSame('000000000000000000000001', $result->author->getId());
        $this->assertContains('author_id', $query->getAutoSelectedKeys());
    }

    /**
     * Test that formatResults in a joined association finder doesn't dirty
     * the root entity.
     */
    public function testAttachToFormatResultsNoDirtyResults(): void
    {
        $this->setAppNamespace('TestApp');
        $articles = $this->getCollectionLocator()->get('Articles');
        $articles->associations()->get('Authors')
            ->setFinder('formatted');

        $query = $articles->find()
            ->where(['_id' => '000000000000000000000001'])
            ->contain('Authors');
        $result = $query->firstOrFail();

        $this->assertNotEmpty($result->author);
        $this->assertNotEmpty($result->author->formatted);
        $this->assertFalse($result->isDirty(), 'Record should be clean as it was pulled from the db.');
    }
}

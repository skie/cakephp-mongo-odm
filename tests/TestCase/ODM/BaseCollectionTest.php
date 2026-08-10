<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM;

use ArrayObject;
use AssertionError;
use BadMethodCallException;
use Cake\Collection\Collection;
use Cake\Core\Exception\CakeException;
use Cake\Database\Connection;
use Cake\Database\Driver\Sqlserver;
use Cake\Database\Exception\DatabaseException;
use Crustum\Mongo\Database\Expression\ComparisonExpression;
use Cake\Database\Expression\QueryExpression;
use Cake\Database\Schema\TableSchema;
use Cake\Database\StatementInterface;
use Cake\Database\TypeMap;
use Cake\Datasource\ConnectionManager;
use Cake\Datasource\EntityInterface;
use Cake\Datasource\RepositoryInterface;
use Cake\Datasource\Exception\InvalidPrimaryKeyException;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\Event\EventInterface;
use Cake\Event\EventManager;
use Cake\I18n\DateTime;
use Crustum\Mongo\ODM\Association\BelongsTo;
use Crustum\Mongo\ODM\Association\BelongsToMany;
use Crustum\Mongo\ODM\Association\HasMany;
use Crustum\Mongo\ODM\Association\HasOne;
use Crustum\Mongo\ODM\AssociationCollection;
use Crustum\Mongo\ODM\BehaviorRegistry;
use Crustum\Mongo\ODM\Document;
use Crustum\Mongo\ODM\Exception\MissingBehaviorException;
use Crustum\Mongo\ODM\Exception\MissingDocumentException;
use Crustum\Mongo\ODM\Exception\PersistenceFailedException;
use Crustum\Mongo\ODM\Marshaller;
use Crustum\Mongo\ODM\Query\DeleteQuery;
use Crustum\Mongo\ODM\Query\InsertQuery;
use Crustum\Mongo\ODM\Query\SelectQuery;
use Crustum\Mongo\ODM\Query\UpdateQuery;
use Crustum\Mongo\ODM\ResultSet;
use Crustum\Mongo\ODM\RulesChecker;
use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\ODM\CollectionRegistry;
use Crustum\Mongo\Test\TestCase\ODM\TestCase;
use Cake\Utility\Hash;
use Cake\Validation\Validator;
use Exception;
use InvalidArgumentException;
use Mockery;
use PDOException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use TestApp\Model\Document\Article;
use TestApp\Model\Document\ArticlesTag;
use TestApp\Model\Document\Author;
use TestApp\Model\Document\ProtectedEntity;
use TestApp\Model\Document\Tag;
use TestApp\Model\Document\VirtualUser;
use TestApp\Model\Collection\ArticlesCollection;
use TestApp\Model\Collection\UsersCollection;
use TestPlugin\Model\Collection\CommentsCollection;

/**
 * Tests BaseCollection class
 */
#[AllowMockObjectsWithoutExpectations]
class BaseCollectionTest extends TestCase
{
    /**
     * @var string[]
     */
    protected array $fixtures = [
        'plugin.Crustum/Mongo.Articles',
        'plugin.Crustum/Mongo.Tags',
        'plugin.Crustum/Mongo.ArticlesTags',
        'plugin.Crustum/Mongo.Authors',
        'plugin.Crustum/Mongo.Categories',
        'plugin.Crustum/Mongo.Comments',
        'plugin.Crustum/Mongo.Sections',
        'plugin.Crustum/Mongo.SectionsMembers',
        'plugin.Crustum/Mongo.Members',
        'plugin.Crustum/Mongo.PolymorphicTagged',
        'plugin.Crustum/Mongo.SiteArticles',
        'plugin.Crustum/Mongo.Users',
    ];

    /**
     * Handy variable containing the next primary key that will be inserted in the
     * users table
     *
     * @var int
     */
    protected static $nextUserId = 5;

    /**
     * @var \Cake\Datasource\ConnectionInterface
     */
    protected $connection;

    /**
     * @var \Cake\Database\TypeMap
     */
    protected $usersTypeMap;

    /**
     * @var \Cake\Database\TypeMap
     */
    protected $articlesTypeMap;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = ConnectionManager::get('test_mongo');
        static::setAppNamespace();

        $this->usersTypeMap = new TypeMap([
            'Users.id' => 'integer',
            'id' => 'integer',
            'Users__id' => 'integer',
            'Users.username' => 'string',
            'Users__username' => 'string',
            'username' => 'string',
            'Users.password' => 'string',
            'Users__password' => 'string',
            'password' => 'string',
            'Users.created' => 'timestamp',
            'Users__created' => 'timestamp',
            'created' => 'timestamp',
            'Users.updated' => 'timestamp',
            'Users__updated' => 'timestamp',
            'updated' => 'timestamp',
        ]);

        $config = $this->connection->config();
        if (str_contains($config['driver'], 'Postgres')) {
            $this->usersTypeMap = new TypeMap([
                'Users.id' => 'integer',
                'id' => 'integer',
                'Users__id' => 'integer',
                'Users.username' => 'string',
                'Users__username' => 'string',
                'username' => 'string',
                'Users.password' => 'string',
                'Users__password' => 'string',
                'password' => 'string',
                'Users.created' => 'timestampfractional',
                'Users__created' => 'timestampfractional',
                'created' => 'timestampfractional',
                'Users.updated' => 'timestampfractional',
                'Users__updated' => 'timestampfractional',
                'updated' => 'timestampfractional',
            ]);
        } elseif (str_contains($config['driver'], 'Sqlserver')) {
            $this->usersTypeMap = new TypeMap([
                'Users.id' => 'integer',
                'id' => 'integer',
                'Users__id' => 'integer',
                'Users.username' => 'string',
                'Users__username' => 'string',
                'username' => 'string',
                'Users.password' => 'string',
                'Users__password' => 'string',
                'password' => 'string',
                'Users.created' => 'datetimefractional',
                'Users__created' => 'datetimefractional',
                'created' => 'datetimefractional',
                'Users.updated' => 'datetimefractional',
                'Users__updated' => 'datetimefractional',
                'updated' => 'datetimefractional',
            ]);
        }

        $this->articlesTypeMap = new TypeMap([
            'Articles.id' => 'integer',
            'Articles__id' => 'integer',
            'id' => 'integer',
            'Articles.title' => 'string',
            'Articles__title' => 'string',
            'title' => 'string',
            'Articles.author_id' => 'integer',
            'Articles__author_id' => 'integer',
            'author_id' => 'integer',
            'Articles.body' => 'text',
            'Articles__body' => 'text',
            'body' => 'text',
            'Articles.published' => 'string',
            'Articles__published' => 'string',
            'published' => 'string',
        ]);
    }

    /**
     * teardown method
     */
    protected function tearDown(): void
    {
        parent::tearDown();
        $this->clearPlugins();
    }

    /**
     * Tests query creation wrappers.
     */
    public function testTableQuery(): void
    {
        $table = new BaseCollection(['collection' => 'users']);

        $query = $table->query();
        $this->assertEquals('users', $query->getRepository()->getCollection());

        $query = $table->selectQuery();
        $this->assertEquals('users', $query->getRepository()->getCollection());

        $query = $table->subquery();
        $this->assertEquals('users', $query->getRepository()->getCollection());
    }

    /**
     * Tests subquery() returns a usable select query.
     */
    public function testSubqueryAliasing(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $subquery = $articles->subquery();

        $subquery->select('Articles.field1');
        $this->assertSame('articles', $subquery->getRepository()->getCollection());
    }

    /**
     * Tests subquery() in where clause.
     */
    public function testSubqueryWhereClause(): void
    {
        $authorIds = $this->getCollectionLocator()->get('Authors')->subquery()
            ->select(['_id'])
            ->where(['name' => 'mariano'])
            ->all()
            ->toList();

        $query = $this->getCollectionLocator()->get('Articles')->find()
            ->where(['author_id IN' => array_column($authorIds, '_id')])
            ->orderBy(['_id' => 'ASC']);

        $results = $query->all()->toList();
        $this->assertCount(2, $results);
        $this->assertEquals(
            ['000000000000000000000001', '000000000000000000000003'],
            array_column($results, '_id'),
        );
    }

    /**
     * Tests subquery() in join clause.
     */
    public function testSubqueryJoinClause(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $authors = $this->getCollectionLocator()->get('Authors');

        $counts = [];
        foreach ($authors->find()->all() as $author) {
            $counts[(string)$author->getId()] = $articles->find()
                ->where(['author_id' => $author->getId()])
                ->count();
        }

        $this->assertSame(2, $counts['000000000000000000000001']);
    }

    /**
     * Tests the table method
     */
    public function testTableMethod(): void
    {
        $table = new BaseCollection(['collection' => 'users']);
        $this->assertSame('users', $table->getCollection());

        $table = new UsersCollection();
        $this->assertSame('users', $table->getCollection());

        /** @var \Crustum\Mongo\ODM\BaseCollection|\PHPUnit\Framework\MockObject\MockObject $table */
        $table = $this->getMockBuilder(BaseCollection::class)
            ->onlyMethods(['find'])
            ->setMockClassName('SpecialThingsCollection')
            ->getMock();
        $this->assertSame('special_things', $table->getCollection());

        $table = new BaseCollection(['alias' => 'LoveBoats']);
        $this->assertSame('love_boats', $table->getCollection());

        $table->setCollection('other');
        $this->assertSame('other', $table->getCollection());

        $table->setCollection('database.other');
        $this->assertSame('database.other', $table->getCollection());
    }

    /**
     * Tests the setAlias method
     */
    public function testSetAlias(): void
    {
        $table = new BaseCollection(['alias' => 'users']);
        $this->assertSame('users', $table->getAlias());

        $table = new BaseCollection(['collection' => 'stuffs']);
        $this->assertSame('stuffs', $table->getAlias());

        $table = new UsersCollection();
        $this->assertSame('Users', $table->getAlias());

        /** @var \Crustum\Mongo\ODM\BaseCollection|\PHPUnit\Framework\MockObject\MockObject $table */
        $table = $this->getMockBuilder(BaseCollection::class)
            ->onlyMethods(['find'])
            ->setMockClassName('SpecialThingCollection')
            ->getMock();
        $this->assertSame('SpecialThing', $table->getAlias());

        $table->setAlias('AnotherOne');
        $this->assertSame('AnotherOne', $table->getAlias());
    }

    public function testGetAliasException(): void
    {
        $this->expectException(CakeException::class);
        $this->expectExceptionMessage('You must specify either the `alias` or the `table` option for the constructor.');

        $table = new BaseCollection();
        $table->getAlias();
    }

    public function testGetTableException(): void
    {
        $this->expectException(CakeException::class);
        $this->expectExceptionMessage('You must specify either the `alias` or the `table` option for the constructor.');

        $table = new BaseCollection();
        $table->getCollection();
    }

    /**
     * Test that aliasField() works.
     */
    public function testAliasField(): void
    {
        $table = new BaseCollection(['alias' => 'Users']);
        $this->assertSame('Users.id', $table->aliasField('id'));

        $this->assertSame('Users.id', $table->aliasField('Users.id'));
    }

    /**
     * Tests setConnection method
     */
    public function testSetConnection(): void
    {
        $table = new BaseCollection(['collection' => 'users']);
        $this->assertSame($this->connection, $table->getConnection());
        $this->assertSame($table, $table->setConnection($this->connection));
        $this->assertSame($this->connection, $table->getConnection());
    }

    /**
     * Tests primaryKey method
     */
    public function testSetPrimaryKey(): void
    {
        $table = new BaseCollection([
            'collection' => 'users',
            'schema' => [
                'id' => ['type' => 'integer'],
                '_constraints' => ['primary' => ['type' => 'primary', 'columns' => ['id']]],
            ],
        ]);
        $this->assertSame('id', $table->getPrimaryKey());
        $this->assertSame($table, $table->setPrimaryKey('thingID'));
        $this->assertSame('thingID', $table->getPrimaryKey());

        $table->setPrimaryKey(['thingID', 'user_id']);
        $this->assertEquals(['thingID', 'user_id'], $table->getPrimaryKey());
    }

    /**
     * Tests that name will be selected as a displayField
     */
    public function testDisplayFieldName(): void
    {
        $table = new BaseCollection([
            'collection' => 'users',
            'schema' => [
                'foo' => ['type' => 'string'],
                'name' => ['type' => 'string'],
            ],
        ]);
        $this->assertSame('name', $table->getDisplayField());
    }

    /**
     * Tests that title will be selected as a displayField
     */
    public function testDisplayFieldTitle(): void
    {
        $table = new BaseCollection([
            'collection' => 'users',
            'schema' => [
                'foo' => ['type' => 'string'],
                'title' => ['type' => 'string'],
            ],
        ]);
        $this->assertSame('title', $table->getDisplayField());
    }

    /**
     * Tests that label will be selected as a displayField
     */
    public function testDisplayFieldLabel(): void
    {
        $table = new BaseCollection([
            'collection' => 'users',
            'schema' => [
                'foo' => ['type' => 'string'],
                'label' => ['type' => 'string'],
            ],
        ]);
        $this->assertSame('label', $table->getDisplayField());
    }

    /**
     * Tests that displayField will fallback to first *_name field
     */
    public function testDisplayNameFallback(): void
    {
        $table = new BaseCollection([
            'collection' => 'users',
            'schema' => [
                'id' => ['type' => 'integer'],
                'custom_title' => ['type' => 'string'],
                '_constraints' => ['primary' => ['type' => 'primary', 'columns' => ['id']]],
            ],
        ]);
        $this->assertSame('custom_title', $table->getDisplayField());

        $table = new BaseCollection([
            'collection' => 'users',
            'schema' => [
                'id' => ['type' => 'integer'],
                'name' => ['type' => 'string'],
                'custom_title' => ['type' => 'string'],
                '_constraints' => ['primary' => ['type' => 'primary', 'columns' => ['id']]],
            ],
        ]);
        $this->assertSame('name', $table->getDisplayField());

        $table = new BaseCollection([
            'collection' => 'users',
            'schema' => [
                'id' => ['type' => 'integer'],
                'title_id' => ['type' => 'integer'],
                'custom_name' => ['type' => 'string'],
                '_constraints' => ['primary' => ['type' => 'primary', 'columns' => ['id']]],
            ],
        ]);
        $this->assertSame('custom_name', $table->getDisplayField());

        $table = new BaseCollection([
            'collection' => 'users',
            'schema' => [
                'id' => ['type' => 'integer'],
                'nullable_title' => ['type' => 'string', 'null' => true],
                'custom_name' => ['type' => 'string'],
                '_constraints' => ['primary' => ['type' => 'primary', 'columns' => ['id']]],
            ],
        ]);
        $this->assertSame('custom_name', $table->getDisplayField());

        $table = new BaseCollection([
            'collection' => 'users',
            'schema' => [
                'id' => ['type' => 'integer'],
                'nullable_title' => ['type' => 'string', 'null' => true],
                'password' => ['type' => 'string'],
                'user_secret' => ['type' => 'string'],
                'api_token' => ['type' => 'string'],
                '_constraints' => ['primary' => ['type' => 'primary', 'columns' => ['id']]],
            ],
        ]);
        $this->assertSame('_id', $table->getDisplayField());
    }

    /**
     * Tests that no displayField will fallback to primary key
     */
    public function testDisplayIdFallback(): void
    {
        $table = new BaseCollection([
            'collection' => 'users',
            'schema' => [
                'id' => ['type' => 'string'],
                'foo' => ['type' => 'string'],
                '_constraints' => ['primary' => ['type' => 'primary', 'columns' => ['id']]],
            ],
        ]);
        $this->assertSame('id', $table->getDisplayField());

        $table = $this->getCollectionLocator()->get('ArticlesTags');
        $this->assertSame('_id', $table->getDisplayField());
    }

    /**
     * Tests that displayField can be changed
     */
    public function testDisplaySet(): void
    {
        $table = new BaseCollection([
            'collection' => 'users',
            'schema' => [
                'id' => ['type' => 'string'],
                'foo' => ['type' => 'string'],
                '_constraints' => ['primary' => ['type' => 'primary', 'columns' => ['id']]],
            ],
        ]);
        $this->assertSame('id', $table->getDisplayField());
        $table->setDisplayField('foo');
        $this->assertSame('foo', $table->getDisplayField());
    }

    /**
     * Tests schema method
     */
    public function testSetSchema(): void
    {
        $schema = $this->connection->getSchemaCollection()->describe('users');
        $table = new BaseCollection([
            'collection' => 'users',
            'connection' => $this->connection,
        ]);
        $this->assertEquals($schema, $table->getSchema());

        $table = new BaseCollection(['collection' => 'stuff']);
        $table->setSchema($schema);
        $this->assertSame($schema, $table->getSchema());

        $table = new BaseCollection(['collection' => 'another']);
        $schema = ['id' => ['type' => 'integer']];
        $table->setSchema($schema);
        $this->assertEquals(
            new TableSchema('another', $schema),
            $table->getSchema(),
        );
    }

    /**
     * Tests schema method with long identifiers
     */
    public function testSetSchemaLongIdentifiers(): void
    {
        $schema = new TableSchema('long_identifiers', [
            'this_is_invalid_because_it_is_very_very_very_long' => [
                'type' => 'string',
            ],
        ]);
        $table = new BaseCollection([
            'collection' => 'very_long_alias_name',
            'connection' => $this->connection,
        ]);

        $maxAlias = $this->connection->getDriver()->getMaxAliasLength();
        if ($maxAlias && $maxAlias < 72) {
            $nameLength = $maxAlias - 2;
            $this->expectException(DatabaseException::class);
            $this->expectExceptionMessage(
                'ORM queries generate field aliases using the table name/alias and column name. ' .
                "The table alias `very_long_alias_name` and column `this_is_invalid_because_it_is_very_very_very_long` create an alias longer than ({$nameLength}). " .
                'You must change the table schema in the database and shorten either the table or column ' .
                'identifier so they fit within the database alias limits.',
            );
        }
        $this->assertNotNull($table->setSchema($schema));
    }

    public function testSchemaTypeOverrideInInitialize(): void
    {
        $table = new class (['alias' => 'Users', 'collection' => 'users', 'connection' => $this->connection]) extends BaseCollection {
            public function initialize(array $config): void
            {
                $this->getSchema()->setColumnType('username', 'foobar');
            }
        };

        $result = $table->getSchema();
        $this->assertSame('foobar', $result->getColumnType('username'));
    }

    /**
     * Tests that all fields for a table are added by default in a find when no
     * other fields are specified
     */
    public function testFindAllNoFieldsAndNoHydration(): void
    {
        $table = new BaseCollection([
            'collection' => 'users',
            'connection' => $this->connection,
        ]);
        $results = $table
            ->find('all')
            ->where(['id IN' => [1, 2]])
            ->orderBy('id')
            ->enableHydration(false)
            ->toArray();
        $expected = [
            [
                '_id' => '000000000000000000000001',
                'username' => 'mariano',
                'password' => '$2a$10$u05j8FjsvLBNdfhBhc21LOuVMpzpabVXQ9OpC2wO3pSO0q6t7HHMO',
                'created' => new DateTime('2007-03-17 01:16:23'),
                'updated' => new DateTime('2007-03-17 01:18:31'),
            ],
            [
                '_id' => '000000000000000000000002',
                'username' => 'nate',
                'password' => '$2a$10$u05j8FjsvLBNdfhBhc21LOuVMpzpabVXQ9OpC2wO3pSO0q6t7HHMO',
                'created' => new DateTime('2008-03-17 01:18:23'),
                'updated' => new DateTime('2008-03-17 01:20:31'),
            ],
        ];
        $this->assertEquals($expected, $results);
    }

    /**
     * Tests that it is possible to select only a few fields when finding over a table
     */
    public function testFindAllSomeFieldsNoHydration(): void
    {
        $table = new BaseCollection([
            'collection' => 'users',
            'connection' => $this->connection,
        ]);
        $results = $table->find('all')
            ->select(['username', 'password'])
            ->enableHydration(false)
            ->orderBy('username')->toArray();
        $expected = [
            ['username' => 'garrett', 'password' => '$2a$10$u05j8FjsvLBNdfhBhc21LOuVMpzpabVXQ9OpC2wO3pSO0q6t7HHMO'],
            ['username' => 'larry', 'password' => '$2a$10$u05j8FjsvLBNdfhBhc21LOuVMpzpabVXQ9OpC2wO3pSO0q6t7HHMO'],
            ['username' => 'mariano', 'password' => '$2a$10$u05j8FjsvLBNdfhBhc21LOuVMpzpabVXQ9OpC2wO3pSO0q6t7HHMO'],
            ['username' => 'nate', 'password' => '$2a$10$u05j8FjsvLBNdfhBhc21LOuVMpzpabVXQ9OpC2wO3pSO0q6t7HHMO'],
        ];
        $this->assertSame($expected, $results);

        $results = $table->find('all')
            ->select(['foo' => 'username', 'password'])
            ->orderBy('username')
            ->enableHydration(false)
            ->toArray();
        $expected = [
            ['foo' => 'garrett', 'password' => '$2a$10$u05j8FjsvLBNdfhBhc21LOuVMpzpabVXQ9OpC2wO3pSO0q6t7HHMO'],
            ['foo' => 'larry', 'password' => '$2a$10$u05j8FjsvLBNdfhBhc21LOuVMpzpabVXQ9OpC2wO3pSO0q6t7HHMO'],
            ['foo' => 'mariano', 'password' => '$2a$10$u05j8FjsvLBNdfhBhc21LOuVMpzpabVXQ9OpC2wO3pSO0q6t7HHMO'],
            ['foo' => 'nate', 'password' => '$2a$10$u05j8FjsvLBNdfhBhc21LOuVMpzpabVXQ9OpC2wO3pSO0q6t7HHMO'],
        ];
        $this->assertSame($expected, $results);
    }

    /**
     * Tests that the query will automatically casts complex conditions to the correct
     * types when the columns belong to the default table
     */
    public function testFindAllConditionAutoTypes(): void
    {
        $table = new BaseCollection([
            'collection' => 'users',
            'connection' => $this->connection,
        ]);
        $query = $table->find('all')
            ->select(['id', 'username'])
            ->where(['created >=' => new DateTime('2010-01-22 00:00')])
            ->enableHydration(false)
            ->orderBy('id');
        $expected = [
            ['_id' => '000000000000000000000003', 'username' => 'larry'],
            ['_id' => '000000000000000000000004', 'username' => 'garrett'],
        ];
        $this->assertSame($expected, $query->toArray());

        $query = $table->find()
            ->enableHydration(false)
            ->select(['id', 'username'])
            ->where(['OR' => [
                'created >=' => new DateTime('2010-01-22 00:00'),
                'users.created' => new DateTime('2008-03-17 01:18:23'),
            ]])
            ->orderBy('id');
        $expected = [
            ['_id' => '000000000000000000000002', 'username' => 'nate'],
            ['_id' => '000000000000000000000003', 'username' => 'larry'],
            ['_id' => '000000000000000000000004', 'username' => 'garrett'],
        ];
        $this->assertSame($expected, $query->toArray());
    }

    /**
     * Test that beforeFind events can mutate the query.
     */
    public function testFindBeforeFindEventMutateQuery(): void
    {
        $table = new BaseCollection([
            'collection' => 'users',
            'connection' => $this->connection,
        ]);
        $table->getEventManager()->on(
            'Collection.beforeFind',
            function (EventInterface $event, $query, $options): void {
                $query->limit(1);
            },
        );

        $result = $table->find('all')->all();
        $this->assertCount(1, $result, 'Should only have 1 record, limit 1 applied.');
    }

    /**
     * Test that beforeFind events are fired and can stop the find and
     * return custom results.
     */
    public function testFindBeforeFindEventOverrideReturn(): void
    {
        $table = new BaseCollection([
            'collection' => 'users',
            'connection' => $this->connection,
        ]);
        $expected = ['One', 'Two', 'Three'];
        $table->getEventManager()->on(
            'Collection.beforeFind',
            function (EventInterface $event, $query, $options) use ($expected): void {
                $query->setResult($expected);
                $event->stopPropagation();
            },
        );

        $query = $table->find('all')
            ->formatResults(function (ResultSet $results) {
                return $results;
            });
        $query->limit(1);
        $this->assertEquals($expected, $query->all()->toArray());
    }

    /**
     * Test that the getAssociation() method supports the dot syntax.
     */
    public function testAssociationDotSyntax(): void
    {
        $sections = $this->getCollectionLocator()->get('Sections');
        $members = $this->getCollectionLocator()->get('Members');
        $sectionsMembers = $this->getCollectionLocator()->get('SectionsMembers');

        $sections->belongsToMany('Members');
        $sections->hasMany('SectionsMembers');
        $sectionsMembers->belongsTo('Members');
        $members->belongsToMany('Sections');

        $association = $sections->getAssociation('SectionsMembers.Members.Sections');
        $this->assertInstanceOf(BelongsToMany::class, $association);
        $this->assertSame(
            $sections->getAssociation('SectionsMembers')->getAssociation('Members')->getAssociation('Sections'),
            $association,
        );
    }

    public function testGetAssociationWithIncorrectCasing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "The `authors` association is not defined on `Articles`.\n"
            . 'Valid associations are: Authors, Tags, ArticlesTags',
        );

        $articles = $this->getCollectionLocator()->get('Articles', ['className' => ArticlesCollection::class]);

        $articles->getAssociation('authors');
    }

    /**
     * Tests that the getAssociation() method throws an exception on nonexistent ones.
     */
    public function testGetAssociationNonExistent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The `FooBar` association is not defined on `Sections`.');

        $this->getCollectionLocator()->get('Sections')->getAssociation('FooBar');
    }

    /**
     * Tests that belongsTo() creates and configures correctly the association
     */
    public function testBelongsTo(): void
    {
        $options = ['foreignKey' => 'fake_id', 'conditions' => ['a' => 'b']];
        $table = new BaseCollection(['collection' => 'dates']);
        $belongsTo = $table->belongsTo('user', $options);
        $this->assertInstanceOf(BelongsTo::class, $belongsTo);
        $this->assertSame($belongsTo, $table->getAssociation('user'));
        $this->assertSame('user', $belongsTo->getName());
        $this->assertSame('fake_id', $belongsTo->getForeignKey());
        $this->assertEquals(['a' => 'b'], $belongsTo->getConditions());
        $this->assertSame($table, $belongsTo->getSource());
    }

    /**
     * Tests that hasOne() creates and configures correctly the association
     */
    public function testHasOne(): void
    {
        $table = new BaseCollection(['collection' => 'users']);
        $hasOne = $table->hasOne('profile', ['conditions' => ['b' => 'c']]);
        $this->assertInstanceOf(HasOne::class, $hasOne);
        $this->assertSame($hasOne, $table->getAssociation('profile'));
        $this->assertSame('profile', $hasOne->getName());
        $this->assertSame('user_id', $hasOne->getForeignKey());
        $this->assertEquals(['b' => 'c'], $hasOne->getConditions());
        $this->assertSame($table, $hasOne->getSource());
    }

    /**
     * Test has one with a plugin model
     */
    public function testHasOnePlugin(): void
    {
        $table = new BaseCollection(['collection' => 'users']);

        $hasOne = $table->hasOne('Comments', ['className' => 'TestPlugin.Comments']);
        $this->assertInstanceOf(HasOne::class, $hasOne);
        $this->assertSame('Comments', $hasOne->getName());

        $this->assertSame('Comments', $hasOne->getAlias());
        $this->assertSame('TestPlugin.Comments', $hasOne->getRegistryAlias());

        $table = new BaseCollection(['collection' => 'users']);

        $hasOne = $table->hasOne('TestPlugin.Comments', ['className' => 'TestPlugin.Comments']);
        $this->assertInstanceOf(HasOne::class, $hasOne);
        $this->assertSame('Comments', $hasOne->getName());

        $this->assertSame('Comments', $hasOne->getAlias());
        $this->assertSame('TestPlugin.Comments', $hasOne->getRegistryAlias());
    }

    /**
     * testNoneUniqueAssociationsSameClass
     */
    public function testNoneUniqueAssociationsSameClass(): void
    {
        $Users = new BaseCollection(['collection' => 'users']);
        $Users->hasMany('Comments');

        $Articles = new BaseCollection(['collection' => 'articles']);
        $Articles->hasMany('Comments');

        $Categories = new BaseCollection(['collection' => 'categories']);
        $options = ['className' => 'TestPlugin.Comments'];
        $Categories->hasMany('Comments', $options);

        $this->assertInstanceOf(BaseCollection::class, $Users->Comments->getTarget());
        $this->assertInstanceOf(BaseCollection::class, $Articles->Comments->getTarget());
        $this->assertInstanceOf(CommentsCollection::class, $Categories->Comments->getTarget());
    }

    /**
     * Test associations which refer to the same table multiple times
     */
    public function testSelfJoinAssociations(): void
    {
        $Categories = $this->getCollectionLocator()->get('Categories');
        $options = ['className' => 'Categories'];
        $Categories->hasMany('Children', ['foreignKey' => 'parent_id'] + $options);
        $Categories->belongsTo('Parent', $options);

        $this->assertSame('categories', $Categories->Children->getTarget()->getCollection());
        $this->assertSame('categories', $Categories->Parent->getTarget()->getCollection());

        $this->assertSame('Children', $Categories->Children->getAlias());
        $this->assertSame('Children', $Categories->Children->getTarget()->getAlias());

        $this->assertSame('Parent', $Categories->Parent->getAlias());
        $this->assertSame('Parent', $Categories->Parent->getTarget()->getAlias());

        $expected = [
            '_id' => '000000000000000000000002',
            'parent_id' => '000000000000000000000001',
            'name' => 'Category 1.1',
            'parent' => [
                '_id' => '000000000000000000000001',
                'parent_id' => '000000000000000000000000',
                'name' => 'Category 1',
            ],
            'children' => [
                [
                    '_id' => '000000000000000000000007',
                    'parent_id' => '000000000000000000000002',
                    'name' => 'Category 1.1.1',
                ],
                [
                    '_id' => '000000000000000000000008',
                    'parent_id' => '000000000000000000000002',
                    'name' => 'Category 1.1.2',
                ],
            ],
        ];

        $fields = ['id', 'parent_id', 'name'];
        $result = $Categories->find('all')
            ->select(['Categories.id', 'Categories.parent_id', 'Categories.name'])
            ->contain(['Children' => ['fields' => $fields], 'Parent' => ['fields' => $fields]])
            ->where(['Categories.id' => '000000000000000000000002'])
            ->first()
            ->toArray();

        $this->assertSame($expected, $result);
    }

    /**
     * Tests that hasMany() creates and configures correctly the association
     */
    public function testHasMany(): void
    {
        $options = [
            'conditions' => ['b' => 'c'],
            'sort' => ['foo' => 'asc'],
        ];
        $table = new BaseCollection(['collection' => 'authors']);
        $hasMany = $table->hasMany('article', $options);
        $this->assertInstanceOf(HasMany::class, $hasMany);
        $this->assertSame($hasMany, $table->getAssociation('article'));
        $this->assertSame('article', $hasMany->getName());
        $this->assertSame('author_id', $hasMany->getForeignKey());
        $this->assertEquals(['b' => 'c'], $hasMany->getConditions());
        $this->assertEquals(['foo' => 'asc'], $hasMany->getSort());
        $this->assertSame($table, $hasMany->getSource());
    }

    /**
     * testHasManyWithClassName
     */
    public function testHasManyWithClassName(): void
    {
        $table = $this->getCollectionLocator()->get('Articles');
        $table->hasMany('Comments', [
            'conditions' => ['published' => 'Y'],
        ]);

        $table->hasMany('UnapprovedComments', [
            'className' => 'Comments',
            'conditions' => ['published' => 'N'],
            'propertyName' => 'unaproved_comments',
        ]);

        $expected = [
            '_id' => '000000000000000000000001',
            'title' => 'First Article',
            'unaproved_comments' => [
                [
                    '_id' => '000000000000000000000004',
                    'article_id' => '000000000000000000000001',
                    'comment' => 'Fourth Comment for First Article',
                ],
            ],
            'comments' => [
                [
                    '_id' => '000000000000000000000001',
                    'article_id' => '000000000000000000000001',
                    'comment' => 'First Comment for First Article',
                ],
                [
                    '_id' => '000000000000000000000002',
                    'article_id' => '000000000000000000000001',
                    'comment' => 'Second Comment for First Article',
                ],
                [
                    '_id' => '000000000000000000000003',
                    'article_id' => '000000000000000000000001',
                    'comment' => 'Third Comment for First Article',
                ],
            ],
        ];
        $result = $table->find()
            ->select(['id', 'title'])
            ->contain([
                'Comments' => ['fields' => ['id', 'article_id', 'comment']],
                'UnapprovedComments' => ['fields' => ['id', 'article_id', 'comment']],
            ])
            ->where(['_id' => '000000000000000000000001'])
            ->first();

        $this->assertSame($expected, $result->toArray());
    }

    /**
     * Ensure associations use the plugin-prefixed model
     */
    public function testHasManyPluginOverlap(): void
    {
        $this->getCollectionLocator()->get('Comments');
        $this->loadPlugins(['TestPlugin']);

        $table = new BaseCollection(['collection' => 'authors']);

        $table->hasMany('TestPlugin.Comments');
        $comments = $table->Comments->getTarget();
        $this->assertInstanceOf(CommentsCollection::class, $comments);
    }

    /**
     * Ensure associations use the plugin-prefixed model
     * even if specified with config
     */
    public function testHasManyPluginOverlapConfig(): void
    {
        $this->getCollectionLocator()->get('Comments');
        $this->loadPlugins(['TestPlugin']);

        $table = new BaseCollection(['collection' => 'authors']);

        $table->hasMany('Comments', ['className' => 'TestPlugin.Comments']);
        $comments = $table->Comments->getTarget();
        $this->assertInstanceOf(CommentsCollection::class, $comments);
    }

    /**
     * Tests that BelongsToMany() creates and configures correctly the association
     */
    public function testBelongsToMany(): void
    {
        $options = [
            'foreignKey' => 'thing_id',
            'joinCollection' => 'things_tags',
            'conditions' => ['b' => 'c'],
            'sort' => ['foo' => 'asc'],
        ];
        $table = new BaseCollection(['collection' => 'authors', 'connection' => $this->connection]);
        $belongsToMany = $table->belongsToMany('tag', $options);
        $this->assertInstanceOf(BelongsToMany::class, $belongsToMany);
        $this->assertSame($belongsToMany, $table->getAssociation('tag'));
        $this->assertSame('tag', $belongsToMany->getName());
        $this->assertSame('thing_id', $belongsToMany->getForeignKey());
        $this->assertEquals(['b' => 'c'], $belongsToMany->getConditions());
        $this->assertEquals(['foo' => 'asc'], $belongsToMany->getSort());
        $this->assertSame($table, $belongsToMany->getSource());
        $this->assertSame('things_tags', $belongsToMany->junction()->getCollection());
    }

    /**
     * Test addAssociations()
     */
    public function testAddAssociations(): void
    {
        $params = [
            'belongsTo' => [
                'users' => ['foreignKey' => 'fake_id', 'conditions' => ['a' => 'b']],
            ],
            'hasOne' => ['profiles'],
            'hasMany' => ['authors'],
            'belongsToMany' => [
                'tags' => [
                    'joinCollection' => 'things_tags',
                    'conditions' => [
                        'Tags.starred' => true,
                    ],
                ],
            ],
        ];

        $table = new BaseCollection(['collection' => 'members']);
        $result = $table->addAssociations($params);
        $this->assertSame($table, $result);

        $associations = $table->associations();

        $belongsTo = $associations->get('users');
        $this->assertInstanceOf(BelongsTo::class, $belongsTo);
        $this->assertSame('users', $belongsTo->getName());
        $this->assertSame('fake_id', $belongsTo->getForeignKey());
        $this->assertEquals(['a' => 'b'], $belongsTo->getConditions());
        $this->assertSame($table, $belongsTo->getSource());

        $hasOne = $associations->get('profiles');
        $this->assertInstanceOf(HasOne::class, $hasOne);
        $this->assertSame('profiles', $hasOne->getName());

        $hasMany = $associations->get('authors');
        $this->assertInstanceOf(HasMany::class, $hasMany);
        $this->assertSame('authors', $hasMany->getName());

        $belongsToMany = $associations->get('tags');
        $this->assertInstanceOf(BelongsToMany::class, $belongsToMany);
        $this->assertSame('tags', $belongsToMany->getName());
        $this->assertSame('things_tags', $belongsToMany->junction()->getCollection());
        $this->assertSame(['Tags.starred' => true], $belongsToMany->getConditions());
    }

    /**
     * Test basic multi row updates.
     */
    public function testUpdateAll(): void
    {
        $table = new BaseCollection([
            'collection' => 'users',
            'connection' => $this->connection,
        ]);
        $fields = ['username' => 'mark'];
        $result = $table->updateAll($fields, [
            '_id' => [
                '$in' => [
                    '000000000000000000000001',
                    '000000000000000000000002',
                    '000000000000000000000003',
                ],
            ],
        ]);
        $this->assertSame(3, $result);

        $result = $table->find('all')
            ->select(['username', '_id' => '000000000000000000000000'])
            ->orderBy(['_id' => 'asc'])
            ->enableHydration(false)
            ->toArray();
        $expected = array_fill(0, 3, $fields);
        $expected[] = ['username' => 'garrett'];
        $this->assertEquals($expected, $result);
    }

    public function testUpdateExpression(): void
    {
        $table = new BaseCollection([
            'collection' => 'counter_cache_users',
            'connection' => $this->connection,
        ]);
        $document = new Document([
            'name' => 'test',
            'post_count' => 0,
            'comment_count' => 0,
            'posts_published' => 0,
        ]);
        $table->save($document);
        $expression = new QueryExpression(['post_count = post_count + 1']);
        $result = $table->updateAll([$expression], ['_id' => '000000000000000000000001']);
        $this->assertNotEmpty($result);
    }

    /**
     * Test updateAll with ExpressionInterface conditions.
     */
    public function testUpdateAllWithExpressionConditions(): void
    {
        $table = new BaseCollection([
            'collection' => 'users',
            'connection' => $this->connection,
        ]);
        $conditions = new ComparisonExpression('_id', '000000000000000000000001', '<');
        $result = $table->updateAll(['username' => 'changed'], $conditions);
        $this->assertSame(0, $result);
    }

    /**
     * Test that exceptions from the Query bubble up.
     */
    public function testUpdateAllFailure(): void
    {
        $this->expectException(DatabaseException::class);
        $table = $this->getMockBuilder(BaseCollection::class)
            ->onlyMethods(['updateQuery'])
            ->setConstructorArgs([['collection' => 'users']])
            ->getMock();
        $query = $this->getMockBuilder(UpdateQuery::class)
            ->onlyMethods(['execute'])
            ->setConstructorArgs([$table])
            ->getMock();
        $table->expects($this->once())
            ->method('updateQuery')
            ->willReturn($query);

        $query->expects($this->once())
            ->method('execute')
            ->will($this->throwException(new DatabaseException('Not good')));

        $table->updateAll(['username' => 'mark'], []);
    }

    /**
     * Test deleting many records.
     */
    public function testDeleteAll(): void
    {
        $table = new BaseCollection([
            'collection' => 'users',
            'connection' => $this->connection,
        ]);
        $result = $table->deleteAll([
            '_id' => [
                '$in' => [
                    '000000000000000000000001',
                    '000000000000000000000002',
                    '000000000000000000000003',
                ],
            ],
        ]);
        $this->assertSame(3, $result);

        $result = $table->find('all')->toArray();
        $this->assertCount(1, $result, 'Only one record should remain');
        $this->assertSame('000000000000000000000004', $result[0]['_id']);
    }

    /**
     * Test deleting many records with conditions using the alias
     */
    public function testDeleteAllAliasedConditions(): void
    {
        $table = new BaseCollection([
            'collection' => 'users',
            'alias' => 'Managers',
            'connection' => $this->connection,
        ]);
        $result = $table->deleteAll([
            'Managers._id' => [
                '$in' => [
                    '000000000000000000000001',
                    '000000000000000000000002',
                    '000000000000000000000003',
                ],
            ],
        ]);
        $this->assertSame(3, $result);

        $result = $table->find('all')->toArray();
        $this->assertCount(1, $result, 'Only one record should remain');
        $this->assertSame('000000000000000000000004', $result[0]['_id']);
    }

    /**
     * Test deleteAll with ExpressionInterface conditions.
     */
    public function testDeleteAllWithExpressionConditions(): void
    {
        $table = new BaseCollection([
            'collection' => 'users',
            'connection' => $this->connection,
        ]);
        $conditions = new ComparisonExpression('_id', '000000000000000000000004', '<');
        $result = $table->deleteAll($conditions);
        $this->assertSame(3, $result);

        $result = $table->find('all')->toArray();
        $this->assertCount(1, $result, 'Only one record should remain');
        $this->assertSame('000000000000000000000004', $result[0]['_id']);
    }

    /**
     * Test that exceptions from the Query bubble up.
     */
    public function testDeleteAllFailure(): void
    {
        $this->expectException(DatabaseException::class);
        $table = $this->getMockBuilder(BaseCollection::class)
            ->onlyMethods(['deleteQuery'])
            ->setConstructorArgs([['collection' => 'users']])
            ->getMock();
        $query = $this->getMockBuilder(DeleteQuery::class)
            ->onlyMethods(['execute'])
            ->setConstructorArgs([$table])
            ->getMock();
        $table->expects($this->once())
            ->method('deleteQuery')
            ->willReturn($query);

        $query->expects($this->once())
            ->method('execute')
            ->will($this->throwException(new DatabaseException('Not good')));

        $table->deleteAll(['id >' => 4]);
    }

    /**
     * Tests that array options are passed to the query object using applyOptions
     */
    public function testFindApplyOptions(): void
    {
        $table = $this->getMockBuilder(BaseCollection::class)
            ->onlyMethods(['selectQuery', 'findAll'])
            ->setConstructorArgs([['collection' => 'users', 'connection' => $this->connection]])
            ->getMock();
        $query = $this->getMockBuilder(SelectQuery::class)
            ->setConstructorArgs([$table])
            ->getMock();
        $table->expects($this->once())
            ->method('selectQuery')
            ->willReturn($query);

        $options = ['fields' => ['a', 'b']];
        $query->method('select')
            ->willReturnSelf();

        $query->expects($this->once())->method('getOptions')
            ->willReturn([]);
        $query->expects($this->once())
            ->method('applyOptions')
            ->with($options);

        $table->expects($this->once())->method('findAll');
        $table->find('all', ...$options);
    }

    /**
     * Tests that extra arguments are passed to finders.
     */
    public function testFindTypedParameters(): void
    {
        $author = $this->getCollectionLocator()->get('Authors')->find('WithIdArgument', 2)->first();
        $this->assertSame(2, $author->id);

        $author = $this->getCollectionLocator()->get('Authors')->find('WithIdArgument', id: 2)->first();
        $this->assertSame(2, $author->id);
    }

    /**
     * https://github.com/cakephp/cakephp/issues/18716
     */
    public function testChangedFindWithOverlappingArgs(): void
    {
        $query = $this->getCollectionLocator()->get('Authors')
            ->find('withIdArgument', 2)
            ->find('custom', id: [1, 2], second: false);

        $this->assertSame(['id' => [1, 2], 'second' => false], $query->getOptions());

        $query = $this->getCollectionLocator()->get('Authors')
            ->find('withIdArgument', id: 2)
            ->find('custom', second: true);

        $this->assertSame(['_id' => '000000000000000000000002', 'second' => true], $query->getOptions());

        $query = $this->getCollectionLocator()->get('Authors')
            ->find('withIdArgument', id: 2)
            ->find('custom2', id: [2, 3], second: true);

        $this->assertSame(['id' => [2, 3], 'second' => true], $query->getOptions());
    }

    public function testFindForFinderVariadic(): void
    {
        $testCollection = $this->fetchCollection('Test');

        $testCollection->find('variadic', foo: 'bar');
        $this->assertNull($testCollection->first);
        $this->assertSame(['foo' => 'bar'], $testCollection->variadic);

        $testCollection->find('variadic', first: 'one', foo: 'bar');
        $this->assertSame('one', $testCollection->first);
        $this->assertSame(['foo' => 'bar'], $testCollection->variadic);

        $testCollection->find('variadicOptions');
        $this->assertSame([], $testCollection->variadicOptions);

        $testCollection->find('variadicOptions', foo: 'bar');
        $this->assertSame(['foo' => 'bar'], $testCollection->variadicOptions);
    }

    /**
     * Tests find('list')
     */
    public function testFindListNoHydration(): void
    {
        $table = new BaseCollection([
            'collection' => 'users',
            'connection' => $this->connection,
        ]);
        $table->setDisplayField('username');
        $query = $table->find('list')
            ->enableHydration(false)
            ->orderBy('id');
        $expected = [
            1 => 'mariano',
            2 => 'nate',
            3 => 'larry',
            4 => 'garrett',
        ];
        $this->assertSame($expected, $query->toArray());

        $query = $table->find('list', fields: ['id', 'username'])
            ->enableHydration(false)
            ->orderBy('id');
        $expected = [
            1 => 'mariano',
            2 => 'nate',
            3 => 'larry',
            4 => 'garrett',
        ];
        $this->assertSame($expected, $query->toArray());

        $query = $table->find('list', groupField: 'odd')
            ->select(['id', 'username', 'odd' => new QueryExpression('id % 2')])
            ->enableHydration(false)
            ->orderBy('id');
        $expected = [
            1 => [
                1 => 'mariano',
                3 => 'larry',
            ],
            0 => [
                2 => 'nate',
                4 => 'garrett',
            ],
        ];
        $this->assertSame($expected, $query->toArray());
    }

    /**
     * Tests find('threaded')
     */
    public function testFindThreadedNoHydration(): void
    {
        $table = new BaseCollection([
            'collection' => 'categories',
            'connection' => $this->connection,
        ]);
        $expected = [
            [
                '_id' => '000000000000000000000001',
                'parent_id' => '000000000000000000000000',
                'name' => 'Category 1',
                'children' => [
                    [
                        '_id' => '000000000000000000000002',
                        'parent_id' => '000000000000000000000001',
                        'name' => 'Category 1.1',
                        'children' => [
                            [
                                '_id' => '000000000000000000000007',
                                'parent_id' => '000000000000000000000002',
                                'name' => 'Category 1.1.1',
                                'children' => [],
                            ],
                            [
                                '_id' => '000000000000000000000008',
                                'parent_id' => '2',
                                'name' => 'Category 1.1.2',
                                'children' => [],
                            ],
                        ],
                    ],
                    [
                        '_id' => '000000000000000000000003',
                        'parent_id' => '1',
                        'name' => 'Category 1.2',
                        'children' => [],
                    ],
                ],
            ],
            [
                '_id' => '000000000000000000000004',
                'parent_id' => '000000000000000000000000',
                'name' => 'Category 2',
                'children' => [],
            ],
            [
                '_id' => '000000000000000000000005',
                'parent_id' => '000000000000000000000000',
                'name' => 'Category 3',
                'children' => [
                    [
                        'id' => '6',
                        'parent_id' => '5',
                        'name' => 'Category 3.1',
                        'children' => [],
                    ],
                ],
            ],
        ];
        $results = $table->find('all')
            ->select(['id', 'parent_id', 'name'])
            ->enableHydration(false)
            ->find('threaded')
            ->toArray();

        $this->assertEquals($expected, $results);
    }

    /**
     * Tests that finders can be stacked
     */
    public function testStackingFinders(): void
    {
        $table = $this->getMockBuilder(BaseCollection::class)
            ->onlyMethods(['find', 'findList'])
            ->disableOriginalConstructor()
            ->getMock();
        $query = $this->getMockBuilder(SelectQuery::class)
            ->onlyMethods(['addDefaultTypes'])
            ->setConstructorArgs([$table])
            ->getMock();

        $table->expects($this->once())
            ->method('find')
            ->with('threaded', ['order' => ['name' => 'ASC']])
            ->willReturn($query);

        $table->expects($this->once())
            ->method('findList')
            ->with($query, 'id')
            ->willReturn($query);

        $result = $table
            ->find('threaded', ['order' => ['name' => 'ASC']])
            ->find('list', keyField: 'id');
        $this->assertSame($query, $result);
    }

    /**
     * Tests find('threaded') with hydrated results
     */
    public function testFindThreadedHydrated(): void
    {
        $table = new BaseCollection([
            'collection' => 'categories',
            'connection' => $this->connection,
        ]);
        $results = $table->find('all')
            ->find('threaded')
            ->select(['id', 'parent_id', 'name'])
            ->toArray();

        $this->assertSame(1, $results[0]->id);
        $expected = [
            '_id' => '000000000000000000000008',
            'parent_id' => '000000000000000000000002',
            'name' => 'Category 1.1.2',
            'children' => [],
        ];
        $this->assertEquals($expected, $results[0]->children[0]->children[1]->toArray());
    }

    /**
     * Tests find('list') with hydrated records
     */
    public function testFindListHydrated(): void
    {
        $table = new BaseCollection([
            'collection' => 'users',
            'connection' => $this->connection,
        ]);
        $table->setDisplayField('username');
        $query = $table
            ->find('list', fields: ['id', 'username'])
            ->orderBy('id');
        $expected = [
            1 => 'mariano',
            2 => 'nate',
            3 => 'larry',
            4 => 'garrett',
        ];
        $this->assertSame($expected, $query->toArray());

        $query = $table->find('list', groupField: 'odd')
            ->select(['id', 'username', 'odd' => new QueryExpression('id % 2')])
            ->enableHydration(true)
            ->orderBy('id');
        $expected = [
            1 => [
                1 => 'mariano',
                3 => 'larry',
            ],
            0 => [
                2 => 'nate',
                4 => 'garrett',
            ],
        ];
        $this->assertSame($expected, $query->toArray());
    }

    /**
     * Test that find('list') only selects required fields.
     */
    public function testFindListSelectedFields(): void
    {
        $table = new BaseCollection([
            'collection' => 'users',
            'connection' => $this->connection,
        ]);
        $table->setDisplayField('username');

        $query = $table->find('list');
        $expected = ['id', 'username'];
        $this->assertSame($expected, $query->clause('select'));

        $query = $table->find('list', valueField: function ($row) {
            return $row->username;
        });
        $this->assertEmpty($query->clause('select'));

        $expected = ['odd' => new QueryExpression('id % 2'), 'id', 'username'];
        $query = $table->find('list', fields: $expected, groupField: 'odd');
        $this->assertSame($expected, $query->clause('select'));

        $articles = new BaseCollection([
            'collection' => 'articles',
            'connection' => $this->connection,
        ]);

        $query = $articles->find('list', groupField: 'author_id');
        $expected = ['id', 'title', 'author_id'];
        $this->assertSame($expected, $query->clause('select'));

        $query = $articles->find('list', valueField: ['author_id', 'title'])
            ->orderBy('id');
        $expected = ['id', 'author_id', 'title'];
        $this->assertSame($expected, $query->clause('select'));

        $expected = [
            1 => '1 First Article',
            2 => '3 Second Article',
            3 => '1 Third Article',
        ];
        $this->assertSame($expected, $query->toArray());

        $query = $articles->find('list', valueField: ['id', 'title'], valueSeparator: ' : ')
            ->orderBy('id');

        $expected = [
            1 => '1 : First Article',
            2 => '2 : Second Article',
            3 => '3 : Third Article',
        ];
        $this->assertSame($expected, $query->toArray());
    }

    /**
     * test that find('list') does not auto add fields to select if using virtual properties
     */
    public function testFindListWithVirtualField(): void
    {
        $table = new BaseCollection([
            'collection' => 'users',
            'connection' => $this->connection,
            'entityClass' => VirtualUser::class,
        ]);
        $table->setDisplayField('bonus');

        $query = $table
            ->find('list')
            ->orderBy('id');
        $this->assertEmpty($query->clause('select'));

        $expected = [
            1 => 'bonus',
            2 => 'bonus',
            3 => 'bonus',
            4 => 'bonus',
        ];
        $this->assertSame($expected, $query->toArray());

        $query = $table->find('list', groupField: 'odd');
        $this->assertEmpty($query->clause('select'));
    }

    /**
     * Test find('list') with value field from associated table
     */
    public function testFindListWithAssociatedCollection(): void
    {
        $articles = new BaseCollection([
            'collection' => 'articles',
            'connection' => $this->connection,
        ]);

        $articles->belongsTo('Authors');
        $query = $articles->find('list', valueField: 'author.name')
            ->contain(['Authors'])
            ->orderBy('articles.id');
        $this->assertEmpty($query->clause('select'));

        $expected = [
            1 => 'mariano',
            2 => 'larry',
            3 => 'mariano',
        ];
        $this->assertSame($expected, $query->toArray());
    }

    /**
     * Test the default entityClass.
     */
    public function testDocumentClassDefault(): void
    {
        $table = new BaseCollection();
        $this->assertSame(Document::class, $table->getDocumentClass());
    }

    /**
     * Tests that using a simple string for entityClass will try to
     * load the class from the App namespace
     */
    public function testTableClassInApp(): void
    {
        $class = Mockery::mock(Document::class)::class;

        if (!class_exists('TestApp\Model\Document\TestUser')) {
            class_alias($class, 'TestApp\Model\Document\TestUser');
        }

        $table = new BaseCollection();
        $this->assertSame($table, $table->setDocumentClass('TestUser'));
        $this->assertSame('TestApp\Model\Document\TestUser', $table->getDocumentClass());
    }

    /**
     * Test that entity class inflection works for compound nouns
     */
    public function testDocumentClassInflection(): void
    {
        $class = Mockery::mock(Document::class)::class;

        if (!class_exists('TestApp\Model\Document\CustomCookie')) {
            class_alias($class, 'TestApp\Model\Document\CustomCookie');
        }

        $table = $this->getCollectionLocator()->get('CustomCookies');
        $this->assertSame('TestApp\Model\Document\CustomCookie', $table->getDocumentClass());

        if (!class_exists('TestApp\Model\Document\Address')) {
            class_alias($class, 'TestApp\Model\Document\Address');
        }

        $table = $this->getCollectionLocator()->get('Addresses');
        $this->assertSame('TestApp\Model\Document\Address', $table->getDocumentClass());
    }

    /**
     * Tests that using a simple string for entityClass will try to
     * load the class from the Plugin namespace when using plugin notation
     */
    public function testTableClassInPlugin(): void
    {
        $class = Mockery::mock(Document::class)::class;

        if (!class_exists('MyPlugin\Model\Document\SuperUser')) {
            class_alias($class, 'MyPlugin\Model\Document\SuperUser');
        }

        $table = new BaseCollection();
        $this->assertSame($table, $table->setDocumentClass('MyPlugin.SuperUser'));
        $this->assertSame(
            'MyPlugin\Model\Document\SuperUser',
            $table->getDocumentClass(),
        );
    }

    /**
     * Tests that using a simple string for entityClass will throw an exception
     * when the class does not exist in the namespace
     */
    public function testTableClassNonExistent(): void
    {
        $this->expectException(MissingDocumentException::class);
        $this->expectExceptionMessage('Document class `FooUser` could not be found.');
        $table = new BaseCollection();
        $table->setDocumentClass('FooUser');
    }

    /**
     * Tests getting the entityClass based on conventions for the entity
     * namespace
     */
    public function testTableClassConventionForAPP(): void
    {
        $table = new ArticlesCollection();
        $this->assertSame(Article::class, $table->getDocumentClass());
    }

    /**
     * Tests setting a entity class object using the setter method
     */
    public function testSetDocumentClass(): void
    {
        $table = new BaseCollection();
        $class = '\\' . Mockery::mock(Document::class)::class;
        $this->assertSame($table, $table->setDocumentClass($class));
        $this->assertSame($class, $table->getDocumentClass());
    }

    /**
     * Proves that associations, even though they are lazy loaded, will fetch
     * records using the correct table class and hydrate with the correct entity
     */
    public function testReciprocalBelongsToLoading(): void
    {
        $table = new ArticlesCollection([
            'connection' => $this->connection,
        ]);
        $result = $table->find('all')->contain(['Authors'])->first();
        $this->assertInstanceOf(Author::class, $result->author);
    }

    /**
     * Proves that associations, even though they are lazy loaded, will fetch
     * records using the correct table class and hydrate with the correct entity
     */
    public function testReciprocalHasManyLoading(): void
    {
        $table = new ArticlesCollection([
            'connection' => $this->connection,
        ]);
        // Use select strategy explicitly for nested contain on PostgreSQL compatibility
        $result = $table->find('all')
            ->contain(['Authors' => ['Articles' => ['strategy' => 'select']]])
            ->first();
        $this->assertCount(2, $result->author->articles);
        foreach ($result->author->articles as $article) {
            $this->assertInstanceOf(Article::class, $article);
        }
    }

    /**
     * Tests that the correct table and entity are loaded for the join association in
     * a belongsToMany setup
     */
    public function testReciprocalBelongsToMany(): void
    {
        $table = new ArticlesCollection([
            'connection' => $this->connection,
        ]);
        $result = $table->find('all')->contain(['Tags'])->first();
        $this->assertInstanceOf(Tag::class, $result->tags[0]);
        $this->assertInstanceOf(
            ArticlesTag::class,
            $result->tags[0]->_joinData,
        );
    }

    /**
     * Tests that recently fetched entities are always clean
     */
    public function testFindCleanEntities(): void
    {
        $table = new ArticlesCollection([
            'connection' => $this->connection,
        ]);
        $results = $table->find('all')->contain(['Tags', 'Authors'])->toArray();
        $this->assertCount(3, $results);
        foreach ($results as $article) {
            $this->assertFalse($article->isDirty('id'));
            $this->assertFalse($article->isDirty('title'));
            $this->assertFalse($article->isDirty('author_id'));
            $this->assertFalse($article->isDirty('body'));
            $this->assertFalse($article->isDirty('published'));
            $this->assertFalse($article->isDirty('author'));
            $this->assertFalse($article->author->isDirty('id'));
            $this->assertFalse($article->author->isDirty('name'));
            $this->assertFalse($article->isDirty('tag'));
            if ($article->tag) {
                $this->assertFalse($article->tag[0]->_joinData->isDirty('tag_id'));
            }
        }
    }

    /**
     * Tests that recently fetched entities are marked as not new
     */
    public function testFindPersistedEntities(): void
    {
        $table = new ArticlesCollection([
            'connection' => $this->connection,
        ]);
        $results = $table->find('all')->contain(['Tags', 'Authors'])->toArray();
        $this->assertCount(3, $results);
        foreach ($results as $article) {
            $this->assertFalse($article->isNew());
            foreach ((array)$article->tag as $tag) {
                $this->assertFalse($tag->isNew());
                $this->assertFalse($tag->_joinData->isNew());
            }
        }
    }

    /**
     * Tests the exists function
     */
    public function testExists(): void
    {
        $table = $this->getCollectionLocator()->get('users');
        $this->assertTrue($table->exists(['_id' => '000000000000000000000001']));
        $this->assertFalse($table->exists(['_id' => '000000000000000000000501']));
        $this->assertTrue($table->exists(['_id' => '000000000000000000000003', 'username' => 'larry']));
    }

    /**
     * Test exists with ExpressionInterface conditions.
     */
    public function testExistsWithExpressionConditions(): void
    {
        $table = $this->getCollectionLocator()->get('users');
        $conditions = new ComparisonExpression('_id', '000000000000000000000001', '=');
        $this->assertTrue($table->exists($conditions));

        $conditions = new ComparisonExpression('_id', '000000000000000000000501', '=');
        $this->assertFalse($table->exists($conditions));
    }

    /**
     * Test adding a behavior to a table.
     */
    public function testAddBehavior(): void
    {
        $mock = $this->getMockBuilder(BehaviorRegistry::class)
            ->disableOriginalConstructor()
            ->getMock();
        $mock->expects($this->once())
            ->method('load')
            ->with('Sluggable');

        $table = new BaseCollection([
            'collection' => 'articles',
            'behaviors' => $mock,
        ]);
        $result = $table->addBehavior('Sluggable');
        $this->assertSame($table, $result);
    }

    /**
     * Test adding a plugin behavior to a table.
     */
    public function testAddBehaviorPlugin(): void
    {
        $table = new BaseCollection([
            'collection' => 'articles',
        ]);
        $result = $table->addBehavior('TestPlugin.PersisterOne', ['some' => 'key']);

        $this->assertSame(['PersisterOne'], $result->behaviors()->loaded());
        $className = $result->behaviors()->get('PersisterOne')->getConfig('className');
        $this->assertSame('TestPlugin.PersisterOne', $className);
    }

    /**
     * Test adding a behavior that is a duplicate.
     */
    public function testAddBehaviorDuplicate(): void
    {
        $table = new BaseCollection(['collection' => 'articles']);
        $this->assertSame($table, $table->addBehavior('Sluggable', ['test' => 'value']));
        $this->assertSame($table, $table->addBehavior('Sluggable', ['test' => 'value']));
        try {
            $table->addBehavior('Sluggable', ['thing' => 'thing']);
            $this->fail('No exception raised');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('The `Sluggable` alias has already been loaded', $e->getMessage());
        }
    }

    /**
     * Test removing a behavior from a table.
     */
    public function testRemoveBehavior(): void
    {
        $mock = $this->getMockBuilder(BehaviorRegistry::class)
            ->disableOriginalConstructor()
            ->getMock();
        $mock->expects($this->once())
            ->method('unload')
            ->with('Sluggable');

        $table = new BaseCollection([
            'collection' => 'articles',
            'behaviors' => $mock,
        ]);
        $result = $table->removeBehavior('Sluggable');
        $this->assertSame($table, $result);
    }

    /**
     * Test adding multiple behaviors to a table.
     */
    public function testAddBehaviors(): void
    {
        $table = new BaseCollection(['collection' => 'comments']);
        $behaviors = [
            'Sluggable',
            'Timestamp' => [
                'events' => [
                    'Collection.beforeSave' => [
                        'created' => 'new',
                        'updated' => 'always',
                    ],
                ],
            ],
        ];

        $this->assertSame($table, $table->addBehaviors($behaviors));
        $this->assertTrue($table->behaviors()->has('Sluggable'));
        $this->assertTrue($table->behaviors()->has('Timestamp'));
        $this->assertSame(
            $behaviors['Timestamp']['events'],
            $table->behaviors()->get('Timestamp')->getConfig('events'),
        );
    }

    /**
     * Test getting a behavior instance from a table.
     */
    public function testBehaviors(): void
    {
        $table = $this->getCollectionLocator()->get('article');
        $result = $table->behaviors();
        $this->assertInstanceOf(BehaviorRegistry::class, $result);
    }

    /**
     * Test that the getBehavior() method retrieves a behavior from the table registry.
     */
    public function testGetBehavior(): void
    {
        $table = new BaseCollection(['collection' => 'comments']);
        $table->addBehavior('Sluggable');
        $this->assertSame($table->behaviors()->get('Sluggable'), $table->getBehavior('Sluggable'));
    }

    /**
     * Test that the getBehavior() method will throw an exception when you try to
     * get a behavior that does not exist.
     */
    public function testGetBehaviorThrowsExceptionForMissingBehavior(): void
    {
        $table = new BaseCollection(['collection' => 'comments']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The `Sluggable` behavior is not defined on `' . $table::class . '`.');

        $this->assertFalse($table->hasBehavior('Sluggable'));
        $table->getBehavior('Sluggable');
    }

    /**
     * Ensure exceptions are raised on missing behaviors.
     */
    public function testAddBehaviorMissing(): void
    {
        $this->expectException(MissingBehaviorException::class);
        $table = $this->getCollectionLocator()->get('article');
        $this->assertNull($table->addBehavior('NopeNotThere'));
    }

    /**
     * Test finder methods from behaviors.
     */
    public function testCallBehaviorFinder(): void
    {
        $table = $this->getCollectionLocator()->get('articles');
        $table->addBehavior('Sluggable');

        $query = $table->find('noSlug');
        $this->assertInstanceOf(SelectQuery::class, $query);
        $this->assertNotEmpty($query->clause('where'));
    }

    /**
     * testCallBehaviorAliasedFinder
     */
    public function testCallBehaviorAliasedFinder(): void
    {
        $table = $this->getCollectionLocator()->get('articles');
        $table->addBehavior('Sluggable', ['implementedFinders' => ['special' => 'findNoSlug']]);

        $query = $table->find('special');
        $this->assertInstanceOf(SelectQuery::class, $query);
        $this->assertNotEmpty($query->clause('where'));
    }

    /**
     * Tests that it is possible to insert a new row using the save method
     */
    public function testSaveNewDocument(): void
    {
        $document = new Document([
            'username' => 'superuser',
            'password' => 'root',
            'created' => new DateTime('2013-10-10 00:00'),
            'updated' => new DateTime('2013-10-10 00:00'),
        ]);
        $table = $this->getCollectionLocator()->get('users');
        $this->assertSame($document, $table->save($document));
        $this->assertEquals($document->id, self::$nextUserId);

        $row = $table->find()->where(['id' => self::$nextUserId])->first();
        $this->assertEquals($document->toArray(), $row->toArray());
    }

    /**
     * Test that saving a new empty entity does nothing.
     */
    public function testSaveNewEmptyDocument(): void
    {
        $document = new Document();
        $table = $this->getCollectionLocator()->get('users');
        $this->assertFalse($table->save($document));
    }

    /**
     * Test that saving a new empty entity does not call exists.
     */
    public function testSaveNewDocumentNoExists(): void
    {
        /** @var \Crustum\Mongo\ODM\BaseCollection|\PHPUnit\Framework\MockObject\MockObject $table */
        $table = $this->getMockBuilder(BaseCollection::class)
            ->onlyMethods(['exists'])
            ->setConstructorArgs([[
                'connection' => $this->connection,
                'alias' => 'Users',
                'collection' => 'users',
            ]])
            ->getMock();
        $document = $table->newDocument(['username' => 'mark']);
        $this->assertTrue($document->isNew());

        $table->expects($this->never())
            ->method('exists');
        $this->assertSame($document, $table->save($document));
    }

    /**
     * Test that saving a new entity with a Primary Key set does call exists.
     */
    public function testSavePrimaryKeyDocumentExists(): void
    {
        $this->skipIfSqlServer();
        /** @var \Crustum\Mongo\ODM\BaseCollection|\PHPUnit\Framework\MockObject\MockObject $table */
        $table = $this->getMockBuilder(BaseCollection::class)
            ->onlyMethods(['exists'])
            ->setConstructorArgs([[
                'connection' => $this->connection,
                'alias' => 'Users',
                'collection' => 'users',
            ]])
            ->getMock();
        $document = $table->newDocument(['_id' => '000000000000000000000020', 'username' => 'mark']);
        $this->assertTrue($document->isNew());

        $table->expects($this->once())->method('exists');
        $this->assertSame($document, $table->save($document));
    }

    /**
     * Test that saving a new entity with a Primary Key set does not call exists when checkExisting is false.
     */
    public function testSavePrimaryKeyDocumentNoExists(): void
    {
        $this->skipIfSqlServer();
        /** @var \Crustum\Mongo\ODM\BaseCollection|\PHPUnit\Framework\MockObject\MockObject $table */
        $table = $this->getMockBuilder(BaseCollection::class)
            ->onlyMethods(['exists'])
            ->setConstructorArgs([[
                'connection' => $this->connection,
                'alias' => 'Users',
                'collection' => 'users',
            ]])
            ->getMock();
        $document = $table->newDocument(['_id' => '000000000000000000000020', 'username' => 'mark']);
        $this->assertTrue($document->isNew());

        $table->expects($this->never())->method('exists');
        $this->assertSame($document, $table->save($document, ['checkExisting' => false]));
    }

    /**
     * Tests that saving an entity will filter out properties that
     * are not present in the table schema when saving
     */
    public function testSaveDocumentOnlySchemaFields(): void
    {
        $document = new Document([
            'username' => 'superuser',
            'password' => 'root',
            'crazyness' => 'super crazy value',
            'created' => new DateTime('2013-10-10 00:00'),
            'updated' => new DateTime('2013-10-10 00:00'),
        ]);
        $table = $this->getCollectionLocator()->get('users');
        $this->assertSame($document, $table->save($document));
        $this->assertEquals($document->id, self::$nextUserId);

        $row = $table->find('all')->where(['id' => self::$nextUserId])->first();
        $document->unset('crazyness');
        $this->assertEquals($document->toArray(), $row->toArray());
    }

    /**
     * Tests that it is possible to modify data from the beforeSave callback
     */
    public function testBeforeSaveModifyData(): void
    {
        $table = $this->getCollectionLocator()->get('users');
        $data = new Document([
            'username' => 'superuser',
            'created' => new DateTime('2013-10-10 00:00'),
            'updated' => new DateTime('2013-10-10 00:00'),
        ]);
        $listener = function ($event, EntityInterface $document, $options) use ($data): void {
            $this->assertSame($data, $document);
            $document->set('password', 'foo');
        };
        $table->getEventManager()->on('Collection.beforeSave', $listener);
        $this->assertSame($data, $table->save($data));
        $this->assertEquals($data->id, self::$nextUserId);
        $row = $table->find('all')->where(['id' => self::$nextUserId])->first();
        $this->assertSame('foo', $row->get('password'));
    }

    /**
     * Tests that it is possible to modify the options array in beforeSave
     */
    public function testBeforeSaveModifyOptions(): void
    {
        $table = $this->getCollectionLocator()->get('users');
        $data = new Document([
            'username' => 'superuser',
            'password' => 'foo',
            'created' => new DateTime('2013-10-10 00:00'),
            'updated' => new DateTime('2013-10-10 00:00'),
        ]);
        $listener1 = function ($event, $document, $options): void {
            $options['crazy'] = true;
        };
        $listener2 = function ($event, $document, $options): void {
            $this->assertTrue($options['crazy']);
        };
        $table->getEventManager()->on('Collection.beforeSave', $listener1);
        $table->getEventManager()->on('Collection.beforeSave', $listener2);
        $this->assertSame($data, $table->save($data));
        $this->assertEquals($data->id, self::$nextUserId);

        $row = $table->find('all')->where(['id' => self::$nextUserId])->first();
        $this->assertEquals($data->toArray(), $row->toArray());
    }

    /**
     * Tests that it is possible to stop the saving altogether, without implying
     * the save operation failed
     */
    public function testBeforeSaveStopEvent(): void
    {
        $table = $this->getCollectionLocator()->get('users');
        $data = new Document([
            'username' => 'superuser',
            'created' => new DateTime('2013-10-10 00:00'),
            'updated' => new DateTime('2013-10-10 00:00'),
        ]);
        $listener = function (EventInterface $event, $document): void {
            $event->stopPropagation();
            $event->setResult($document);
        };
        $table->getEventManager()->on('Collection.beforeSave', $listener);
        $this->assertSame($data, $table->save($data));
        $this->assertNull($data->id);
        $row = $table->find('all')->where(['id' => self::$nextUserId])->first();
        $this->assertNull($row);
    }

    /**
     * Tests that if beforeSave event is stopped and callback doesn't return any
     * value then save() returns false.
     */
    public function testBeforeSaveStopEventWithNoResult(): void
    {
        $table = $this->getCollectionLocator()->get('users');
        $data = new Document([
            'username' => 'superuser',
            'created' => new DateTime('2013-10-10 00:00'),
            'updated' => new DateTime('2013-10-10 00:00'),
        ]);
        $listener = function (EventInterface $event, $document): void {
            $event->stopPropagation();
        };
        $table->getEventManager()->on('Collection.beforeSave', $listener);
        $this->assertFalse($table->save($data));
    }

    public function testBeforeSaveException(): void
    {
        $this->expectException(AssertionError::class);
        $this->expectExceptionMessage('The result for the `Model.beforeSave` event must be `false` or `EntityInterface` instance. Got `int` instead.');

        $table = $this->getCollectionLocator()->get('users');
        $data = new Document([
            'username' => 'superuser',
            'created' => new DateTime('2013-10-10 00:00'),
            'updated' => new DateTime('2013-10-10 00:00'),
        ]);
        $listener = function (EventInterface $event, $document): void {
            $event->stopPropagation();
            $event->setResult(1);
        };
        $table->getEventManager()->on('Collection.beforeSave', $listener);
        $table->save($data);
    }

    /**
     * Asserts that afterSave callback is called on successful save
     */
    public function testAfterSave(): void
    {
        $table = $this->getCollectionLocator()->get('users');
        $data = $table->get('000000000000000000000001');

        $data->username = 'newusername';

        $called = false;
        $listener = function ($e, EntityInterface $document, $options) use ($data, &$called): void {
            $this->assertSame($data, $document);
            $this->assertTrue($document->isDirty());
            $called = true;
        };
        $table->getEventManager()->on('Collection.afterSave', $listener);

        $calledAfterCommit = false;
        $listenerAfterCommit = function ($e, EntityInterface $document, $options) use ($data, &$calledAfterCommit): void {
            $this->assertSame($data, $document);
            $this->assertTrue($document->isDirty());
            $this->assertNotSame($data->get('username'), $data->getOriginal('username'));
            $calledAfterCommit = true;
        };
        $table->getEventManager()->on('Model.afterSaveCommit', $listenerAfterCommit);

        $this->assertSame($data, $table->save($data));
        $this->assertTrue($called);
        $this->assertTrue($calledAfterCommit);
    }

    /**
     * Asserts that afterSaveCommit is also triggered for non-atomic saves
     */
    public function testAfterSaveCommitForNonAtomic(): void
    {
        $table = $this->getCollectionLocator()->get('users');
        $data = new Document([
            'username' => 'superuser',
            'created' => new DateTime('2013-10-10 00:00'),
            'updated' => new DateTime('2013-10-10 00:00'),
        ]);

        $called = false;
        $listener = function ($e, $document, $options) use ($data, &$called): void {
            $this->assertSame($data, $document);
            $called = true;
        };
        $table->getEventManager()->on('Collection.afterSave', $listener);

        $calledAfterCommit = false;
        $listenerAfterCommit = function ($e, $document, $options) use (&$calledAfterCommit): void {
            $calledAfterCommit = true;
        };
        $table->getEventManager()->on('Model.afterSaveCommit', $listenerAfterCommit);

        $this->assertSame($data, $table->save($data, ['atomic' => false]));
        $this->assertEquals($data->id, self::$nextUserId);
        $this->assertTrue($called);
        $this->assertTrue($calledAfterCommit);
    }

    /**
     * Asserts the afterSaveCommit is not triggered if transaction is running.
     */
    public function testAfterSaveCommitWithTransactionRunning(): void
    {
        $table = $this->getCollectionLocator()->get('users');
        $data = new Document([
            'username' => 'superuser',
            'created' => new DateTime('2013-10-10 00:00'),
            'updated' => new DateTime('2013-10-10 00:00'),
        ]);

        $called = false;
        $listener = function ($e, $document, $options) use (&$called): void {
            $called = true;
        };
        $table->getEventManager()->on('Model.afterSaveCommit', $listener);

        $this->connection->begin();
        $this->assertSame($data, $table->save($data));
        $this->assertFalse($called);
        $this->connection->commit();
    }

    public function testDocumentFinalizedSynchronouslyInOuterTransaction(): void
    {
        $table = $this->getCollectionLocator()->get('users');

        $this->connection->transactional(function () use ($table): void {
            $document = new Document([
                'username' => 'outertxnuser',
                'created' => new DateTime('2013-10-10 00:00'),
                'updated' => new DateTime('2013-10-10 00:00'),
            ]);
            $table->saveOrFail($document);

            $this->assertFalse(
                $document->isNew(),
                'Document saved inside outer transaction must report isNew() === false immediately after save',
            );
            $this->assertFalse(
                $document->isDirty(),
                'Document saved inside outer transaction must be clean immediately after save',
            );
            $this->assertSame(
                'users',
                $document->getSource(),
                'Document saved inside outer transaction must have source set immediately after save',
            );
        });
    }

    public function testDeleteWorksOnDocumentSavedInOuterTransaction(): void
    {
        $table = $this->getCollectionLocator()->get('users');

        $this->connection->transactional(function () use ($table): void {
            $document = new Document([
                'username' => 'deletetxnuser',
                'created' => new DateTime('2013-10-10 00:00'),
                'updated' => new DateTime('2013-10-10 00:00'),
            ]);
            $table->saveOrFail($document);

            $result = $table->delete($document);
            $this->assertTrue(
                $result,
                'BaseCollection::delete() must not short-circuit on entity saved inside outer transaction',
            );
        });
    }

    public function testSaveThenUpdateInOuterTransaction(): void
    {
        $table = $this->getCollectionLocator()->get('users');

        $this->connection->transactional(function () use ($table): void {
            $document = new Document([
                'username' => 'insertupdateuser',
                'created' => new DateTime('2013-10-10 00:00'),
                'updated' => new DateTime('2013-10-10 00:00'),
            ]);
            $table->saveOrFail($document);

            $document->username = 'updateduser';
            $table->saveOrFail($document);

            $row = $table->get($document->id);
            $this->assertSame('updateduser', $row->username);
        });
    }

    public function testDocumentFinalizedDespiteEventualRollback(): void
    {
        $table = $this->getCollectionLocator()->get('users');

        $this->connection->begin();

        $document = new Document([
            'username' => 'rollbackfinalizeuser',
            'created' => new DateTime('2013-10-10 00:00'),
            'updated' => new DateTime('2013-10-10 00:00'),
        ]);
        $table->saveOrFail($document);

        $this->assertFalse($document->isNew(), 'Document must be finalized even if outer transaction will roll back');
        $this->assertFalse($document->isDirty(), 'Document must be clean even if outer transaction will roll back');
        $this->assertSame('users', $document->getSource());

        $this->connection->rollback();
    }

    /**
     * Asserts the afterSaveCommit is not triggered if transaction is running.
     */
    public function testAfterSaveCommitWithNonAtomicAndTransactionRunning(): void
    {
        $table = $this->getCollectionLocator()->get('users');
        $data = new Document([
            'username' => 'superuser',
            'created' => new DateTime('2013-10-10 00:00'),
            'updated' => new DateTime('2013-10-10 00:00'),
        ]);

        $called = false;
        $listener = function ($e, $document, $options) use (&$called): void {
            $called = true;
        };
        $table->getEventManager()->on('Model.afterSaveCommit', $listener);

        $this->connection->begin();
        $this->assertSame($data, $table->save($data, ['atomic' => false]));
        $this->assertFalse($called);
        $this->connection->commit();
    }

    /**
     * Asserts that afterSave callback not is called on unsuccessful save
     */
    public function testAfterSaveNotCalled(): void
    {
        /** @var \Crustum\Mongo\ODM\BaseCollection|\PHPUnit\Framework\MockObject\MockObject $table */
        $table = $this->getMockBuilder(BaseCollection::class)
            ->onlyMethods(['insertQuery'])
            ->setConstructorArgs([['collection' => 'users']])
            ->getMock();
        $query = $this->getMockBuilder(InsertQuery::class)
            ->onlyMethods(['execute', 'addDefaultTypes'])
            ->setConstructorArgs([$table])
            ->getMock();
        $statement = Mockery::mock(StatementInterface::class);
        $data = new Document([
            'username' => 'superuser',
            'created' => new DateTime('2013-10-10 00:00'),
            'updated' => new DateTime('2013-10-10 00:00'),
        ]);

        $table->expects($this->once())->method('insertQuery')
            ->willReturn($query);

        $query->expects($this->once())->method('execute')
            ->willReturn($statement);

        $statement->shouldReceive('rowCount')
            ->once()
            ->andReturn(0);

        $called = false;
        $listener = function ($e, $document, $options) use (&$called): void {
            $called = true;
        };
        $table->getEventManager()->on('Collection.afterSave', $listener);

        $calledAfterCommit = false;
        $listenerAfterCommit = function ($e, $document, $options) use (&$calledAfterCommit): void {
            $calledAfterCommit = true;
        };
        $table->getEventManager()->on('Model.afterSaveCommit', $listenerAfterCommit);

        $this->assertFalse($table->save($data));
        $this->assertFalse($called);
        $this->assertFalse($calledAfterCommit);
    }

    /**
     * Asserts that afterSaveCommit callback is triggered only for primary table
     */
    public function testAfterSaveCommitTriggeredOnlyForPrimaryCollection(): void
    {
        $document = new Document([
            'title' => 'A Title',
            'body' => 'A body',
        ]);
        $document->author = new Document([
            'name' => 'Jose',
        ]);

        $table = $this->getCollectionLocator()->get('articles');
        $table->belongsTo('authors');

        $calledForArticle = false;
        $listenerForArticle = function ($e, $document, $options) use (&$calledForArticle): void {
            $calledForArticle = true;
        };
        $table->getEventManager()->on('Model.afterSaveCommit', $listenerForArticle);

        $calledForAuthor = false;
        $listenerForAuthor = function ($e, $document, $options) use (&$calledForAuthor): void {
            $calledForAuthor = true;
        };
        $table->authors->getEventManager()->on('Model.afterSaveCommit', $listenerForAuthor);

        $this->assertSame($document, $table->save($document));
        $this->assertFalse($document->isNew());
        $this->assertFalse($document->author->isNew());
        $this->assertTrue($calledForArticle);
        $this->assertFalse($calledForAuthor);
    }

    /**
     * Test that you cannot save rows without a primary key.
     */
    public function testSaveNewErrorOnNoPrimaryKey(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Cannot insert row in `users` table, it has no primary key');
        $document = new Document(['username' => 'superuser']);
        $table = $this->getCollectionLocator()->get('users', [
            'schema' => [
                'id' => ['type' => 'integer'],
                'username' => ['type' => 'string'],
            ],
        ]);
        $table->save($document);
    }

    /**
     * Tests that save is wrapped around a transaction
     */
    public function testAtomicSave(): void
    {
        $config = ConnectionManager::getConfig('test');

        $connection = $this->getMockBuilder(Connection::class)
            ->onlyMethods(['begin', 'commit', 'inTransaction'])
            ->setConstructorArgs([['driver' => $this->connection->getDriver()] + $config])
            ->getMock();

        $table = new BaseCollection(['collection' => 'users', 'connection' => $connection]);

        $connection->expects($this->once())->method('begin');
        $connection->expects($this->once())->method('commit');
        $connection->method('inTransaction')->willReturn(true);
        $data = new Document([
            'username' => 'superuser',
            'created' => new DateTime('2013-10-10 00:00'),
            'updated' => new DateTime('2013-10-10 00:00'),
        ]);
        $this->assertSame($data, $table->save($data));
    }

    /**
     * Tests that save will rollback the transaction in the case of an exception
     */
    public function testAtomicSaveRollback(): void
    {
        $this->expectException(PDOException::class);
        /** @var \Cake\Database\Connection|\PHPUnit\Framework\MockObject\MockObject $connection */
        $connection = $this->getMockBuilder(Connection::class)
            ->onlyMethods(['begin', 'rollback'])
            ->setConstructorArgs([['driver' => $this->connection->getDriver()] + ConnectionManager::getConfig('test')])
            ->getMock();

        /** @var \Crustum\Mongo\ODM\BaseCollection|\PHPUnit\Framework\MockObject\MockObject $table */
        $table = $this->getMockBuilder(BaseCollection::class)
            ->onlyMethods(['insertQuery', 'getConnection'])
            ->setConstructorArgs([['collection' => 'users']])
            ->getMock();
        $query = $this->getMockBuilder(InsertQuery::class)
            ->onlyMethods(['execute', 'addDefaultTypes'])
            ->setConstructorArgs([$table])
            ->getMock();
        $table->method('getConnection')
            ->willReturn($connection);

        $table->expects($this->once())->method('insertQuery')
            ->willReturn($query);

        $connection->expects($this->once())->method('begin');
        $connection->expects($this->once())->method('rollback');
        $query->expects($this->once())->method('execute')
            ->will($this->throwException(new PDOException()));

        $data = new Document([
            'username' => 'superuser',
            'created' => new DateTime('2013-10-10 00:00'),
            'updated' => new DateTime('2013-10-10 00:00'),
        ]);
        $table->save($data);
    }

    /**
     * Tests that save will rollback the transaction in the case of an exception
     */
    public function testAtomicSaveRollbackOnFailure(): void
    {
        /** @var \Cake\Database\Connection|\PHPUnit\Framework\MockObject\MockObject $connection */
        $connection = $this->getMockBuilder(Connection::class)
            ->onlyMethods(['begin', 'rollback'])
            ->setConstructorArgs([['driver' => $this->connection->getDriver()] + ConnectionManager::getConfig('test')])
            ->getMock();

        /** @var \Crustum\Mongo\ODM\BaseCollection|\PHPUnit\Framework\MockObject\MockObject $table */
        $table = $this->getMockBuilder(BaseCollection::class)
            ->onlyMethods(['insertQuery', 'getConnection', 'exists'])
            ->setConstructorArgs([['collection' => 'users']])
            ->getMock();
        $query = $this->getMockBuilder(InsertQuery::class)
            ->onlyMethods(['execute', 'addDefaultTypes'])
            ->setConstructorArgs([$table])
            ->getMock();

        $table->method('getConnection')
            ->willReturn($connection);

        $table->expects($this->once())->method('insertQuery')
            ->willReturn($query);

        $statement = Mockery::mock(StatementInterface::class);
        $statement->shouldReceive('rowCount')
            ->once()
            ->andReturn(0);
        $connection->expects($this->once())->method('begin');
        $connection->expects($this->once())->method('rollback');
        $query->expects($this->once())
            ->method('execute')
            ->willReturn($statement);

        $data = new Document([
            'username' => 'superuser',
            'created' => new DateTime('2013-10-10 00:00'),
            'updated' => new DateTime('2013-10-10 00:00'),
        ]);
        $table->save($data);
    }

    /**
     * Tests that only the properties marked as dirty are actually saved
     * to the database
     */
    public function testSaveOnlyDirtyProperties(): void
    {
        $document = new Document([
            'username' => 'superuser',
            'password' => 'root',
            'created' => new DateTime('2013-10-10 00:00'),
            'updated' => new DateTime('2013-10-10 00:00'),
        ]);
        $document->clean();
        $document->setDirty('username', true);
        $document->setDirty('created', true);
        $document->setDirty('updated', true);

        $table = $this->getCollectionLocator()->get('users');
        $this->assertSame($document, $table->save($document));
        $this->assertEquals($document->id, self::$nextUserId);

        $row = $table->find('all')->where(['id' => self::$nextUserId])->first();
        $document->set('password', null);
        $this->assertEquals($document->toArray(), $row->toArray());
    }

    /**
     * Tests that a recently saved entity is marked as clean
     */
    public function testASavedDocumentIsClean(): void
    {
        $document = new Document([
            'username' => 'superuser',
            'password' => 'root',
            'created' => new DateTime('2013-10-10 00:00'),
            'updated' => new DateTime('2013-10-10 00:00'),
        ]);
        $table = $this->getCollectionLocator()->get('users');
        $this->assertSame($document, $table->save($document));
        $this->assertFalse($document->isDirty('usermane'));
        $this->assertFalse($document->isDirty('password'));
        $this->assertFalse($document->isDirty('created'));
        $this->assertFalse($document->isDirty('updated'));
    }

    /**
     * Tests that a recently saved entity is marked as not new
     */
    public function testASavedDocumentIsNotNew(): void
    {
        $document = new Document([
            'username' => 'superuser',
            'password' => 'root',
            'created' => new DateTime('2013-10-10 00:00'),
            'updated' => new DateTime('2013-10-10 00:00'),
        ]);
        $table = $this->getCollectionLocator()->get('users');
        $this->assertSame($document, $table->save($document));
        $this->assertFalse($document->isNew());
    }

    /**
     * Tests that save can detect automatically if it needs to insert
     * or update a row
     */
    public function testSaveUpdateAuto(): void
    {
        $document = new Document([
            '_id' => '000000000000000000000002',
            'username' => 'baggins',
        ]);
        $table = $this->getCollectionLocator()->get('users');
        $original = $table->find('all')->where(['_id' => '000000000000000000000002'])->first();
        $this->assertSame($document, $table->save($document));

        $row = $table->find('all')->where(['_id' => '000000000000000000000002'])->first();
        $this->assertSame('baggins', $row->username);
        $this->assertEquals($original->password, $row->password);
        $this->assertEquals($original->created, $row->created);
        $this->assertEquals($original->updated, $row->updated);
        $this->assertFalse($document->isNew());
        $this->assertFalse($document->isDirty('id'));
        $this->assertFalse($document->isDirty('username'));
    }

    /**
     * Tests that beforeFind gets the correct isNew() state for the entity
     */
    public function testBeforeSaveGetsCorrectPersistance(): void
    {
        $document = new Document([
            '_id' => '000000000000000000000002',
            'username' => 'baggins',
        ]);
        $table = $this->getCollectionLocator()->get('users');
        $called = false;
        $listener = function (EventInterface $event, $document) use (&$called): void {
            $this->assertFalse($document->isNew());
            $called = true;
        };
        $table->getEventManager()->on('Collection.beforeSave', $listener);
        $this->assertSame($document, $table->save($document));
        $this->assertTrue($called);
    }

    /**
     * Tests that marking an entity as already persisted will prevent the save
     * method from trying to infer the entity's actual status.
     */
    public function testSaveUpdateWithHint(): void
    {
        /** @var \Crustum\Mongo\ODM\BaseCollection|\PHPUnit\Framework\MockObject\MockObject $table */
        $table = $this->getMockBuilder(BaseCollection::class)
            ->onlyMethods(['exists'])
            ->setConstructorArgs([['collection' => 'users', 'connection' => ConnectionManager::get('test_mongo')]])
            ->getMock();
        $document = new Document([
            '_id' => '000000000000000000000002',
            'username' => 'baggins',
        ], ['markNew' => false]);
        $this->assertFalse($document->isNew());
        $table->expects($this->never())->method('exists');
        $this->assertSame($document, $table->save($document));
    }

    /**
     * Tests that when updating the primary key is not passed to the list of
     * attributes to change
     */
    public function testSaveUpdatePrimaryKeyNotModified(): void
    {
        /** @var \Cake\Database\Connection|\PHPUnit\Framework\MockObject\MockObject $connection */
        $connection = $this->getMockBuilder(Connection::class)
            ->onlyMethods(['run'])
            ->setConstructorArgs([['driver' => $this->connection->getDriver()] + ConnectionManager::getConfig('test')])
            ->getMock();
        $table = $this->fetchCollection('Users');
        $table->setConnection($connection);

        $statement = $this->getMockBuilder(StatementInterface::class)->getMock();
        $statement->expects($this->once())
            ->method('errorCode')
            ->willReturn('00000');

        $connection->expects($this->once())->method('run')
            ->willReturn($statement);

        $document = new Document([
            '_id' => '000000000000000000000002',
            'username' => 'baggins',
        ], ['markNew' => false]);
        $this->assertSame($document, $table->save($document));
    }

    /**
     * Tests that passing only the primary key to save will not execute any queries
     * but still return success
     */
    public function testUpdateNoChange(): void
    {
        /** @var \Crustum\Mongo\ODM\BaseCollection|\PHPUnit\Framework\MockObject\MockObject $table */
        $table = $this->getMockBuilder(BaseCollection::class)
            ->onlyMethods(['query'])
            ->setConstructorArgs([['collection' => 'users', 'connection' => $this->connection]])
            ->getMock();
        $table->expects($this->never())->method('query');
        $document = new Document([
            '_id' => '000000000000000000000002',
        ], ['markNew' => false]);
        $this->assertSame($document, $table->save($document));
    }

    /**
     * Tests that passing only the primary key to save will not execute any queries
     * but still return success
     */
    public function testUpdateDirtyNoActualChanges(): void
    {
        $table = $this->getCollectionLocator()->get('Articles');
        $document = $table->get('000000000000000000000001');

        $document->setAccess('*', true);
        $document->patch($document->toArray());
        $this->assertSame($document, $table->save($document));
    }

    /**
     * Tests that failing to pass a primary key to save will result in exception
     */
    public function testUpdateNoPrimaryButOtherKeys(): void
    {
        $this->expectException(InvalidArgumentException::class);
        /** @var \Crustum\Mongo\ODM\BaseCollection|\PHPUnit\Framework\MockObject\MockObject $table */
        $table = $this->getMockBuilder(BaseCollection::class)
            ->onlyMethods(['query'])
            ->setConstructorArgs([['collection' => 'users', 'connection' => $this->connection]])
            ->getMock();
        $table->expects($this->never())->method('query');
        $document = new Document([
            'username' => 'mariano',
        ], ['markNew' => false]);
        $this->assertSame($document, $table->save($document));
    }

    /**
     * Test saveMany() with entities array
     */
    public function testSaveManyArray(): void
    {
        $documents = [
            new Document(['name' => 'admad']),
            new Document(['name' => 'dakota']),
        ];

        $timesCalled = 0;
        $listener = function ($e, $document, $options) use (&$timesCalled): void {
            $timesCalled++;
        };
        $table = $this->getCollectionLocator()
            ->get('authors');

        $table->getEventManager()
            ->on('Model.afterSaveCommit', $listener);

        $result = $table->saveMany($documents);

        $this->assertSame($documents, $result);
        $this->assertTrue(isset($result[0]->id));
        foreach ($documents as $document) {
            $this->assertFalse($document->isNew());
        }
        $this->assertSame(2, $timesCalled);
    }

    /**
     * Test saveMany() with ResultSet instance
     */
    public function testSaveManyResultSet(): void
    {
        $table = $this->getCollectionLocator()->get('authors');
        $table->Articles->setSort('Articles.id');

        $documents = $table->find()
            ->orderBy(['id' => 'ASC'])
            ->contain(['Articles'])
            ->all();
        $documents->first()->name = 'admad';
        $documents->first()->articles[0]->title = 'First Article Edited';

        $listener = function (EventInterface $event, EntityInterface $document, $options): void {
            if ($document->id === 1) {
                $this->assertTrue($document->isDirty());

                $this->assertSame('admad', $document->name);
                $this->assertSame('mariano', $document->getOriginal('name'));

                $this->assertSame('First Article Edited', $document->articles[0]->title);
                $this->assertSame('First Article', $document->articles[0]->getOriginal('title'));
            } else {
                $this->assertFalse($document->isDirty());
            }
        };
        $table = $this->getCollectionLocator()
            ->get('authors');

        $table->getEventManager()
            ->on('Model.afterSaveCommit', $listener);

        $result = $table->saveMany($documents);
        $this->assertSame($documents, $result);
        $this->assertFalse($result->first()->isDirty());
        $this->assertFalse($result->first()->articles[0]->isDirty());

        $first = $table->find()
            ->orderBy(['id' => 'ASC'])
            ->first();
        $this->assertSame('admad', $first->name);
    }

    /**
     * Test saveMany() with failed save
     */
    public function testSaveManyFailed(): void
    {
        $table = $this->getCollectionLocator()->get('authors');
        $expectedCount = $table->find()->count();
        $documents = [
            new Document(['name' => 'mark']),
            new Document(['name' => 'jose']),
        ];
        $documents[1]->setErrors(['name' => ['message']]);
        $result = $table->saveMany($documents);

        $this->assertFalse($result);
        $this->assertSame($expectedCount, $table->find()->count());
        foreach ($documents as $document) {
            $this->assertTrue($document->isNew());
        }
    }

    /**
     * Test saveMany() with failed save due to an exception
     */
    public function testSaveManyFailedWithException(): void
    {
        $table = $this->getCollectionLocator()
            ->get('authors');
        $documents = [
            new Document(['name' => 'mark']),
            new Document(['name' => 'jose']),
        ];

        $table->getEventManager()->on('Collection.beforeSave', function (EventInterface $event, EntityInterface $document): void {
            if ($document->name === 'jose') {
                throw new Exception('Oh noes');
            }
        });

        $this->expectException(Exception::class);

        try {
            $table->saveMany($documents);
        } finally {
            foreach ($documents as $document) {
                $this->assertTrue($document->isNew());
            }
        }
    }

    /**
     * Test saveManyOrFail() with entities array
     */
    public function testSaveManyOrFailArray(): void
    {
        $documents = [
            new Document(['name' => 'admad']),
            new Document(['name' => 'dakota']),
        ];

        $table = $this->getCollectionLocator()->get('authors');
        $result = $table->saveManyOrFail($documents);

        $this->assertSame($documents, $result);
        $this->assertTrue(isset($result[0]->id));
        foreach ($documents as $document) {
            $this->assertFalse($document->isNew());
        }
    }

    /**
     * Test saveManyOrFail() with ResultSet instance
     */
    public function testSaveManyOrFailResultSet(): void
    {
        $table = $this->getCollectionLocator()->get('authors');

        $documents = $table->find()
            ->orderBy(['id' => 'ASC'])
            ->all();
        $documents->first()->name = 'admad';

        $result = $table->saveManyOrFail($documents);
        $this->assertSame($documents, $result);

        $first = $table->find()
            ->orderBy(['id' => 'ASC'])
            ->first();
        $this->assertSame('admad', $first->name);
    }

    /**
     * Test saveManyOrFail() with failed save
     */
    public function testSaveManyOrFailFailed(): void
    {
        $table = $this->getCollectionLocator()->get('authors');
        $documents = [
            new Document(['name' => 'mark']),
            new Document(['name' => 'jose']),
        ];
        $documents[1]->setErrors(['name' => ['message']]);

        $this->expectException(PersistenceFailedException::class);

        $table->saveManyOrFail($documents);
    }

    public function testSaveWithBuildRulesFailWithErrorMessage(): void
    {
        $Articles = new class extends BaseCollection {
            public function initialize(array $config): void
            {
                $this->setAlias('Articles');
                $this->setCollection('articles');
                $this->hasMany('Comments');
            }
        };
        $Comments = new class extends BaseCollection {
            public function initialize(array $config): void
            {
                $this->setAlias('Comments');
                $this->setCollection('comments');
            }

            public function buildRules(RulesChecker $rules): RulesChecker
            {
                return $rules->add(function () {
                    return 'Xyz';
                });
            }
        };
        CollectionRegistry::getCollectionLocator()->set('Comments', $Comments);

        $article = $Articles->newDocument([
            'title' => 'First Article',
            'body' => 'First Article Body',
            'published' => 'Y',
            'comments' => [
                '_ids' => [1],
            ],
        ]);

        $result = $Articles->save($article, ['associated' => ['Comments']]);
        $this->assertFalse($result);

        // There should be errors here, due to comment not being savable.
        $errors = $article->getErrors();
        $this->assertNotEmpty($errors);
        $this->assertArrayHasKey('comments', $errors);
        $this->assertArrayHasKey(0, $errors['comments']);
        $this->assertArrayHasKey('_rule', $errors['comments'][0]);
        $this->assertSame(['Xyz'], $errors['comments'][0]['_rule']);
    }

    /**
     * Test simple delete.
     */
    public function testDelete(): void
    {
        $table = $this->getCollectionLocator()->get('users');
        $options = [
            'limit' => 1,
            'conditions' => [
                'username' => 'nate',
            ],
        ];
        $query = $table->find('all', ...$options);
        $document = $query->first();
        $result = $table->delete($document);
        $this->assertTrue($result);

        $query = $table->find('all', ...$options);
        $this->assertCount(0, $query->all(), 'Find should fail.');
    }

    /**
     * Test delete with dependent records
     */
    public function testDeleteDependent(): void
    {
        $table = $this->getCollectionLocator()->get('authors');
        $table->Articles->setDependent(true);

        $document = $table->get('000000000000000000000001');
        $table->delete($document);

        $articles = $table->getAssociation('Articles')->getTarget();
        $query = $articles->find('all', conditions: ['author_id' => $document->id]);
        $this->assertNull($query->all()->first(), 'Should not find any rows.');
    }

    /**
     * Test delete with dependent records
     */
    public function testDeleteDependentHasMany(): void
    {
        $table = $this->getCollectionLocator()->get('authors');
        $table->Articles
            ->setDependent(true)
            ->setCascadeCallbacks(true);

        $articles = $table->getAssociation('Articles')->getTarget();
        $articles->getEventManager()->on('Model.buildRules', function ($event, $rules): void {
            $rules->addDelete(function ($document) {
                if ($document->author_id === 3) {
                    return false;
                }

                return true;
            });
        });

        $document = $table->get('000000000000000000000001');
        $result = $table->delete($document);
        $this->assertTrue($result);

        $query = $articles->find('all', conditions: ['author_id' => $document->id]);
        $this->assertNull($query->all()->first(), 'Should not find any rows.');

        $document = $table->get('000000000000000000000003');
        $result = $table->delete($document);
        $this->assertFalse($result);

        $query = $articles->find('all', conditions: ['author_id' => $document->id]);
        $this->assertFalse($query->all()->isEmpty(), 'Should find some rows.');

        $table->associations()->get('Articles')->setCascadeCallbacks(false);
        $document = $table->get('000000000000000000000002');
        $result = $table->delete($document);
        $this->assertTrue($result);
    }

    /**
     * Test delete with dependent = false does not cascade.
     */
    public function testDeleteNoDependentNoCascade(): void
    {
        $table = $this->getCollectionLocator()->get('authors');
        $table->hasMany('article', [
            'dependent' => false,
        ]);

        $query = $table->find('all')->where(['_id' => '000000000000000000000001']);
        $document = $query->first();
        $table->delete($document);

        $articles = $table->getAssociation('Articles')->getTarget();
        $query = $articles->find('all')->where(['author_id' => $document->id]);
        $this->assertCount(2, $query->all(), 'Should find rows.');
    }

    /**
     * Test delete with BelongsToMany
     */
    public function testDeleteBelongsToMany(): void
    {
        $table = $this->getCollectionLocator()->get('articles');
        $table->belongsToMany('tag', [
            'foreignKey' => 'article_id',
            'joinCollection' => 'articles_tags',
        ]);
        $query = $table->find('all')->where(['_id' => '000000000000000000000001']);
        $document = $query->first();
        $table->delete($document);

        $junction = $table->getAssociation('tag')->junction();
        $query = $junction->find('all')->where(['article_id' => '000000000000000000000001']);
        $this->assertNull($query->all()->first(), 'Should not find any rows.');
    }

    /**
     * Test delete with dependent records belonging to an aliased
     * belongsToMany association.
     */
    public function testDeleteDependentAliased(): void
    {
        $Authors = $this->getCollectionLocator()->get('authors');
        $Authors->associations()->removeAll();
        $Articles = $this->getCollectionLocator()->get('articles');
        $Articles->associations()->removeAll();

        $Authors->hasMany('AliasedArticles', [
            'className' => 'Articles',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);
        $Articles->belongsToMany('Tags');

        $author = $Authors->get('000000000000000000000001');
        $result = $Authors->delete($author);

        $this->assertTrue($result);
    }

    /**
     * Test that cascading associations are deleted first.
     */
    public function testDeleteAssociationsCascadingCallbacksOrder(): void
    {
        $sections = $this->getCollectionLocator()->get('Sections');
        $members = $this->getCollectionLocator()->get('Members');
        $sectionsMembers = $this->getCollectionLocator()->get('SectionsMembers');

        $sections->belongsToMany('Members', [
            'joinCollection' => 'sections_members',
        ]);
        $sections->hasMany('SectionsMembers', [
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);
        $sectionsMembers->belongsTo('Members');
        $sectionsMembers->addBehavior('CounterCache', [
            'Members' => ['section_count'],
        ]);

        $member = $members->get('000000000000000000000001');
        $this->assertSame(2, $member->section_count);

        $section = $sections->get('000000000000000000000001');
        $sections->delete($section);

        $member = $members->get('000000000000000000000001');
        $this->assertSame(1, $member->section_count);
    }

    /**
     * Test that primary record is not deleted if junction record deletion fails
     * when cascadeCallbacks is enabled.
     */
    public function testDeleteBelongsToManyDependentFailure(): void
    {
        $sections = $this->getCollectionLocator()->get('Sections');
        $sectionsMembers = $this->getCollectionLocator()->get('SectionsMembers');
        $sectionsMembers->getEventManager()->on('Model.buildRules', function ($event, $rules): void {
            $rules->addDelete(function () {
                return false;
            });
        });

        $sections->belongsToMany('Members', [
            'joinCollection' => 'sections_members',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);

        $section = $sections->get('000000000000000000000001', contain: 'Members');
        $this->assertSame(1, count($section->members));

        $this->assertFalse($sections->delete($section));

        $section = $sections->get('000000000000000000000001', contain: 'Members');
        $this->assertSame(1, count($section->members));
    }

    /**
     * Test delete callbacks
     */
    public function testDeleteCallbacks(): void
    {
        $document = new Document(['_id' => '000000000000000000000001', 'name' => 'mark']);
        $options = new ArrayObject(['atomic' => true, 'checkRules' => false, '_primary' => true]);

        $mock = Mockery::mock(EventManager::class);

        $mock->shouldReceive('on');

        $mock->shouldReceive('dispatch')
            ->withAnyArgs()
            ->once();

        $mock->shouldReceive('dispatch')
            ->withArgs(function (EventInterface $event) use ($document, $options) {
                $this->assertSame('Collection.beforeDelete', $event->getName());
                $this->assertEquals(['entity' => $document, 'options' => $options], $event->getData());

                return true;
            })
            ->once();

        $mock->shouldReceive('dispatch')
            ->withArgs(function (EventInterface $event) use ($document, $options) {
                $this->assertSame('Collection.afterDelete', $event->getName());
                $this->assertEquals(['entity' => $document, 'options' => $options], $event->getData());

                return true;
            })
            ->once();

        $mock->shouldReceive('dispatch')
            ->withArgs(function (EventInterface $event) use ($document, $options) {
                $this->assertSame('Model.afterDeleteCommit', $event->getName());
                $this->assertEquals(['entity' => $document, 'options' => $options], $event->getData());

                return true;
            })
            ->once();

        $table = $this->getCollectionLocator()->get('users', ['eventManager' => $mock]);
        $document->setNew(false);
        $table->delete($document, ['checkRules' => false]);
    }

    /**
     * Test afterDeleteCommit is also called for non-atomic delete
     */
    public function testDeleteCallbacksNonAtomic(): void
    {
        $table = $this->getCollectionLocator()->get('users');

        $data = $table->get('000000000000000000000001');

        $called = false;
        $listener = function ($e, $document, $options) use ($data, &$called): void {
            $this->assertSame($data, $document);
            $called = true;
        };
        $table->getEventManager()->on('Collection.afterDelete', $listener);

        $calledAfterCommit = false;
        $listenerAfterCommit = function ($e, $document, $options) use (&$calledAfterCommit): void {
            $calledAfterCommit = true;
        };
        $table->getEventManager()->on('Model.afterDeleteCommit', $listenerAfterCommit);

        $table->delete($data, ['atomic' => false]);
        $this->assertTrue($called);
        $this->assertTrue($calledAfterCommit);
    }

    /**
     * Test that afterDeleteCommit is only triggered for primary table
     */
    public function testAfterDeleteCommitTriggeredOnlyForPrimaryCollection(): void
    {
        $table = $this->getCollectionLocator()->get('authors');
        $table->Articles->setDependent(true);

        $called = false;
        $listener = function ($e, $document, $options) use (&$called): void {
            $called = true;
        };
        $table->getEventManager()->on('Model.afterDeleteCommit', $listener);

        $called2 = false;
        $listener = function ($e, $document, $options) use (&$called2): void {
            $called2 = true;
        };
        $table->Articles->getEventManager()->on('Model.afterDeleteCommit', $listener);

        $document = $table->get('000000000000000000000001');
        $this->assertTrue($table->delete($document));

        $this->assertTrue($called);
        $this->assertFalse($called2);
    }

    /**
     * Test delete beforeDelete can abort the delete.
     */
    public function testDeleteBeforeDeleteAbort(): void
    {
        $document = new Document(['_id' => '000000000000000000000001', 'name' => 'mark']);

        $mock = $this->getMockBuilder(EventManager::class)->getMock();
        $mock->method('dispatch')
            ->willReturnCallback(function (EventInterface $event) {
                $event->stopPropagation();

                return $event;
            });

        $table = $this->getCollectionLocator()->get('users', ['eventManager' => $mock]);
        $document->setNew(false);
        $result = $table->delete($document, ['checkRules' => false]);
        $this->assertFalse($result);
    }

    /**
     * Test delete beforeDelete return result
     */
    public function testDeleteBeforeDeleteReturnResult(): void
    {
        $document = new Document(['_id' => '000000000000000000000001', 'name' => 'mark']);

        $mock = $this->getMockBuilder(EventManager::class)->getMock();
        $mock->method('dispatch')
            ->willReturnCallback(function (EventInterface $event) {
                $event->stopPropagation();
                $event->setResult('got stopped');

                return $event;
            });

        $table = $this->getCollectionLocator()->get('users', ['eventManager' => $mock]);
        $document->setNew(false);
        $result = $table->delete($document, ['checkRules' => false]);
        $this->assertTrue($result);
    }

    /**
     * Test deleting new entities does nothing.
     */
    public function testDeleteIsNew(): void
    {
        $document = new Document(['_id' => '000000000000000000000001', 'name' => 'mark']);

        /** @var \Crustum\Mongo\ODM\BaseCollection|\PHPUnit\Framework\MockObject\MockObject $table */
        $table = $this->getMockBuilder(BaseCollection::class)
            ->onlyMethods(['query'])
            ->setConstructorArgs([['connection' => $this->connection]])
            ->getMock();
        $table->expects($this->never())
            ->method('query');

        $document->setNew(true);
        $result = $table->delete($document);
        $this->assertFalse($result);
    }

    /**
     * Test simple delete.
     */
    public function testDeleteMany(): void
    {
        $table = $this->getCollectionLocator()->get('users');
        $documents = $table->find()->limit(2)->all()->toArray();
        $this->assertCount(2, $documents);

        $result = $table->deleteMany($documents);
        $this->assertSame($documents, $result);

        $count = $table->find()->where(['id IN' => Hash::extract($documents, '{n}.id')])->count();
        $this->assertSame(0, $count, 'Find should not return > 0.');
    }

    /**
     * Test simple delete.
     */
    public function testDeleteManyOrFail(): void
    {
        $table = $this->getCollectionLocator()->get('users');
        $documents = $table->find()->limit(2)->all()->toArray();
        $this->assertCount(2, $documents);

        $table->deleteManyOrFail($documents);

        $count = $table->find()->where(['id IN' => Hash::extract($documents, '{n}.id')])->count();
        $this->assertSame(0, $count, 'Find should not return > 0.');
    }

    /**
     * test hasField()
     */
    public function testHasField(): void
    {
        $table = $this->getCollectionLocator()->get('articles');
        $this->assertFalse($table->hasField('nope'), 'Should not be there.');
        $this->assertTrue($table->hasField('title'), 'Should be there.');
        $this->assertTrue($table->hasField('body'), 'Should be there.');
    }

    /**
     * Tests that there exists a default validator
     */
    public function testValidatorDefault(): void
    {
        $table = new BaseCollection();
        $validator = $table->getValidator();
        $this->assertSame($table, $validator->getProvider('table'));
        $this->assertInstanceOf(Validator::class, $validator);
        $default = $table->getValidator('default');
        $this->assertSame($validator, $default);
    }

    /**
     * Tests that a InvalidArgumentException is thrown if the custom validator method does not exist.
     */
    public function testValidatorWithMissingMethod(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The `Crustum\Mongo\ODM\BaseCollection::validationMissing()` validation method does not exist.');
        $table = new BaseCollection();
        $table->getValidator('missing');
    }

    /**
     * Tests that it is possible to set a custom validator under a name
     */
    public function testValidatorSetter(): void
    {
        $table = new BaseCollection();
        $validator = new Validator();
        $table->setValidator('other', $validator);
        $this->assertSame($validator, $table->getValidator('other'));
        $this->assertSame($table, $validator->getProvider('table'));
    }

    /**
     * Tests hasValidator method.
     */
    public function testHasValidator(): void
    {
        $table = new BaseCollection();
        $this->assertTrue($table->hasValidator('default'));
        $this->assertFalse($table->hasValidator('other'));

        $validator = new Validator();
        $table->setValidator('other', $validator);
        $this->assertTrue($table->hasValidator('other'));
    }

    /**
     * Tests that the source of an existing Document is the same as a new one
     */
    public function testDocumentSourceExistingAndNew(): void
    {
        $this->loadPlugins(['TestPlugin']);
        $table = $this->getCollectionLocator()->get('TestPlugin.Authors');

        $existingAuthor = $table->find()->first();
        $newAuthor = $table->newEmptyDocument();

        $this->assertSame('TestPlugin.Authors', $existingAuthor->getSource());
        $this->assertSame('TestPlugin.Authors', $newAuthor->getSource());
    }

    /**
     * Tests that calling an entity with an empty array will run validation.
     */
    public function testNewDocumentAndValidation(): void
    {
        $table = $this->getCollectionLocator()->get('Articles');
        $table->getValidator()->requirePresence('title');

        $document = $table->newDocument([]);
        $errors = $document->getErrors();
        $this->assertNotEmpty($errors['title']);
    }

    /**
     * Tests that creating an entity will not run any validation.
     */
    public function testCreateDocumentAndValidation(): void
    {
        $table = $this->getCollectionLocator()->get('Articles');
        $table->getValidator()->requirePresence('title');

        $document = $table->newEmptyDocument();
        $this->assertEmpty($document->getErrors());
    }

    /**
     * Test magic findByXX method.
     */
    public function testMagicFindDefaultToAll(): void
    {
        $table = $this->getCollectionLocator()->get('Users');

        $result = $table->findByUsername('garrett');
        $this->assertInstanceOf(SelectQuery::class, $result);

        $this->assertEquals(['username' => 'garrett'], $result->clause('where'));
    }

    /**
     * Test magic findByXX errors on missing arguments.
     */
    public function testMagicFindError(): void
    {
        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Not enough arguments for magic finder. Got 0 required 1');
        $table = $this->getCollectionLocator()->get('Users');

        $table->findByUsername();
    }

    /**
     * Test magic findByXX errors on missing arguments.
     */
    public function testMagicFindErrorMissingField(): void
    {
        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Not enough arguments for magic finder. Got 1 required 2');
        $table = $this->getCollectionLocator()->get('Users');

        $table->findByUsernameAndId('garrett');
    }

    /**
     * Test magic findByXX errors when there is a mix of or & and.
     */
    public function testMagicFindErrorMixOfOperators(): void
    {
        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Cannot mix "and" & "or" in a magic finder. Use find() instead.');
        $table = $this->getCollectionLocator()->get('Users');

        $table->findByUsernameAndIdOrPassword('garrett', 1, 'sekret');
    }

    /**
     * Test magic findByXX method.
     */
    public function testMagicFindFirstAnd(): void
    {
        $table = $this->getCollectionLocator()->get('Users');

        $result = $table->findByUsernameAndId('garrett', 4);
        $this->assertInstanceOf(SelectQuery::class, $result);

        $this->assertEquals(['username' => 'garrett', '_id' => 4], $result->clause('where'));
    }

    /**
     * Test magic findByXX method.
     */
    public function testMagicFindFirstOr(): void
    {
        $table = $this->getCollectionLocator()->get('Users');

        $result = $table->findByUsernameOrId('garrett', 4);
        $this->assertInstanceOf(SelectQuery::class, $result);

        $this->assertEquals(
            ['$or' => [['username' => 'garrett'], ['_id' => 4]]],
            $result->clause('where'),
        );
    }

    /**
     * Test magic findAllByXX method.
     */
    public function testMagicFindAll(): void
    {
        $table = $this->getCollectionLocator()->get('Articles');

        $result = $table->findAllByAuthorId(1);
        $this->assertInstanceOf(SelectQuery::class, $result);
        $this->assertNull($result->clause('limit'));

        $this->assertEquals(['author_id' => 1], $result->clause('where'));
    }

    /**
     * Test magic findAllByXX method.
     */
    public function testMagicFindAllAnd(): void
    {
        $table = $this->getCollectionLocator()->get('Users');

        $result = $table->findAllByAuthorIdAndPublished(1, 'Y');
        $this->assertInstanceOf(SelectQuery::class, $result);
        $this->assertNull($result->clause('limit'));
        $this->assertEquals(['author_id' => 1, 'published' => 'Y'], $result->clause('where'));
    }

    /**
     * Test magic findAllByXX method.
     */
    public function testMagicFindAllOr(): void
    {
        $table = $this->getCollectionLocator()->get('Users');

        $result = $table->findAllByAuthorIdOrPublished(1, 'Y');
        $this->assertInstanceOf(SelectQuery::class, $result);
        $this->assertNull($result->clause('limit'));
        $this->assertEquals(
            ['$or' => [['author_id' => 1], ['published' => 'Y']]],
            $result->clause('where'),
        );
        $this->assertSame([], $result->clause('order'));
    }

    /**
     * Test the behavior method.
     */
    public function testBehaviorIntrospection(): void
    {
        $table = $this->getCollectionLocator()->get('users');

        $table->addBehavior('Timestamp');
        $this->assertTrue($table->hasBehavior('Timestamp'), 'should be true on loaded behavior');
        $this->assertFalse($table->hasBehavior('Tree'), 'should be false on unloaded behavior');
    }

    /**
     * Tests saving belongsTo association
     */
    public function testSaveBelongsTo(): void
    {
        $document = new Document([
            'title' => 'A Title',
            'body' => 'A body',
        ]);
        $document->author = new Document([
            'name' => 'Jose',
        ]);

        $table = $this->getCollectionLocator()->get('articles');
        $table->belongsTo('authors');
        $this->assertSame($document, $table->save($document));
        $this->assertFalse($document->isNew());
        $this->assertFalse($document->author->isNew());
        $this->assertSame(5, $document->author->id);
        $this->assertSame(5, $document->get('author_id'));
    }

    /**
     * Tests saving hasOne association
     */
    public function testSaveHasOne(): void
    {
        $document = new Document([
            'name' => 'Jose',
        ]);
        $document->article = new Document([
            'title' => 'A Title',
            'body' => 'A body',
        ]);

        $table = $this->getCollectionLocator()->get('authors');
        $table->associations()->remove('Articles');
        $table->hasOne('Articles');
        $this->assertSame($document, $table->save($document));
        $this->assertFalse($document->isNew());
        $this->assertFalse($document->article->isNew());
        $this->assertSame(4, $document->article->id);
        $this->assertSame(5, $document->article->get('author_id'));
        $this->assertFalse($document->article->isDirty('author_id'));
    }

    /**
     * Tests saving associations only saves associations
     * if they are entities.
     */
    public function testSaveOnlySaveAssociatedEntities(): void
    {
        $document = new Document([
            'name' => 'Jose',
        ]);

        // Not an entity.
        $document->article = [
            'title' => 'A Title',
            'body' => 'A body',
        ];

        $table = $this->getCollectionLocator()->get('authors');
        // $table->hasOne('articles');

        $table->save($document);
        $this->assertFalse($document->isNew());
        $this->assertIsArray($document->article);
    }

    /**
     * Tests saving multiple entities in a hasMany association
     */
    public function testSaveHasMany(): void
    {
        $document = new Document([
            'name' => 'Jose',
        ]);
        $document->articles = [
            new Document([
                'title' => 'A Title',
                'body' => 'A body',
            ]),
            new Document([
                'title' => 'Another Title',
                'body' => 'Another body',
            ]),
        ];

        $table = $this->getCollectionLocator()->get('authors');
        $this->assertSame($document, $table->save($document));
        $this->assertFalse($document->isNew());
        $this->assertFalse($document->articles[0]->isNew());
        $this->assertFalse($document->articles[1]->isNew());
        $this->assertSame(4, $document->articles[0]->id);
        $this->assertSame(5, $document->articles[1]->id);
        $this->assertSame(5, $document->articles[0]->author_id);
        $this->assertSame(5, $document->articles[1]->author_id);
    }

    /**
     * Tests overwriting hasMany associations in an integration scenario.
     */
    public function testSaveHasManyOverwrite(): void
    {
        $table = $this->getCollectionLocator()->get('authors');

        $document = $table->get('000000000000000000000003', contain: ['Articles']);
        $data = [
            'name' => 'big jose',
            'articles' => [
                [
                    '_id' => '000000000000000000000002',
                    'title' => 'New title',
                ],
            ],
        ];
        $document = $table->patchDocument($document, $data, ['associated' => 'Articles']);
        $this->assertSame($document, $table->save($document));

        $document = $table->get('000000000000000000000003', contain: ['Articles']);
        $this->assertSame('big jose', $document->name, 'Author did not persist');
        $this->assertSame('New title', $document->articles[0]->title, 'Article did not persist');
    }

    /**
     * Tests saving belongsToMany records
     */
    public function testSaveBelongsToMany(): void
    {
        $document = new Document([
            'title' => 'A Title',
            'body' => 'A body',
        ]);
        $document->tags = [
            new Document([
                'name' => 'Something New',
            ]),
            new Document([
                'name' => 'Another Something',
            ]),
        ];
        $table = $this->getCollectionLocator()->get('Articles');
        $this->assertSame($document, $table->save($document));
        $this->assertFalse($document->isNew());
        $this->assertFalse($document->tags[0]->isNew());
        $this->assertFalse($document->tags[1]->isNew());
        $this->assertSame(4, $document->tags[0]->id);
        $this->assertSame(5, $document->tags[1]->id);
        $this->assertSame(4, $document->tags[0]->_joinData->article_id);
        $this->assertSame(4, $document->tags[1]->_joinData->article_id);
        $this->assertSame(4, $document->tags[0]->_joinData->tag_id);
        $this->assertSame(5, $document->tags[1]->_joinData->tag_id);
    }

    /**
     * Tests saving belongsToMany records when record exists.
     */
    public function testSaveBelongsToManyJoinDataOnExistingRecord(): void
    {
        $tags = $this->getCollectionLocator()->get('Tags');
        $table = $this->getCollectionLocator()->get('Articles');

        $document = $table->find()->contain('Tags')->first();
        // not associated to the article already.
        $document->tags[] = $tags->get('000000000000000000000003');
        $document->setDirty('tags', true);

        $this->assertSame($document, $table->save($document));

        $this->assertFalse($document->isNew());
        $this->assertFalse($document->tags[0]->isNew());
        $this->assertFalse($document->tags[1]->isNew());
        $this->assertFalse($document->tags[2]->isNew());

        $this->assertNotEmpty($document->tags[0]->_joinData);
        $this->assertNotEmpty($document->tags[1]->_joinData);
        $this->assertNotEmpty($document->tags[2]->_joinData);
    }

    /**
     * Test that belongsToMany can be saved with _joinData data.
     */
    public function testSaveBelongsToManyJoinData(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $article = $articles->get('000000000000000000000001', contain: ['Tags']);
        $data = [
            'tags' => [
                ['_id' => '000000000000000000000001', '_joinData' => ['highlighted' => 1]],
                ['_id' => '000000000000000000000003'],
            ],
        ];
        $article = $articles->patchDocument($article, $data);
        $result = $articles->save($article);
        $this->assertSame($result, $article);
    }

    /**
     * Test to check that association condition are used when fetching existing
     * records to decide which records to unlink.
     */
    public function testPolymorphicBelongsToManySave(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $articles->Tags->setThrough('PolymorphicTagged')
            ->setForeignKey('foreign_key')
            ->setConditions(['PolymorphicTagged.foreign_model' => 'Articles'])
            ->setSort(['PolymorphicTagged.position' => 'ASC']);

        $document = $articles->get('000000000000000000000001', contain: ['Tags']);
        $data = [
            '_id' => '000000000000000000000001',
            'tags' => [
                [
                    '_id' => '000000000000000000000001',
                    '_joinData' => [
                        '_id' => '000000000000000000000002',
                        'foreign_model' => 'Articles',
                        'position' => 2,
                    ],
                ],
                [
                    '_id' => '000000000000000000000002',
                    '_joinData' => [
                        'foreign_model' => 'Articles',
                        'position' => 1,
                    ],
                ],
            ],
        ];
        $document = $articles->patchDocument($document, $data, ['associated' => ['Tags._joinData']]);
        $document = $articles->save($document);

        $expected = [
            [
                '_id' => '000000000000000000000001',
                'tag_id' => '000000000000000000000001',
                'foreign_key' => 1,
                'foreign_model' => 'Posts',
                'position' => 1,
            ],
            [
                '_id' => '000000000000000000000002',
                'tag_id' => '000000000000000000000001',
                'foreign_key' => 1,
                'foreign_model' => 'Articles',
                'position' => 2,
            ],
            [
                '_id' => '000000000000000000000003',
                'tag_id' => '000000000000000000000002',
                'foreign_key' => 1,
                'foreign_model' => 'Articles',
                'position' => 1,
            ],
        ];
        $result = $this->getCollectionLocator()->get('PolymorphicTagged')
            ->find('all', sort: ['id' => 'DESC'])
            ->enableHydration(false)
            ->toArray();
        $this->assertEquals($expected, $result);
    }

    /**
     * Tests saving belongsToMany records can delete all links.
     */
    public function testSaveBelongsToManyDeleteAllLinks(): void
    {
        $table = $this->getCollectionLocator()->get('Articles');
        $table->Tags->setSaveStrategy('replace');

        $document = $table->get('000000000000000000000001', contain: 'Tags');
        $this->assertCount(2, $document->tags, 'Fixture data did not change.');

        $document->tags = [];
        $result = $table->save($document);
        $this->assertSame($result, $document);
        $this->assertSame([], $document->tags, 'No tags on the entity.');

        $document = $table->get('000000000000000000000001', contain: 'Tags');
        $this->assertSame([], $document->tags, 'No tags in the db either.');
    }

    /**
     * Tests saving belongsToMany records can delete some links.
     */
    public function testSaveBelongsToManyDeleteSomeLinks(): void
    {
        $table = $this->getCollectionLocator()->get('Articles');
        $table->Tags->setSaveStrategy('replace');

        $document = $table->get('000000000000000000000001', contain: 'Tags');
        $this->assertCount(2, $document->tags, 'Fixture data did not change.');

        $tag = new Document([
            '_id' => '000000000000000000000002',
        ]);
        $document->tags = [$tag];
        $result = $table->save($document);
        $this->assertSame($result, $document);
        $this->assertCount(1, $document->tags, 'Only one tag left.');
        $this->assertEquals($tag, $document->tags[0]);

        $document = $table->get('000000000000000000000001', contain: 'Tags');
        $this->assertCount(1, $document->tags, 'Only one tag in the db.');
        $this->assertEquals($tag->id, $document->tags[0]->id);
    }

    /**
     * Test that belongsToMany ignores non-entity data.
     */
    public function testSaveBelongsToManyIgnoreNonDocumentData(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $article = $articles->get('000000000000000000000001', contain: ['Tags']);
        $article->tags = [
            '_ids' => [2, 1],
        ];
        $result = $articles->save($article);
        $this->assertSame($result, $article);
    }

    /**
     * Tests that saving a persisted and clean entity will is a no-op
     */
    public function testSaveCleanDocument(): void
    {
        $table = $this->getMockBuilder(BaseCollection::class)
            ->onlyMethods(['processSave'])
            ->getMock();
        $document = new Document(
            ['id' => 'foo'],
            ['markNew' => false, 'markClean' => true],
        );
        $table->expects($this->never())->method('processSave');
        $this->assertSame($document, $table->save($document));
    }

    /**
     * Integration test to show how to append a new tag to an article
     */
    public function testBelongsToManyIntegration(): void
    {
        $table = $this->getCollectionLocator()->get('Articles');
        $article = $table->find('all')->where(['_id' => '000000000000000000000001'])->contain(['Tags'])->first();
        $tags = $article->tags;
        $this->assertNotEmpty($tags);
        $tags[] = new Tag(['name' => 'Something New']);
        $article->tags = $tags;
        $this->assertSame($article, $table->save($article));
        $tags = $article->tags;
        $this->assertCount(3, $tags);
        $this->assertFalse($tags[2]->isNew());
        $this->assertSame(4, $tags[2]->id);
        $this->assertSame(1, $tags[2]->_joinData->article_id);
        $this->assertSame(4, $tags[2]->_joinData->tag_id);
    }

    /**
     * Tests that it is possible to do a deep save and control what associations get saved,
     * while having control of the options passed to each level of the save
     */
    public function testSaveDeepAssociationOptions(): void
    {
        $articles = $this->getMockBuilder(BaseCollection::class)
            ->onlyMethods(['insert'])
            ->setConstructorArgs([['collection' => 'articles', 'connection' => $this->connection]])
            ->getMock();
        $authors = $this->getMockBuilder(BaseCollection::class)
            ->onlyMethods(['insert'])
            ->setConstructorArgs([['collection' => 'authors', 'connection' => $this->connection]])
            ->getMock();
        $supervisors = $this->getMockBuilder(BaseCollection::class)
            ->onlyMethods(['insert'])
            ->setConstructorArgs([[
                'collection' => 'authors',
                'alias' => 'supervisors',
                'connection' => $this->connection,
            ]])
            ->getMock();
        $tags = $this->getMockBuilder(BaseCollection::class)
            ->onlyMethods(['insert'])
            ->setConstructorArgs([['collection' => 'tags', 'connection' => $this->connection]])
            ->getMock();

        $articles->belongsTo('authors', ['target' => $authors]);
        $authors->hasOne('supervisors', ['target' => $supervisors]);
        $supervisors->belongsToMany('tags', ['target' => $tags]);

        $document = new Document([
            'title' => 'bar',
            'author' => new Document([
                'name' => 'Juan',
                'supervisor' => new Document(['name' => 'Marc']),
                'tags' => [
                    new Document(['name' => 'foo']),
                ],
            ]),
        ]);
        $document->setNew(true);
        $document->author->setNew(true);
        $document->author->supervisor->setNew(true);
        $document->author->tags[0]->setNew(true);

        $articles->expects($this->once())
            ->method('insert')
            ->with($document, ['title' => 'bar'])
            ->willReturn($document);

        $authors->expects($this->once())
            ->method('insert')
            ->with($document->author, ['name' => 'Juan'])
            ->willReturn($document->author);

        $supervisors->expects($this->once())
            ->method('insert')
            ->with($document->author->supervisor, ['name' => 'Marc'])
            ->willReturn($document->author->supervisor);

        $tags->expects($this->never())->method('insert');

        $this->assertSame($document, $articles->save($document, [
            'associated' => [
                'authors' => [],
                'authors.supervisors' => [
                    'atomic' => false,
                    'associated' => false,
                ],
            ],
        ]));
    }

    /**
     * Tests that deep save options accept contain-style nested association arrays.
     */
    public function testSaveDeepAssociationContainStyleOptions(): void
    {
        $articles = $this->getMockBuilder(BaseCollection::class)
            ->onlyMethods(['insert'])
            ->setConstructorArgs([['collection' => 'articles', 'connection' => $this->connection]])
            ->getMock();
        $authors = $this->getMockBuilder(BaseCollection::class)
            ->onlyMethods(['insert'])
            ->setConstructorArgs([['collection' => 'authors', 'connection' => $this->connection]])
            ->getMock();
        $supervisors = $this->getMockBuilder(BaseCollection::class)
            ->onlyMethods(['insert'])
            ->setConstructorArgs([[
                'collection' => 'authors',
                'alias' => 'supervisors',
                'connection' => $this->connection,
            ]])
            ->getMock();

        $articles->belongsTo('authors', ['target' => $authors]);
        $authors->hasOne('supervisors', ['target' => $supervisors]);

        $document = new Document([
            'title' => 'bar',
            'author' => new Document([
                'name' => 'Juan',
                'supervisor' => new Document(['name' => 'Marc']),
            ]),
        ]);
        $document->setNew(true);
        $document->author->setNew(true);
        $document->author->supervisor->setNew(true);

        $articles->expects($this->once())
            ->method('insert')
            ->with($document, ['title' => 'bar'])
            ->willReturn($document);

        $authors->expects($this->once())
            ->method('insert')
            ->with($document->author, ['name' => 'Juan'])
            ->willReturn($document->author);

        $supervisors->expects($this->once())
            ->method('insert')
            ->with($document->author->supervisor, ['name' => 'Marc'])
            ->willReturn($document->author->supervisor);

        $this->assertSame($document, $articles->save($document, [
            'associated' => [
                'authors' => [
                    'supervisors' => [
                        'atomic' => false,
                        'associated' => false,
                    ],
                ],
            ],
        ]));
    }

    public function testBelongsToFluentInterface(): void
    {
        /** @var \TestApp\Model\Collection\ArticlesCollection $articles */
        $articles = $this->getMockBuilder(BaseCollection::class)
            ->onlyMethods(['insert'])
            ->setConstructorArgs([['collection' => 'articles', 'connection' => $this->connection]])
            ->getMock();
        $authors = $this->getMockBuilder(BaseCollection::class)
            ->onlyMethods(['insert'])
            ->setConstructorArgs([['collection' => 'authors', 'connection' => $this->connection]])
            ->getMock();

        try {
            $articles->belongsTo('Articles')
                ->setForeignKey('author_id')
                ->setTarget($authors)
                ->setBindingKey('id')
                ->setConditions([])
                ->setFinder('list')
                ->setProperty('authors')
                ->setJoinType('inner');
        } catch (BadMethodCallException) {
            $this->fail('Method chaining should be ok');
        }
        $this->assertSame('articles', $articles->getCollection());
    }

    public function testHasOneFluentInterface(): void
    {
        /** @var \TestApp\Model\Collection\AuthorsCollection $authors */
        $authors = $this->getMockBuilder(BaseCollection::class)
            ->onlyMethods(['insert'])
            ->setConstructorArgs([['collection' => 'authors', 'connection' => $this->connection]])
            ->getMock();

        try {
            $authors->hasOne('Articles')
                ->setForeignKey('author_id')
                ->setDependent(true)
                ->setBindingKey('id')
                ->setConditions([])
                ->setCascadeCallbacks(true)
                ->setFinder('list')
                ->setStrategy('select')
                ->setProperty('authors')
                ->setJoinType('inner');
        } catch (BadMethodCallException) {
            $this->fail('Method chaining should be ok');
        }
        $this->assertSame('authors', $authors->getCollection());
    }

    public function testHasManyFluentInterface(): void
    {
        /** @var \TestApp\Model\Collection\AuthorsCollection $authors */
        $authors = $this->getMockBuilder(BaseCollection::class)
            ->onlyMethods(['insert'])
            ->setConstructorArgs([['collection' => 'authors', 'connection' => $this->connection]])
            ->getMock();

        try {
            $authors->hasMany('Articles')
                ->setForeignKey('author_id')
                ->setDependent(true)
                ->setSort(['created' => 'DESC'])
                ->setBindingKey('id')
                ->setConditions([])
                ->setCascadeCallbacks(true)
                ->setFinder('list')
                ->setStrategy('select')
                ->setSaveStrategy('replace')
                ->setProperty('authors')
                ->setJoinType('inner');
        } catch (BadMethodCallException) {
            $this->fail('Method chaining should be ok');
        }
        $this->assertSame('authors', $authors->getCollection());
    }

    public function testBelongsToManyFluentInterface(): void
    {
        /** @var \TestApp\Model\Collection\AuthorsCollection $authors */
        $authors = $this->getMockBuilder(BaseCollection::class)
            ->onlyMethods(['insert'])
            ->setConstructorArgs([['collection' => 'authors', 'connection' => $this->connection]])
            ->getMock();
        try {
            $authors->belongsToMany('Articles')
                ->setForeignKey('author_id')
                ->setDependent(true)
                ->setTargetForeignKey('article_id')
                ->setBindingKey('id')
                ->setConditions([])
                ->setFinder('list')
                ->setProperty('authors')
                ->setSource($authors)
                ->setStrategy('select')
                ->setSaveStrategy('append')
                ->setThrough('author_articles')
                ->setJoinType('inner');
        } catch (BadMethodCallException) {
            $this->fail('Method chaining should be ok');
        }
        $this->assertSame('authors', $authors->getCollection());
    }

    /**
     * Integration test for linking entities with belongsToMany
     */
    public function testLinkBelongsToMany(): void
    {
        $table = $this->getCollectionLocator()->get('Articles');
        $tagsCollection = $this->getCollectionLocator()->get('Tags');
        $source = ['source' => 'Tags'];
        $options = ['markNew' => false];

        $article = new Document([
            '_id' => '000000000000000000000001',
        ], $options);

        $newTag = new Tag([
            'name' => 'Foo',
            'description' => 'Foo desc',
            'created' => null,
        ], $source);
        $tags[] = new Tag([
            '_id' => '000000000000000000000003',
        ], $options + $source);
        $tags[] = $newTag;

        $tagsCollection->save($newTag);
        $table->getAssociation('Tags')->link($article, $tags);

        $this->assertEquals($article->tags, $tags);
        foreach ($tags as $tag) {
            $this->assertFalse($tag->isNew());
        }

        $article = $table->find('all')->where(['_id' => '000000000000000000000001'])->contain(['Tags'])->first();
        $this->assertEquals($article->tags[2]->id, $tags[0]->id);
        $this->assertEqualsCanonicalizing($article->tags[3]->toArray(), $tags[1]->toArray());
    }

    /**
     * Integration test for linking entities with HasMany
     */
    public function testLinkHasMany(): void
    {
        $authors = $this->getCollectionLocator()->get('Authors');
        $articles = $this->getCollectionLocator()->get('Articles');

        $author = $authors->newDocument(['name' => 'mylux']);
        $author = $authors->save($author);

        $newArticles = $articles->newDocuments(
            [
                [
                    'title' => 'New bakery next corner',
                    'body' => 'They sell tastefull cakes',
                ],
                [
                    'title' => 'Spicy cake recipe',
                    'body' => 'chocolate and peppers',
                ],
            ],
        );

        $sizeArticles = count($newArticles);

        $this->assertTrue($authors->Articles->link($author, $newArticles));

        $this->assertCount($sizeArticles, $authors->Articles->findAllByAuthorId($author->id));
        $this->assertCount($sizeArticles, $author->articles);
        $this->assertFalse($author->isDirty('articles'));
    }

    /**
     * Integration test for linking entities with HasMany combined with ReplaceSaveStrategy. It must append, not unlinking anything
     */
    public function testLinkHasManyReplaceSaveStrategy(): void
    {
        $authors = $this->getCollectionLocator()->get('Authors');
        $articles = $this->getCollectionLocator()->get('Articles');

        $authors->Articles->setSaveStrategy('replace');

        $author = $authors->newDocument(['name' => 'mylux']);
        $author = $authors->save($author);

        $newArticles = $articles->newDocuments(
            [
                [
                    'title' => 'New bakery next corner',
                    'body' => 'They sell tastefull cakes',
                ],
                [
                    'title' => 'Spicy cake recipe',
                    'body' => 'chocolate and peppers',
                ],
            ],
        );

        $this->assertTrue($authors->Articles->link($author, $newArticles));

        $sizeArticles = count($newArticles);

        $newArticles = $articles->newDocuments(
            [
                [
                    'title' => 'Nothing but the cake',
                    'body' => 'It is all that we need',
                ],
            ],
        );
        $this->assertTrue($authors->Articles->link($author, $newArticles));

        $sizeArticles++;

        $this->assertCount($sizeArticles, $authors->Articles->findAllByAuthorId($author->id));
        $this->assertCount($sizeArticles, $author->articles);
        $this->assertFalse($author->isDirty('articles'));
    }

    /**
     * Integration test for linking entities with HasMany. The input contains already linked entities and they should not appeat duplicated
     */
    public function testLinkHasManyExisting(): void
    {
        $authors = $this->getCollectionLocator()->get('Authors');
        $articles = $this->getCollectionLocator()->get('Articles');

        $authors->Articles->setSaveStrategy('replace');

        $author = $authors->newDocument(['name' => 'mylux']);
        $author = $authors->save($author);

        $newArticles = $articles->newDocuments(
            [
                [
                    'title' => 'New bakery next corner',
                    'body' => 'They sell tastefull cakes',
                ],
                [
                    'title' => 'Spicy cake recipe',
                    'body' => 'chocolate and peppers',
                ],
            ],
        );

        $this->assertTrue($authors->Articles->link($author, $newArticles));

        $sizeArticles = count($newArticles);

        $newArticles = array_merge(
            $author->articles,
            $articles->newDocuments(
                [
                    [
                        'title' => 'Nothing but the cake',
                        'body' => 'It is all that we need',
                    ],
                ],
            ),
        );
        $this->assertTrue($authors->Articles->link($author, $newArticles));

        $sizeArticles++;

        $this->assertCount($sizeArticles, $authors->Articles->findAllByAuthorId($author->id));
        $this->assertCount($sizeArticles, $author->articles);
        $this->assertFalse($author->isDirty('articles'));
    }

    /**
     * Integration test for unlinking entities with HasMany. The association property must be cleaned
     */
    public function testUnlinkHasManyCleanProperty(): void
    {
        $authors = $this->getCollectionLocator()->get('Authors');
        $articles = $this->getCollectionLocator()->get('Articles');

        $authors->Articles->setSaveStrategy('replace');

        $author = $authors->newDocument(['name' => 'mylux']);
        $author = $authors->save($author);

        $newArticles = $articles->newDocuments(
            [
                [
                    'title' => 'New bakery next corner',
                    'body' => 'They sell tastefull cakes',
                ],
                [
                    'title' => 'Spicy cake recipe',
                    'body' => 'chocolate and peppers',
                ],
                [
                    'title' => 'Creamy cake recipe',
                    'body' => 'chocolate and cream',
                ],
            ],
        );

        $this->assertTrue($authors->Articles->link($author, $newArticles));

        $sizeArticles = count($newArticles);

        $articlesToUnlink = [$author->articles[0], $author->articles[1]];

        $authors->Articles->unlink($author, $articlesToUnlink);

        $this->assertCount($sizeArticles - count($articlesToUnlink), $authors->Articles->findAllByAuthorId($author->id));
        $this->assertCount($sizeArticles - count($articlesToUnlink), $author->articles);
        $this->assertFalse($author->isDirty('articles'));
    }

    /**
     * Integration test for unlinking entities with HasMany. The association property must stay unchanged
     */
    public function testUnlinkHasManyNotCleanProperty(): void
    {
        $authors = $this->getCollectionLocator()->get('Authors');
        $articles = $this->getCollectionLocator()->get('Articles');

        $authors->Articles->setSaveStrategy('replace');

        $author = $authors->newDocument(['name' => 'mylux']);
        $author = $authors->save($author);

        $newArticles = $articles->newDocuments(
            [
                [
                    'title' => 'New bakery next corner',
                    'body' => 'They sell tastefull cakes',
                ],
                [
                    'title' => 'Spicy cake recipe',
                    'body' => 'chocolate and peppers',
                ],
                [
                    'title' => 'Creamy cake recipe',
                    'body' => 'chocolate and cream',
                ],
            ],
        );

        $this->assertTrue($authors->Articles->link($author, $newArticles));

        $sizeArticles = count($newArticles);

        $articlesToUnlink = [$author->articles[0], $author->articles[1]];

        $authors->Articles->unlink($author, $articlesToUnlink, ['cleanProperty' => false]);

        $this->assertCount($sizeArticles - count($articlesToUnlink), $authors->Articles->findAllByAuthorId($author->id));
        $this->assertCount($sizeArticles, $author->articles);
        $this->assertFalse($author->isDirty('articles'));
    }

    /**
     * Integration test for unlinking entities with HasMany.
     * Checking that no error happens when the hasMany property is originally
     * null
     */
    public function testUnlinkHasManyEmpty(): void
    {
        $authors = $this->getCollectionLocator()->get('Authors');
        $author = $authors->get('000000000000000000000001');
        $article = $authors->Articles->get('000000000000000000000001');

        $authors->Articles->unlink($author, [$article]);
        $this->assertNotEmpty($authors);
    }

    /**
     * Integration test for replacing entities which depend on their source entity with HasMany and failing transaction. False should be returned when
     * unlinking fails while replacing even when cascadeCallbacks is enabled
     */
    public function testReplaceHasManyOnErrorDependentCascadeCallbacks(): void
    {
        $articles = $this->getMockBuilder(BaseCollection::class)
            ->onlyMethods(['deleteMany'])
            ->setConstructorArgs([[
                'connection' => $this->connection,
                'alias' => 'Articles',
                'collection' => 'articles',
            ]])
            ->getMock();

        $articles->method('deleteMany')->willReturn(false);

        $associations = new AssociationCollection();

        $hasManyArticles = $this->getMockBuilder(HasMany::class)
            ->onlyMethods(['getTarget'])
            ->setConstructorArgs([
                'articles',
                new BaseCollection(),
                [
                    'target' => $articles,
                    'foreignKey' => 'author_id',
                    'dependent' => true,
                    'cascadeCallbacks' => true,
                ],
            ])
            ->getMock();
        $hasManyArticles->method('getTarget')->willReturn($articles);

        $associations->add('Articles', $hasManyArticles);

        $authors = new BaseCollection([
            'connection' => $this->connection,
            'alias' => 'Authors',
            'collection' => 'authors',
            'associations' => $associations,
        ]);
        $authors->Articles->setSource($authors);

        $author = $authors->newDocument(['name' => 'mylux']);
        $author = $authors->save($author);

        $newArticles = $articles->newDocuments(
            [
                [
                    'title' => 'New bakery next corner',
                    'body' => 'They sell tastefull cakes',
                ],
                [
                    'title' => 'Spicy cake recipe',
                    'body' => 'chocolate and peppers',
                ],
            ],
        );

        $sizeArticles = count($newArticles);

        $this->assertTrue($authors->Articles->link($author, $newArticles));
        $this->assertEquals($authors->Articles->findAllByAuthorId($author->id)->count(), $sizeArticles);
        $this->assertCount($sizeArticles, $author->articles);

        $newArticles = array_merge(
            $author->articles,
            $articles->newDocuments(
                [
                    [
                        'title' => 'Cheese cake recipe',
                        'body' => 'The secrets of mixing salt and sugar',
                    ],
                    [
                        'title' => 'Not another piece of cake',
                        'body' => 'This is the best',
                    ],
                ],
            ),
        );
        unset($newArticles[0]);

        $this->assertFalse($authors->Articles->replace($author, $newArticles));
        $this->assertCount($sizeArticles, $authors->Articles->findAllByAuthorId($author->id));
    }

    /**
     * Integration test for replacing entities with HasMany and an empty target list. The transaction must be successful
     */
    public function testReplaceHasManyEmptyList(): void
    {
        $authors = new BaseCollection([
            'connection' => $this->connection,
            'alias' => 'Authors',
            'collection' => 'authors',
        ]);
        $authors->hasMany('Articles');

        $author = $authors->newDocument(['name' => 'mylux']);
        $author = $authors->save($author);

        $newArticles = $authors->Articles->newDocuments(
            [
                [
                    'title' => 'New bakery next corner',
                    'body' => 'They sell tastefull cakes',
                ],
                [
                    'title' => 'Spicy cake recipe',
                    'body' => 'chocolate and peppers',
                ],
            ],
        );

        $sizeArticles = count($newArticles);

        $this->assertTrue($authors->Articles->link($author, $newArticles));
        $this->assertEquals($authors->Articles->findAllByAuthorId($author->id)->count(), $sizeArticles);
        $this->assertCount($sizeArticles, $author->articles);

        $newArticles = [];

        $this->assertTrue($authors->Articles->replace($author, $newArticles));
        $this->assertCount(0, $authors->Articles->findAllByAuthorId($author->id));
    }

    /**
     * Integration test for replacing entities with HasMany and no already persisted entities. The transaction must be successful.
     * Replace operation should prevent considering 0 changed records an error when they are not found in the table
     */
    public function testReplaceHasManyNoPersistedEntities(): void
    {
        $authors = new BaseCollection([
            'connection' => $this->connection,
            'alias' => 'Authors',
            'collection' => 'authors',
        ]);
        $authors->hasMany('Articles');

        $author = $authors->newDocument(['name' => 'mylux']);
        $author = $authors->save($author);

        $newArticles = $authors->Articles->newDocuments(
            [
                [
                    'title' => 'New bakery next corner',
                    'body' => 'They sell tastefull cakes',
                ],
                [
                    'title' => 'Spicy cake recipe',
                    'body' => 'chocolate and peppers',
                ],
            ],
        );

        $authors->Articles->deleteAll(['1=1']);

        $sizeArticles = count($newArticles);

        $this->assertTrue($authors->Articles->link($author, $newArticles));
        $this->assertEquals($authors->Articles->findAllByAuthorId($author->id)->count(), $sizeArticles);
        $this->assertCount($sizeArticles, $author->articles);
        $this->assertTrue($authors->Articles->replace($author, $newArticles));
        $this->assertCount($sizeArticles, $authors->Articles->findAllByAuthorId($author->id));
    }

    /**
     * Integration test for replacing entities with HasMany.
     */
    public function testReplaceHasMany(): void
    {
        $authors = $this->getCollectionLocator()->get('Authors');
        $articles = $this->getCollectionLocator()->get('Articles');

        $author = $authors->newDocument(['name' => 'mylux']);
        $author = $authors->save($author);

        $newArticles = $articles->newDocuments(
            [
                [
                    'title' => 'New bakery next corner',
                    'body' => 'They sell tastefull cakes',
                ],
                [
                    'title' => 'Spicy cake recipe',
                    'body' => 'chocolate and peppers',
                ],
            ],
        );

        $sizeArticles = count($newArticles);

        $this->assertTrue($authors->Articles->link($author, $newArticles));

        $this->assertEquals($authors->Articles->findAllByAuthorId($author->id)->count(), $sizeArticles);
        $this->assertCount($sizeArticles, $author->articles);

        $newArticles = array_merge(
            $author->articles,
            $articles->newDocuments(
                [
                    [
                        'title' => 'Cheese cake recipe',
                        'body' => 'The secrets of mixing salt and sugar',
                    ],
                    [
                        'title' => 'Not another piece of cake',
                        'body' => 'This is the best',
                    ],
                ],
            ),
        );
        unset($newArticles[0]);

        $this->assertTrue($authors->Articles->replace($author, $newArticles));
        $this->assertCount(count($newArticles), $author->articles);
        $this->assertEquals(
            new Collection($newArticles)->extract('title')->toList(),
            new Collection($author->articles)->extract('title')->toList(),
        );
    }

    /**
     * Integration test to show how to unlink a single record from a belongsToMany
     */
    public function testUnlinkBelongsToMany(): void
    {
        $table = $this->getCollectionLocator()->get('Articles');

        $article = $table->find('all')
            ->where(['_id' => '000000000000000000000001'])
            ->contain(['Tags'])->first();

        $table->getAssociation('Tags')->unlink($article, [$article->tags[0]]);
        $this->assertCount(1, $article->tags);
        $this->assertSame(2, $article->tags[0]->get('id'));
        $this->assertFalse($article->isDirty('tags'));
    }

    /**
     * Integration test to show how to unlink multiple records from a belongsToMany
     */
    public function testUnlinkBelongsToManyMultiple(): void
    {
        $table = $this->getCollectionLocator()->get('Articles');
        $options = ['markNew' => false];

        $article = new Document(['_id' => '000000000000000000000001'], $options);
        $tags[] = new Tag(['_id' => '000000000000000000000001'], $options);
        $tags[] = new Tag(['_id' => '000000000000000000000002'], $options);

        $table->getAssociation('Tags')->unlink($article, $tags);
        $left = $table->find('all')->where(['_id' => '000000000000000000000001'])->contain(['Tags'])->first();
        $this->assertEmpty($left->tags);
    }

    /**
     * Integration test to show how to unlink multiple records from a belongsToMany
     * providing some of the joint
     */
    public function testUnlinkBelongsToManyPassingJoint(): void
    {
        $table = $this->getCollectionLocator()->get('Articles');
        $options = ['markNew' => false];

        $article = new Document(['_id' => '000000000000000000000001'], $options);
        $tags[] = new Tag(['_id' => '000000000000000000000001'], $options);
        $tags[] = new Tag(['_id' => '000000000000000000000002'], $options);

        $tags[1]->_joinData = new Document([
            'article_id' => '000000000000000000000001',
            'tag_id' => '000000000000000000000002',
        ], $options);

        $table->getAssociation('Tags')->unlink($article, $tags);
        $left = $table->find('all')->where(['_id' => '000000000000000000000001'])->contain(['Tags'])->first();
        $this->assertEmpty($left->tags);
    }

    /**
     * Integration test to show how to replace records from a belongsToMany
     */
    public function testReplacelinksBelongsToMany(): void
    {
        $table = $this->getCollectionLocator()->get('Articles');
        $options = ['markNew' => false];

        $article = new Document(['_id' => '000000000000000000000001'], $options);
        $tags[] = new Tag(['_id' => '000000000000000000000002'], $options);
        $tags[] = new Tag(['_id' => '000000000000000000000003'], $options);
        $tags[] = new Tag(['name' => 'foo']);

        $table->getAssociation('Tags')->replaceLinks($article, $tags);
        $this->assertSame(2, $article->tags[0]->id);
        $this->assertSame(3, $article->tags[1]->id);
        $this->assertSame(4, $article->tags[2]->id);

        $article = $table->find('all')->where(['_id' => '000000000000000000000001'])->contain(['Tags'])->first();
        $this->assertCount(3, $article->tags);
        $this->assertSame(2, $article->tags[0]->id);
        $this->assertSame(3, $article->tags[1]->id);
        $this->assertSame(4, $article->tags[2]->id);
        $this->assertSame('foo', $article->tags[2]->name);
    }

    /**
     * Integration test to show how remove all links from a belongsToMany
     */
    public function testReplacelinksBelongsToManyWithEmpty(): void
    {
        $table = $this->getCollectionLocator()->get('Articles');
        $options = ['markNew' => false];

        $article = new Document(['_id' => '000000000000000000000001'], $options);
        $tags = [];

        $table->getAssociation('Tags')->replaceLinks($article, $tags);
        $this->assertSame($tags, $article->tags);
        $article = $table->find('all')->where(['_id' => '000000000000000000000001'])->contain(['Tags'])->first();
        $this->assertEmpty($article->tags);
    }

    /**
     * Integration test to show how to replace records from a belongsToMany
     * passing the joint property along in the target entity
     */
    public function testReplacelinksBelongsToManyWithJoint(): void
    {
        $table = $this->getCollectionLocator()->get('Articles');
        $options = ['markNew' => false];

        $article = new Document(['_id' => '000000000000000000000001'], $options);
        $tags[] = new Tag([
            '_id' => '000000000000000000000002',
            '_joinData' => new Document([
                'article_id' => '000000000000000000000001',
                'tag_id' => '000000000000000000000002',
            ]),
        ], $options);
        $tags[] = new Tag(['_id' => '000000000000000000000003'], $options);

        $table->getAssociation('Tags')->replaceLinks($article, $tags);
        $this->assertSame($tags, $article->tags);
        $article = $table->find('all')->where(['_id' => '000000000000000000000001'])->contain(['Tags'])->first();
        $this->assertCount(2, $article->tags);
        $this->assertSame(2, $article->tags[0]->id);
        $this->assertSame(3, $article->tags[1]->id);
    }

    /**
     * Tests that options are being passed through to the internal table method calls.
     */
    public function testOptionsBeingPassedToImplicitBelongsToManyDeletesUsingSaveReplace(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');

        $tags = $articles->Tags;
        $tags->setSaveStrategy(BelongsToMany::SAVE_REPLACE)
            ->setDependent(true)
            ->setCascadeCallbacks(true);

        $actualOptions = null;
        $tags->junction()->getEventManager()->on(
            'Collection.beforeDelete',
            function (EventInterface $event, EntityInterface $document, ArrayObject $options) use (&$actualOptions): void {
                $actualOptions = $options->getArrayCopy();
            },
        );

        $article = $articles->get('000000000000000000000001');
        $article->tags = [];
        $article->setDirty('tags', true);

        $result = $articles->save($article, ['foo' => 'bar']);
        $this->assertNotEmpty($result);

        $expected = [
            '_primary' => false,
            'foo' => 'bar',
            'atomic' => true,
            'checkRules' => true,
            'checkExisting' => true,
            '_cleanOnSuccess' => true,
        ];
        $this->assertEquals($expected, $actualOptions);
    }

    /**
     * Tests that options are being passed through to the internal table method calls.
     */
    public function testOptionsBeingPassedToInternalSaveCallsUsingBelongsToManyLink(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $tags = $articles->Tags;

        $actualOptions = null;
        $tags->junction()->getEventManager()->on(
            'Collection.beforeSave',
            function (EventInterface $event, EntityInterface $document, ArrayObject $options) use (&$actualOptions): void {
                $actualOptions = $options->getArrayCopy();
            },
        );

        $article = $articles->get('000000000000000000000001');

        $result = $tags->link($article, [$tags->getTarget()->get('000000000000000000000002')], ['foo' => 'bar']);
        $this->assertTrue($result);

        $expected = [
            '_primary' => true,
            'foo' => 'bar',
            'atomic' => true,
            'checkRules' => true,
            'checkExisting' => true,
            'associated' => [
                'Articles' => [],
                'Tags' => [],
            ],
            '_cleanOnSuccess' => true,
        ];
        $this->assertEquals($expected, $actualOptions);
    }

    /**
     * Tests that options are being passed through to the internal table method calls.
     */
    public function testOptionsBeingPassedToInternalSaveCallsUsingBelongsToManyUnlink(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $tags = $articles->Tags;

        $actualOptions = null;
        $tags->junction()->getEventManager()->on(
            'Collection.beforeDelete',
            function (EventInterface $event, EntityInterface $document, ArrayObject $options) use (&$actualOptions): void {
                $actualOptions = $options->getArrayCopy();
            },
        );

        $article = $articles->get('000000000000000000000001');

        $tags->unlink($article, [$tags->getTarget()->get('000000000000000000000002')], ['foo' => 'bar']);

        $expected = [
            '_primary' => true,
            'foo' => 'bar',
            'atomic' => true,
            'checkRules' => true,
            'cleanProperty' => true,
        ];
        $this->assertEquals($expected, $actualOptions);
    }

    /**
     * Tests that options are being passed through to the internal table method calls.
     */
    public function testOptionsBeingPassedToInternalSaveAndDeleteCallsUsingBelongsToManyReplaceLinks(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $tags = $articles->Tags;

        $actualSaveOptions = null;
        $actualDeleteOptions = null;
        $tags->junction()->getEventManager()->on(
            'Collection.beforeSave',
            function (EventInterface $event, EntityInterface $document, ArrayObject $options) use (&$actualSaveOptions): void {
                $actualSaveOptions = $options->getArrayCopy();
            },
        );
        $tags->junction()->getEventManager()->on(
            'Collection.beforeDelete',
            function (EventInterface $event, EntityInterface $document, ArrayObject $options) use (&$actualDeleteOptions): void {
                $actualDeleteOptions = $options->getArrayCopy();
            },
        );

        $article = $articles->get('000000000000000000000001');

        $result = $tags->replaceLinks(
            $article,
            [
                $tags->getTarget()->newDocument(['name' => 'new']),
                $tags->getTarget()->get('000000000000000000000002'),
            ],
            ['foo' => 'bar'],
        );
        $this->assertTrue($result);

        $expected = [
            '_primary' => true,
            'foo' => 'bar',
            'atomic' => true,
            'checkRules' => true,
            'checkExisting' => true,
            'associated' => [],
            '_cleanOnSuccess' => true,
        ];
        $this->assertEquals($expected, $actualSaveOptions);

        $expected = [
            '_primary' => true,
            'foo' => 'bar',
            'atomic' => true,
            'checkRules' => true,
        ];
        $this->assertEquals($expected, $actualDeleteOptions);
    }

    /**
     * Tests that options are being passed through to the internal table method calls.
     */
    public function testOptionsBeingPassedToImplicitHasManyDeletesUsingSaveReplace(): void
    {
        $authors = $this->getCollectionLocator()->get('Authors');

        $articles = $authors->Articles;
        $articles->setSaveStrategy(HasMany::SAVE_REPLACE)
            ->setDependent(true)
            ->setCascadeCallbacks(true);

        $actualOptions = null;
        $articles->getTarget()->getEventManager()->on(
            'Collection.beforeDelete',
            function (EventInterface $event, EntityInterface $document, ArrayObject $options) use (&$actualOptions): void {
                $actualOptions = $options->getArrayCopy();
            },
        );

        $author = $authors->get('000000000000000000000001');
        $author->articles = [];
        $author->setDirty('articles', true);

        $result = $authors->save($author, ['foo' => 'bar']);
        $this->assertNotEmpty($result);

        $expected = [
            '_primary' => false,
            'foo' => 'bar',
            'atomic' => true,
            'checkRules' => true,
            'checkExisting' => true,
            'sourceCollection' => $authors,
            '_cleanOnSuccess' => true,
        ];
        $this->assertEquals($expected, $actualOptions);
    }

    /**
     * Tests that options are being passed through to the internal table method calls.
     */
    public function testOptionsBeingPassedToInternalSaveCallsUsingHasManyLink(): void
    {
        $authors = $this->getCollectionLocator()->get('Authors');
        $articles = $authors->Articles;

        $actualOptions = null;
        $articles->getTarget()->getEventManager()->on(
            'Collection.beforeSave',
            function (EventInterface $event, EntityInterface $document, ArrayObject $options) use (&$actualOptions): void {
                $actualOptions = $options->getArrayCopy();
            },
        );

        $author = $authors->get('000000000000000000000001');
        $author->articles = [];
        $author->setDirty('articles', true);

        $result = $articles->link($author, [$articles->getTarget()->get('000000000000000000000002')], ['foo' => 'bar']);
        $this->assertTrue($result);

        $expected = [
            '_primary' => true,
            'foo' => 'bar',
            'atomic' => true,
            'checkRules' => true,
            'checkExisting' => true,
            'sourceCollection' => $authors,
            'associated' => [
                'Authors' => [],
                'Tags' => [],
                'ArticlesTags' => [],
            ],
            '_cleanOnSuccess' => true,
        ];
        $this->assertEquals($expected, $actualOptions);
    }

    /**
     * Tests that options are being passed through to the internal table method calls.
     */
    public function testOptionsBeingPassedToInternalSaveCallsUsingHasManyUnlink(): void
    {
        $authors = $this->getCollectionLocator()->get('Authors');
        $articles = $authors->Articles;
        $articles->setDependent(true);
        $articles->setCascadeCallbacks(true);

        $actualOptions = null;
        $articles->getTarget()->getEventManager()->on(
            'Collection.beforeDelete',
            function (EventInterface $event, EntityInterface $document, ArrayObject $options) use (&$actualOptions): void {
                $actualOptions = $options->getArrayCopy();
            },
        );

        $author = $authors->get('000000000000000000000001');
        $author->articles = [];
        $author->setDirty('articles', true);

        $articles->unlink($author, [$articles->getTarget()->get('000000000000000000000001')], ['foo' => 'bar']);

        $expected = [
            '_primary' => true,
            'foo' => 'bar',
            'atomic' => true,
            'checkRules' => true,
            'cleanProperty' => true,
        ];
        $this->assertEquals($expected, $actualOptions);
    }

    /**
     * Tests that options are being passed through to the internal table method calls.
     */
    public function testOptionsBeingPassedToInternalSaveAndDeleteCallsUsingHasManyReplace(): void
    {
        $authors = $this->getCollectionLocator()->get('Authors');
        $articles = $authors->Articles;
        $articles->setDependent(true);
        $articles->setCascadeCallbacks(true);

        $actualSaveOptions = null;
        $actualDeleteOptions = null;
        $articles->getTarget()->getEventManager()->on(
            'Collection.beforeSave',
            function (EventInterface $event, EntityInterface $document, ArrayObject $options) use (&$actualSaveOptions): void {
                $actualSaveOptions = $options->getArrayCopy();
            },
        );
        $articles->getTarget()->getEventManager()->on(
            'Collection.beforeDelete',
            function (EventInterface $event, EntityInterface $document, ArrayObject $options) use (&$actualDeleteOptions): void {
                $actualDeleteOptions = $options->getArrayCopy();
            },
        );

        $author = $authors->get('000000000000000000000001');

        $result = $articles->replace(
            $author,
            [
                $articles->getTarget()->newDocument(['title' => 'new', 'body' => 'new']),
                $articles->getTarget()->get('000000000000000000000001'),
            ],
            ['foo' => 'bar'],
        );
        $this->assertTrue($result);

        $expected = [
            '_primary' => true,
            'foo' => 'bar',
            'atomic' => true,
            'checkRules' => true,
            'checkExisting' => true,
            'sourceCollection' => $authors,
            'associated' => [
                'Authors' => [],
                'Tags' => [],
                'ArticlesTags' => [],
            ],
            '_cleanOnSuccess' => true,
        ];
        $this->assertEquals($expected, $actualSaveOptions);

        $expected = [
            '_primary' => true,
            'foo' => 'bar',
            'atomic' => true,
            'checkRules' => true,
            'sourceCollection' => $authors,
        ];
        $this->assertEquals($expected, $actualDeleteOptions);
    }

    /**
     * Tests backwards compatibility of the the `$options` argument, formerly `$cleanProperty`.
     */
    public function testBackwardsCompatibilityForBelongsToManyUnlinkCleanPropertyOption(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $tags = $articles->Tags;

        $actualOptions = null;
        $tags->junction()->getEventManager()->on(
            'Collection.beforeDelete',
            function (EventInterface $event, EntityInterface $document, ArrayObject $options) use (&$actualOptions): void {
                $actualOptions = $options->getArrayCopy();
            },
        );

        $article = $articles->get('000000000000000000000001');

        $tags->unlink($article, [$tags->getTarget()->get('000000000000000000000001')], false);
        $this->assertArrayHasKey('cleanProperty', $actualOptions);
        $this->assertFalse($actualOptions['cleanProperty']);

        $actualOptions = null;
        $tags->unlink($article, [$tags->getTarget()->get('000000000000000000000002')]);
        $this->assertArrayHasKey('cleanProperty', $actualOptions);
        $this->assertTrue($actualOptions['cleanProperty']);
    }

    /**
     * Tests backwards compatibility of the the `$options` argument, formerly `$cleanProperty`.
     */
    public function testBackwardsCompatibilityForHasManyUnlinkCleanPropertyOption(): void
    {
        $authors = $this->getCollectionLocator()->get('Authors');
        $articles = $authors->Articles;
        $articles->setDependent(true);
        $articles->setCascadeCallbacks(true);

        $actualOptions = null;
        $articles->getTarget()->getEventManager()->on(
            'Collection.beforeDelete',
            function (EventInterface $event, EntityInterface $document, ArrayObject $options) use (&$actualOptions): void {
                $actualOptions = $options->getArrayCopy();
            },
        );

        $author = $authors->get('000000000000000000000001');
        $author->articles = [];
        $author->setDirty('articles', true);

        $articles->unlink($author, [$articles->getTarget()->get('000000000000000000000001')], false);
        $this->assertArrayHasKey('cleanProperty', $actualOptions);
        $this->assertFalse($actualOptions['cleanProperty']);

        $actualOptions = null;
        $articles->unlink($author, [$articles->getTarget()->get('000000000000000000000003')]);
        $this->assertArrayHasKey('cleanProperty', $actualOptions);
        $this->assertTrue($actualOptions['cleanProperty']);
    }

    /**
     * Tests that it is possible to call find with no arguments
     */
    public function testSimplifiedFind(): void
    {
        $table = $this->getMockBuilder(BaseCollection::class)
            ->onlyMethods(['findAll'])
            ->setConstructorArgs([[
                'connection' => $this->connection,
                'schema' => ['id' => ['type' => 'integer']],
            ]])
            ->getMock();

        $table->expects($this->once())->method('findAll');
        $table->find();
    }

    public static function providerForTestGet(): array
    {
        return [
            [['fields' => ['id']]],
            [['fields' => ['id'], 'cache' => null]],
        ];
    }

    /**
     * Test that get() will use the primary key for searching and return the first
     * entity found
     *
     * @param array $options
     */
    #[DataProvider('providerForTestGet')]
    public function testGet($options): void
    {
        $table = $this->getMockBuilder(BaseCollection::class)
            ->onlyMethods(['selectQuery'])
            ->setConstructorArgs([[
                'connection' => $this->connection,
                'schema' => [
                    'id' => ['type' => 'integer'],
                    'bar' => ['type' => 'integer'],
                    '_constraints' => ['primary' => ['type' => 'primary', 'columns' => ['bar']]],
                ],
            ]])
            ->getMock();

        $query = $this->getMockBuilder(SelectQuery::class)
            ->onlyMethods(['addDefaultTypes', 'firstOrFail', 'where', 'cache', 'applyOptions'])
            ->setConstructorArgs([$table])
            ->getMock();

        $table->expects($this->once())->method('selectQuery')
            ->willReturn($query);

        $document = new Document();
        $query->expects($this->once())->method('applyOptions')
            ->with(['fields' => ['id']]);
        $query->expects($this->once())->method('where')
            ->with([$table->getAlias() . '.bar' => 10])
            ->willReturnSelf();
        $query->expects($this->never())->method('cache');
        $query->expects($this->once())->method('firstOrFail')
            ->willReturn($document);

        $result = $table->get('000000000000000000000010', ...$options);
        $this->assertSame($document, $result);
    }

    public static function providerForTestGetWithCache(): array
    {
        return [
            [
                ['fields' => ['id'], 'cache' => 'default'],
                'get-test-table_name-[10]', 'default', 10,
            ],
            [
                ['fields' => ['id'], 'cache' => 'default'],
                'get-test-table_name-["uuid"]', 'default', 'uuid',
            ],
            [
                ['fields' => ['id'], 'cache' => 'default'],
                'get-test-table_name-["2020-07-08T00:00:00+00:00"]', 'default', new DateTime('2020-07-08'),
            ],
            [
                ['fields' => ['id'], 'cache' => 'default', 'cacheKey' => 'custom_key'],
                'custom_key', 'default', 10,
            ],
        ];
    }

    /**
     * Test that get() will use the cache.
     *
     * @param array $options
     * @param string $cacheKey
     * @param string $cacheConfig
     * @param mixed $primaryKey
     */
    #[DataProvider('providerForTestGetWithCache')]
    public function testGetWithCache($options, $cacheKey, $cacheConfig, $primaryKey): void
    {
        $table = $this->getMockBuilder(BaseCollection::class)
            ->onlyMethods(['selectQuery'])
            ->setConstructorArgs([[
                'connection' => $this->connection,
                'schema' => [
                    'id' => ['type' => 'integer'],
                    'bar' => ['type' => 'integer'],
                    '_constraints' => ['primary' => ['type' => 'primary', 'columns' => ['bar']]],
                ],
            ]])
            ->getMock();
        $table->setCollection('table_name');

        $query = $this->getMockBuilder(SelectQuery::class)
            ->onlyMethods(['addDefaultTypes', 'firstOrFail', 'where', 'cache', 'applyOptions'])
            ->setConstructorArgs([$table])
            ->getMock();

        $table->expects($this->once())->method('selectQuery')
            ->willReturn($query);

        $document = new Document();
        $query->expects($this->once())->method('applyOptions')
            ->with(['fields' => ['id']]);
        $query->expects($this->once())->method('where')
            ->with([$table->getAlias() . '.bar' => $primaryKey])
            ->willReturnSelf();
        $query->expects($this->once())->method('cache')
            ->with($cacheKey, $cacheConfig)
            ->willReturnSelf();
        $query->expects($this->once())->method('firstOrFail')
            ->willReturn($document);

        $result = $table->get($primaryKey, ...$options);
        $this->assertSame($document, $result);
    }

    /**
     * Tests that get() will throw an exception if the record was not found
     */
    public function testGetNotFoundException(): void
    {
        $this->expectException(RecordNotFoundException::class);
        $this->expectExceptionMessage('Record not found in table `articles`.');
        $table = new BaseCollection([
            'name' => 'Articles',
            'connection' => $this->connection,
            'collection' => 'articles',
        ]);
        $table->get('000000000000000000000010');
    }

    /**
     * Test that an exception is raised when there are not enough keys.
     */
    public function testGetExceptionOnNoData(): void
    {
        $this->expectException(InvalidPrimaryKeyException::class);
        $this->expectExceptionMessage('Record not found in table `articles` with primary key `[NULL]`.');
        $table = new BaseCollection([
            'name' => 'Articles',
            'connection' => $this->connection,
            'collection' => 'articles',
        ]);
        $table->get(null);
    }

    /**
     * Test that an exception is raised when there are too many keys.
     */
    public function testGetExceptionOnTooMuchData(): void
    {
        $this->expectException(InvalidPrimaryKeyException::class);
        $this->expectExceptionMessage("Record not found in table `articles` with primary key `[1, 'two']`.");
        $table = new BaseCollection([
            'name' => 'Articles',
            'connection' => $this->connection,
            'collection' => 'articles',
        ]);
        $table->get([1, 'two']);
    }

    /**
     * Tests that patchEntity delegates the task to the marshaller and passed
     * all associations
     */
    public function testPatchDocumentMarshallerUsage(): void
    {
        $table = $this->getMockBuilder(BaseCollection::class)
            ->onlyMethods(['marshaller'])
            ->getMock();
        $marshaller = $this->getMockBuilder(Marshaller::class)
            ->setConstructorArgs([$table])
            ->getMock();
        $table->belongsTo('users');
        $table->hasMany('articles');
        $table->expects($this->once())->method('marshaller')
            ->willReturn($marshaller);

        $document = new Document();
        $data = ['foo' => 'bar'];
        $marshaller->expects($this->once())
            ->method('merge')
            ->with($document, $data, ['associated' => ['users', 'articles']])
            ->willReturn($document);
        $table->patchDocument($document, $data);
    }

    /**
     * Tests patchEntity in a simple scenario. The tests for Marshaller cover
     * patch scenarios in more depth.
     */
    public function testPatchDocument(): void
    {
        $table = $this->getCollectionLocator()->get('Articles');
        $document = new Document(['title' => 'old title'], ['markNew' => false]);
        $data = ['title' => 'new title'];
        $document = $table->patchDocument($document, $data);

        $this->assertSame($data['title'], $document->title);
        $this->assertFalse($document->isNew(), 'entity should not be new.');
    }

    /**
     * Tests that patchEntities delegates the task to the marshaller and passed
     * all associations
     */
    public function testPatchEntitiesMarshallerUsage(): void
    {
        $table = $this->getMockBuilder(BaseCollection::class)
            ->onlyMethods(['marshaller'])
            ->getMock();
        $marshaller = $this->getMockBuilder(Marshaller::class)
            ->setConstructorArgs([$table])
            ->getMock();
        $table->belongsTo('users');
        $table->hasMany('articles');
        $table->expects($this->once())->method('marshaller')
            ->willReturn($marshaller);

        $documents = [new Document()];
        $data = [['foo' => 'bar']];
        $marshaller->expects($this->once())
            ->method('mergeMany')
            ->with($documents, $data, ['associated' => ['users', 'articles']])
            ->willReturn($documents);
        $table->patchDocuments($documents, $data);
    }

    /**
     * Tests patchEntities in a simple scenario. The tests for Marshaller cover
     * patch scenarios in more depth.
     */
    public function testPatchEntities(): void
    {
        $table = $this->getCollectionLocator()->get('Articles');
        $documents = $table->find()->limit(2)->toArray();

        $data = [
            ['id' => $documents[0]->id, 'title' => 'new title'],
            ['id' => $documents[1]->id, 'title' => 'new title2'],
        ];
        $documents = $table->patchDocuments($documents, $data);
        foreach ($documents as $i => $document) {
            $this->assertFalse($document->isNew(), 'entities should not be new.');
            $this->assertSame($data[$i]['title'], $document->title);
        }
    }

    /**
     * Tests that the RepositoryInterface-compatible Entity-named methods
     * delegate to their Document-named counterparts.
     */
    public function testInterfaceWrappersDelegateToDocumentMethods(): void
    {
        $table = $this->getCollectionLocator()->get('Articles');
        $this->assertInstanceOf(RepositoryInterface::class, $table);

        $document = $table->newEntity(['title' => 'wrapper title']);
        $this->assertInstanceOf(Document::class, $document);
        $this->assertSame('wrapper title', $document->title);

        $documents = $table->newEntities([['title' => 'a'], ['title' => 'b']]);
        $this->assertCount(2, $documents);
        $this->assertInstanceOf(Document::class, $documents[0]);

        $empty = $table->newEmptyEntity();
        $this->assertInstanceOf(Document::class, $empty);
        $this->assertTrue($empty->isNew());

        $patched = $table->patchEntity($document, ['title' => 'patched']);
        $this->assertSame('patched', $patched->title);

        $patchedMany = $table->patchEntities($documents, [
            ['id' => $documents[0]->getId(), 'title' => 'patched a'],
            ['id' => $documents[1]->getId(), 'title' => 'patched b'],
        ]);
        $this->assertSame('patched a', $patchedMany[0]->title);
        $this->assertSame('patched b', $patchedMany[1]->title);
    }

    /**
     * Tests __debugInfo
     */
    public function testDebugInfo(): void
    {
        $articles = $this->getCollectionLocator()->get('articles');
        $articles->addBehavior('Timestamp');
        $result = $articles->__debugInfo();
        $expected = [
            'registryAlias' => 'articles',
            'collection' => 'articles',
            'alias' => 'articles',
            'entityClass' => Article::class,
            'associations' => ['Authors', 'Tags', 'ArticlesTags'],
            'behaviors' => ['Timestamp'],
            'defaultConnection' => 'default',
            'connectionName' => 'test',
        ];
        $this->assertEquals($expected, $result);

        $articles = $this->getCollectionLocator()->get('Foo.Articles');
        $result = $articles->__debugInfo();
        $expected = [
            'registryAlias' => 'Foo.Articles',
            'collection' => 'articles',
            'alias' => 'Articles',
            'entityClass' => Document::class,
            'associations' => [],
            'behaviors' => [],
            'defaultConnection' => 'default',
            'connectionName' => 'test',
        ];
        $this->assertEquals($expected, $result);
    }

    /**
     * Test that findOrCreate creates a new entity, and then finds that entity.
     */
    public function testFindOrCreateNewDocument(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');

        $callbackExecuted = false;
        $firstArticle = $articles->findOrCreate(['title' => 'Not there'], function ($article) use (&$callbackExecuted): void {
            $this->assertInstanceOf(EntityInterface::class, $article);
            $article->body = 'New body';
            $callbackExecuted = true;
        });
        $this->assertTrue($callbackExecuted);
        $this->assertFalse($firstArticle->isNew());
        $this->assertNotNull($firstArticle->id);
        $this->assertSame('Not there', $firstArticle->title);
        $this->assertSame('New body', $firstArticle->body);

        $secondArticle = $articles->findOrCreate(['title' => 'Not there'], function ($article): void {
            $this->fail('Should not be called for existing entities.');
        });
        $this->assertFalse($secondArticle->isNew());
        $this->assertNotNull($secondArticle->id);
        $this->assertSame('Not there', $secondArticle->title);
        $this->assertEquals($firstArticle->id, $secondArticle->id);
    }

    /**
     * Test that findOrCreate finds fixture data.
     */
    public function testFindOrCreateExistingDocument(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');

        $article = $articles->findOrCreate(['title' => 'First Article'], function ($article): void {
            $this->fail('Should not be called for existing entities.');
        });
        $this->assertFalse($article->isNew());
        $this->assertNotNull($article->id);
        $this->assertSame('First Article', $article->title);
    }

    /**
     * Test that findOrCreate uses the search conditions as defaults for new entity.
     */
    public function testFindOrCreateDefaults(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');

        $callbackExecuted = false;
        $article = $articles->findOrCreate(
            ['author_id' => '000000000000000000000002', 'title' => 'First Article'],
            function ($article) use (&$callbackExecuted): void {
                $this->assertInstanceOf(EntityInterface::class, $article);
                $article->patch(['published' => 'N', 'body' => 'New body']);
                $callbackExecuted = true;
            },
        );
        $this->assertTrue($callbackExecuted);
        $this->assertFalse($article->isNew());
        $this->assertNotNull($article->id);
        $this->assertSame('First Article', $article->title);
        $this->assertSame('New body', $article->body);
        $this->assertSame('N', $article->published);
        $this->assertSame(2, $article->author_id);

        $query = $articles->find()->where(['author_id' => '000000000000000000000002', 'title' => 'First Article']);
        $article = $articles->findOrCreate($query);
        $this->assertSame('First Article', $article->title);
        $this->assertSame(2, $article->author_id);
        $this->assertFalse($article->isNew());
    }

    /**
     * Test that findOrCreate adds new entity without using a callback.
     */
    public function testFindOrCreateNoCallable(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');

        $article = $articles->findOrCreate(['title' => 'Just Something New']);
        $this->assertFalse($article->isNew());
        $this->assertNotNull($article->id);
        $this->assertSame('Just Something New', $article->title);
    }

    /**
     * Test that findOrCreate executes search conditions as a callable.
     */
    public function testFindOrCreateSearchCallable(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');

        $calledOne = false;
        $calledTwo = false;
        $article = $articles->findOrCreate(function ($query) use (&$calledOne): void {
            $this->assertInstanceOf(SelectQuery::class, $query);
            $query->where(['title' => 'Something Else']);
            $calledOne = true;
        }, function ($article) use (&$calledTwo): void {
            $this->assertInstanceOf(EntityInterface::class, $article);
            $article->title = 'Set Defaults Here';
            $calledTwo = true;
        });
        $this->assertTrue($calledOne);
        $this->assertTrue($calledTwo);
        $this->assertFalse($article->isNew());
        $this->assertNotNull($article->id);
        $this->assertSame('Set Defaults Here', $article->title);
    }

    /**
     * Test that findOrCreate options disable defaults.
     */
    public function testFindOrCreateNoDefaults(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');

        $article = $articles->findOrCreate(['title' => 'A New Article', 'published' => 'Y'], function ($article): void {
            $this->assertInstanceOf(EntityInterface::class, $article);
            $article->title = 'A Different Title';
        }, ['defaults' => false]);
        $this->assertFalse($article->isNew());
        $this->assertNotNull($article->id);
        $this->assertSame('A Different Title', $article->title);
        $this->assertNull($article->published, 'Expected Null since defaults are disabled.');
    }

    /**
     * Test that findOrCreate executes callable inside transaction.
     */
    public function testFindOrCreateTransactions(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $articles->getEventManager()->on('Model.afterSaveCommit', function (EventInterface $event, EntityInterface $document, ArrayObject $options): void {
            $document->afterSaveCommit = true;
        });

        $article = $articles->findOrCreate(function ($query): void {
            $this->assertInstanceOf(SelectQuery::class, $query);
            $query->where(['title' => 'Find Something New']);
            $this->assertTrue($this->connection->inTransaction());
        }, function ($article): void {
            $this->assertInstanceOf(EntityInterface::class, $article);
            $article->title = 'Success';
            $this->assertTrue($this->connection->inTransaction());
        });
        $this->assertFalse($article->isNew());
        $this->assertNotNull($article->id);
        $this->assertSame('Success', $article->title);
        $this->assertTrue($article->afterSaveCommit);
    }

    /**
     * Test that findOrCreate executes callable without transaction.
     */
    public function testFindOrCreateNoTransaction(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');

        $article = $articles->findOrCreate(function (SelectQuery $query): void {
            $this->assertInstanceOf(SelectQuery::class, $query);
            $query->where(['title' => 'Find Something New']);
            $this->assertFalse($this->connection->inTransaction());
        }, function ($article): void {
            $this->assertInstanceOf(EntityInterface::class, $article);
            $this->assertFalse($this->connection->inTransaction());
            $article->title = 'Success';
        }, ['atomic' => false]);
        $this->assertFalse($article->isNew());
        $this->assertNotNull($article->id);
        $this->assertSame('Success', $article->title);
    }

    /**
     * Test that findOrCreate throws a PersistenceFailedException when it cannot save
     * an entity created from $search
     */
    public function testFindOrCreateWithInvalidDocument(): void
    {
        $this->expectException(PersistenceFailedException::class);
        $this->expectExceptionMessage(
            'Document findOrCreate failure. ' .
            'Found the following errors (title._empty: "This field cannot be left empty").',
        );

        $articles = $this->getCollectionLocator()->get('Articles');
        $validator = new Validator();
        $validator->notEmptyString('title');
        $articles->setValidator('default', $validator);

        $articles->findOrCreate(['title' => '']);
    }

    /**
     * Test that findOrCreate allows patching of all $search keys
     */
    public function testFindOrCreatePatchableFields(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $articles->setDocumentClass(ProtectedEntity::class);
        $validator = new Validator();
        $validator->notBlank('title');
        $articles->setValidator('default', $validator);

        $article = $articles->findOrCreate(['title' => 'test']);
        $this->assertInstanceOf(ProtectedEntity::class, $article);
        $this->assertSame('test', $article->title);
    }

    /**
     * Test that findOrCreate cannot accidentally bypass required validation.
     */
    public function testFindOrCreatePartialValidation(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $articles->setDocumentClass(ProtectedEntity::class);
        $validator = new Validator();
        $validator->notBlank('title')->requirePresence('title', 'create');
        $validator->notBlank('body')->requirePresence('body', 'create');
        $articles->setValidator('default', $validator);

        $this->expectException(PersistenceFailedException::class);
        $this->expectExceptionMessage(
            'Document findOrCreate failure. ' .
            'Found the following errors (title._required: "This field is required").',
        );

        $articles->findOrCreate(['body' => 'test']);
    }

    /**
     * Test that findOrCreate with array data.
     */
    public function testFindOrCreateArrayData(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');

        $firstArticle = $articles->findOrCreate(['title' => 'Some title'], ['body' => 'Some body']);
        $this->assertFalse($firstArticle->isNew());
        $this->assertNotNull($firstArticle->id);
        $this->assertSame('Some title', $firstArticle->title);
        $this->assertSame('Some body', $firstArticle->body);

        $secondArticle = $articles->findOrCreate(['title' => 'Some title'], ['body' => 'Different body']);
        $this->assertFalse($secondArticle->isNew());
        $this->assertNotNull($secondArticle->id);
        $this->assertSame('Some title', $secondArticle->title);
        $this->assertEquals($firstArticle->id, $secondArticle->id);
        $this->assertSame('Some body', $secondArticle->body);
    }

    /**
     * Test that creating a table fires the initialize event.
     */
    public function testInitializeEvent(): void
    {
        $count = 0;
        $cb = function (EventInterface $event) use (&$count): void {
            $count++;
        };
        EventManager::instance()->on('Model.initialize', $cb);
        $this->getCollectionLocator()->get('Articles');

        $this->assertSame(1, $count, 'Callback should be called');
        EventManager::instance()->off('Model.initialize', $cb);
    }

    /**
     * Tests the hasFinder method
     */
    public function testHasFinder(): void
    {
        $table = $this->getCollectionLocator()->get('articles');
        $table->addBehavior('Sluggable');

        $this->assertTrue($table->hasFinder('list'));
        $this->assertTrue($table->hasFinder('noSlug'));
        $this->assertFalse($table->hasFinder('noFind'));
    }

    /**
     * Tests that calling validator() trigger the buildValidator event
     */
    public function testBuildValidatorEvent(): void
    {
        $count = 0;
        $cb = function (EventInterface $event) use (&$count): void {
            $count++;
        };
        EventManager::instance()->on('Model.buildValidator', $cb);
        $articles = $this->getCollectionLocator()->get('Articles');
        $articles->getValidator();
        $this->assertSame(1, $count, 'Callback should be called');

        $articles->getValidator();
        $this->assertSame(1, $count, 'Callback should be called only once');
    }

    /**
     * Tests the validateUnique method with different combinations
     */
    public function testValidateUnique(): void
    {
        $table = $this->getCollectionLocator()->get('Users');
        $validator = new Validator();
        $validator->setProvider('table', $table);
        $validator->add('username', 'unique', ['rule' => 'validateUnique', 'provider' => 'table']);

        $data = ['username' => ['larry', 'notthere']];
        $this->assertNotEmpty($validator->validate($data));

        $data = ['username' => 'larry'];
        $this->assertNotEmpty($validator->validate($data));

        $data = ['username' => 'jose'];
        $this->assertEmpty($validator->validate($data));

        $data = ['username' => 'larry', '_id' => '000000000000000000000003'];
        $this->assertEmpty($validator->validate($data, false));

        $data = ['username' => 'larry', '_id' => '000000000000000000000003'];
        $this->assertNotEmpty($validator->validate($data));

        $data = ['username' => 'larry'];
        $this->assertNotEmpty($validator->validate($data, false));
    }

    /**
     * Tests the validateUnique method with scope
     */
    public function testValidateUniqueScope(): void
    {
        $table = $this->getCollectionLocator()->get('Users');
        $validator = new Validator();
        $validator->setProvider('table', $table);
        $validator->add('username', 'unique', [
            'rule' => ['validateUnique', ['derp' => 'erp', 'scope' => 'id']],
            'provider' => 'table',
        ]);
        $data = ['username' => 'larry', '_id' => '000000000000000000000003'];
        $this->assertNotEmpty($validator->validate($data));

        $data = ['username' => 'larry', '_id' => '000000000000000000000001'];
        $this->assertEmpty($validator->validate($data));

        $data = ['username' => 'jose'];
        $this->assertEmpty($validator->validate($data));
    }

    /**
     * Tests the validateUnique method with options
     */
    public function testValidateUniqueMultipleNulls(): void
    {
        $document = new Document([
            '_id' => '000000000000000000000009',
            'site_id' => '000000000000000000000001',
            'author_id' => null,
            'title' => 'Null title',
        ]);

        $table = $this->getCollectionLocator()->get('SiteArticles');
        $table->save($document);

        $validator = new Validator();
        $validator->setProvider('table', $table);
        $validator->add('site_id', 'unique', [
            'rule' => [
                'validateUnique',
                [
                    'allowMultipleNulls' => false,
                    'scope' => ['author_id'],
                ],
            ],
            'provider' => 'table',
            'message' => 'Must be unique.',
        ]);

        $data = ['site_id' => '000000000000000000000001', 'author_id' => null, 'title' => 'Null dupe'];
        $expected = ['site_id' => ['unique' => 'Must be unique.']];
        $this->assertEquals($expected, $validator->validate($data));
    }

    /**
     * Tests that the callbacks receive the expected types of arguments.
     */
    public function testCallbackArgumentTypes(): void
    {
        $table = $this->getCollectionLocator()->get('articles');
        $table->belongsTo('authors');

        $eventManager = $table->getEventManager();

        $associationBeforeFindCount = 0;
        $table->getAssociation('authors')->getTarget()->getEventManager()->on(
            'Collection.beforeFind',
            function (EventInterface $event, SelectQuery $query, ArrayObject $options, bool $primary) use (&$associationBeforeFindCount): void {
                $this->assertIsBool($primary);
                $associationBeforeFindCount++;
            },
        );

        $beforeFindCount = 0;
        $eventManager->on(
            'Collection.beforeFind',
            function (EventInterface $event, SelectQuery $query, ArrayObject $options, bool $primary) use (&$beforeFindCount): void {
                $this->assertIsBool($primary);
                $beforeFindCount++;
            },
        );
        $table->find()->contain('authors')->first();
        $this->assertSame(1, $associationBeforeFindCount);
        $this->assertSame(1, $beforeFindCount);

        $buildValidatorCount = 0;
        $eventManager->on(
            'Model.buildValidator',
            $callback = function (EventInterface $event, Validator $validator, $name) use (&$buildValidatorCount): void {
                $this->assertIsString($name);
                $buildValidatorCount++;
            },
        );
        $table->getValidator();
        $this->assertSame(1, $buildValidatorCount);
        $buildRulesCount = 0;
        $beforeRulesCount = 0;
        $afterRulesCount = 0;
        $beforeSaveCount = 0;
        $afterSaveCount = 0;
        $eventManager->on(
            'Model.buildRules',
            function (EventInterface $event, RulesChecker $rules) use (&$buildRulesCount): void {
                $buildRulesCount++;
            },
        );
        $eventManager->on(
            'Collection.beforeRules',
            function (EventInterface $event, EntityInterface $document, ArrayObject $options, $operation) use (&$beforeRulesCount): void {
                $this->assertIsString($operation);
                $beforeRulesCount++;
            },
        );
        $eventManager->on(
            'Collection.afterRules',
            function (EventInterface $event, EntityInterface $document, ArrayObject $options, $result, $operation) use (&$afterRulesCount): void {
                $this->assertIsBool($result);
                $this->assertIsString($operation);
                $afterRulesCount++;
            },
        );
        $eventManager->on(
            'Collection.beforeSave',
            function (EventInterface $event, EntityInterface $document, ArrayObject $options) use (&$beforeSaveCount): void {
                $beforeSaveCount++;
            },
        );
        $eventManager->on(
            'Collection.afterSave',
            $afterSaveCallback = function (EventInterface $event, EntityInterface $document, ArrayObject $options) use (&$afterSaveCount): void {
                $afterSaveCount++;
            },
        );
        $document = new Document(['title' => 'Title']);
        $this->assertNotFalse($table->save($document));
        $this->assertSame(1, $buildRulesCount);
        $this->assertSame(1, $beforeRulesCount);
        $this->assertSame(1, $afterRulesCount);
        $this->assertSame(1, $beforeSaveCount);
        $this->assertSame(1, $afterSaveCount);
        $beforeDeleteCount = 0;
        $afterDeleteCount = 0;
        $eventManager->on(
            'Collection.beforeDelete',
            function (EventInterface $event, EntityInterface $document, ArrayObject $options) use (&$beforeDeleteCount): void {
                $beforeDeleteCount++;
            },
        );
        $eventManager->on(
            'Collection.afterDelete',
            function (EventInterface $event, EntityInterface $document, ArrayObject $options) use (&$afterDeleteCount): void {
                $afterDeleteCount++;
            },
        );
        $this->assertTrue($table->delete($document, ['checkRules' => false]));
        $this->assertSame(1, $beforeDeleteCount);
        $this->assertSame(1, $afterDeleteCount);
    }

    /**
     * Tests that calling newEmptyDocument() on a collection sets the right source alias.
     */
    public function testSetDocumentSource(): void
    {
        $table = $this->getCollectionLocator()->get('Articles');
        $this->assertSame('Articles', $table->newEmptyDocument()->getSource());

        $this->loadPlugins(['TestPlugin']);
        $table = $this->getCollectionLocator()->get('TestPlugin.Comments');
        $this->assertSame('TestPlugin.Comments', $table->newEmptyDocument()->getSource());
    }

    /**
     * Tests that passing a coned entity that was marked as new to save() will
     * actually save it as a new entity
     */
    public function testSaveWithClonedDocument(): void
    {
        $table = $this->getCollectionLocator()->get('Articles');
        $article = $table->get('000000000000000000000001');

        $cloned = clone $article;
        $cloned->unset('id');
        $cloned->setNew(true);
        $this->assertSame($cloned, $table->save($cloned));
        $this->assertEquals(
            $article->extract(['title', 'author_id']),
            $cloned->extract(['title', 'author_id']),
        );
        $this->assertSame(4, $cloned->id);
    }

    /**
     * Tests that the _ids notation can be used for HasMany
     */
    public function testSaveHasManyWithIds(): void
    {
        $data = [
            'username' => 'lux',
            'password' => 'passphrase',
            'comments' => [
                '_ids' => [1, 2],
            ],
        ];

        $userCollection = $this->getCollectionLocator()->get('Users');
        $userCollection->hasMany('Comments');
        $savedUser = $userCollection->save($userCollection->newDocument($data, ['associated' => ['Comments']]));
        $retrievedUser = $userCollection->find('all')->where(['id' => $savedUser->id])->contain(['Comments'])->first();
        $this->assertEquals($savedUser->comments[0]->user_id, $retrievedUser->comments[0]->user_id);
        $this->assertEquals($savedUser->comments[1]->user_id, $retrievedUser->comments[1]->user_id);
    }

    /**
     * Tests that on second save, entities for the has many relation are not marked
     * as dirty unnecessarily. This helps avoid wasteful database statements and makes
     * for a cleaner transaction log
     */
    public function testSaveHasManyNoWasteSave(): void
    {
        $data = [
            'username' => 'lux',
            'password' => 'passphrase',
            'comments' => [
                '_ids' => [1, 2],
            ],
        ];

        $userCollection = $this->getCollectionLocator()->get('Users');
        $userCollection->hasMany('Comments');
        $savedUser = $userCollection->save($userCollection->newDocument($data, ['associated' => ['Comments']]));

        $counter = 0;
        $userCollection->Comments
            ->getEventManager()
            ->on('Collection.afterSave', function (EventInterface $event, $document) use (&$counter): void {
                if ($document->isDirty()) {
                    $counter++;
                }
            });

        $savedUser->comments[] = $userCollection->Comments->get('000000000000000000000005');
        $this->assertCount(3, $savedUser->comments);
        $savedUser->setDirty('comments', true);
        $userCollection->save($savedUser);
        $this->assertSame(1, $counter);
    }

    /**
     * Tests that on second save, entities for the belongsToMany relation are not marked
     * as dirty unnecessarily. This helps avoid wasteful database statements and makes
     * for a cleaner transaction log
     */
    public function testSaveBelongsToManyNoWasteSave(): void
    {
        $data = [
            'title' => 'foo',
            'body' => 'bar',
            'tags' => [
                '_ids' => [1, 2],
            ],
        ];

        $table = $this->getCollectionLocator()->get('Articles');
        $article = $table->save($table->newDocument($data, ['associated' => ['Tags']]));

        $counter = 0;
        $table->Tags->junction()
            ->getEventManager()
            ->on('Collection.afterSave', function (EventInterface $event, $document) use (&$counter): void {
                if ($document->isDirty()) {
                    $counter++;
                }
            });

        $article->tags[] = $table->Tags->get('000000000000000000000003');
        $this->assertCount(3, $article->tags);
        $article->setDirty('tags', true);
        $table->save($article);
        $this->assertSame(1, $counter);
    }

    /**
     * Tests that after saving then entity contains the right primary
     * key casted to the right type
     */
    public function testSaveCorrectPrimaryKeyType(): void
    {
        $document = new Document([
            'username' => 'superuser',
            'created' => new DateTime('2013-10-10 00:00'),
            'updated' => new DateTime('2013-10-10 00:00'),
        ], ['markNew' => true]);

        $table = $this->getCollectionLocator()->get('Users');
        $this->assertSame($document, $table->save($document));
        $this->assertSame(self::$nextUserId, $document->id);
    }

    /**
     * Tests entity clean()
     */
    public function testDocumentClean(): void
    {
        $table = $this->getCollectionLocator()->get('Articles');
        $table->getValidator()->requirePresence('body');
        $document = $table->newDocument(['title' => 'mark']);

        $document->setDirty('title', true);
        $document->setInvalidField('title', 'albert');

        $this->assertNotEmpty($document->getErrors());
        $this->assertTrue($document->isDirty());
        $this->assertEquals(['title' => 'albert'], $document->getInvalid());

        $document->title = 'alex';
        $this->assertSame($document->getOriginal('title'), 'mark');

        $document->clean();

        $this->assertEmpty($document->getErrors());
        $this->assertFalse($document->isDirty());
        $this->assertEquals([], $document->getInvalid());
        $this->assertSame($document->getOriginal('title'), 'alex');
    }

    /**
     * Tests the loadInto() method
     */
    public function testLoadIntoDocument(): void
    {
        $table = $this->getCollectionLocator()->get('Authors');
        $table->hasMany('SiteArticles');

        $document = $table->get('000000000000000000000001');
        $result = $table->loadInto($document, ['SiteArticles', 'Articles.Tags']);
        $this->assertSame($document, $result);

        $expected = $table->get('000000000000000000000001', contain: ['SiteArticles', 'Articles.Tags']);
        $this->assertEquals($expected->site_articles, $result->site_articles);
        $this->assertEquals($expected->articles, $result->articles);
    }

    /**
     * Tests that it is possible to pass conditions and fields to loadInto()
     */
    public function testLoadIntoWithConditions(): void
    {
        $table = $this->getCollectionLocator()->get('Authors');
        $table->hasMany('SiteArticles');

        $document = $table->get('000000000000000000000001');
        $options = [
            'SiteArticles' => ['fields' => ['title', 'author_id']],
            'Articles.Tags' => function ($q) {
                return $q->where(['Tags.name' => 'tag2']);
            },
        ];
        $result = $table->loadInto($document, $options);
        $this->assertSame($document, $result);
        $expected = $table->get('000000000000000000000001', contain: $options);
        $this->assertEquals($expected->site_articles, $result->site_articles);
        $this->assertEquals(['title', 'author_id'], $expected->site_articles[0]->getOriginalFields());
        $this->assertEquals($expected->articles, $result->articles);
        $this->assertSame('tag2', $expected->articles[0]->tags[0]->name);
    }

    /**
     * Tests loadInto() with a belongsTo association
     */
    public function testLoadBelongsTo(): void
    {
        $table = $this->getCollectionLocator()->get('Articles');

        $document = $table->get('000000000000000000000002');
        $result = $table->loadInto($document, ['Authors']);
        $this->assertSame($document, $result);

        $expected = $table->get('000000000000000000000002', contain: ['Authors']);
        $this->assertEquals($expected, $document);
    }

    /**
     * Tests loadInto() with a belongsTo association with a join and contain on the same table
     */
    public function testLoadBelongsToDoubleJoin(): void
    {
        $table = $this->getCollectionLocator()->get('Comments');
        $table->belongsTo('Articles');

        $document = $table->get('000000000000000000000002');
        $result = $table->loadInto($document, [
            'Articles' => function (SelectQuery $q) {
                return $q->innerJoinWith('Authors', function ($q) {
                    return $q->where(['Authors.name' => 'mariano']);
                });
            },
            'Articles.Authors',
        ]);

        $this->assertSame($document, $result);

        $expected = $table->get('000000000000000000000002', contain: ['Articles.Authors']);
        $this->assertEquals($expected, $document);
        $this->assertEquals($expected->article, $document->article);
        $this->assertEquals($expected->article->author, $document->article->author);
    }

    /**
     * Tests that it is possible to post-load associations for many entities at
     * the same time
     */
    public function testLoadIntoMany(): void
    {
        $table = $this->getCollectionLocator()->get('Authors');
        $table->hasMany('SiteArticles');

        $documents = $table->find()->toArray();
        $contain = ['SiteArticles', 'Articles.Tags'];
        $result = $table->loadInto($documents, $contain);

        foreach ($documents as $k => $v) {
            $this->assertSame($v, $result[$k]);
        }

        $documents = $table->find()->contain($contain)->toArray();
        foreach ($documents as $k => $v) {
            $this->assertEquals($v->site_articles, $result[$k]->site_articles);
            $this->assertEquals($v->articles, $result[$k]->articles);
        }
    }

    /**
     * Tests loadInto() with deeply nested associations
     */
    public function testLoadIntoNestedAssociations(): void
    {
        $table = $this->getCollectionLocator()->get('Authors');

        $document = $table->get('000000000000000000000001');
        // This should work without throwing an error about 'includeFields' not being an association
        $result = $table->loadInto($document, ['Articles.Tags']);
        $this->assertSame($document, $result);
        $this->assertNotEmpty($result->articles);
        $this->assertNotEmpty($result->articles[0]->tags);

        $expected = $table->get('000000000000000000000001', contain: ['Articles.Tags']);
        $this->assertEquals($expected->articles, $result->articles);
        $this->assertEquals($expected->articles[0]->tags, $result->articles[0]->tags);
    }

    /**
     * Tests loadInto() multiple times with nested associations - reproduces GitHub issue #16362
     */
    public function testLoadIntoMultipleTimesWithNestedAssociations(): void
    {
        $table = $this->getCollectionLocator()->get('Authors');

        // First load some associations
        $document = $table->get('000000000000000000000001');
        $document = $table->loadInto($document, ['Articles']);
        $this->assertNotEmpty($document->articles);
        $this->assertEmpty($document->articles[0]->tags);

        // Now load nested associations - this should not throw an error about 'includeFields'
        $result = $table->loadInto($document, ['Articles.Tags']);
        $this->assertSame($document, $result);

        // Verify the nested associations were loaded correctly
        $this->assertNotEmpty($result->articles);
        $firstArticle = $result->articles[0];
        $this->assertNotNull($firstArticle);

        // Tags should be loaded now
        $this->assertIsArray($firstArticle->tags);
    }

    /**
     * Tests that saveOrFail triggers an exception on not successful save
     */
    public function testSaveOrFail(): void
    {
        $this->expectException(PersistenceFailedException::class);
        $this->expectExceptionMessage('Document save failure.');

        $document = new Document([
            'foo' => 'bar',
        ]);
        $table = $this->getCollectionLocator()->get('users');

        $table->saveOrFail($document);
    }

    /**
     * Tests that saveOrFail displays useful messages on output, especially in tests for CLI.
     */
    public function testSaveOrFailErrorDisplay(): void
    {
        $this->expectException(PersistenceFailedException::class);
        $this->expectExceptionMessage('Document save failure. Found the following errors (field.0: "Some message", multiple.one: "One", multiple.two: "Two")');

        $document = new Document([
            'foo' => 'bar',
        ]);
        $document->setError('field', 'Some message');
        $document->setError('multiple', ['one' => 'One', 'two' => 'Two']);
        $table = $this->getCollectionLocator()->get('users');

        $table->saveOrFail($document);
    }

    /**
     * Tests that saveOrFail with nested errors
     */
    public function testSaveOrFailNestedError(): void
    {
        $this->expectException(PersistenceFailedException::class);
        $this->expectExceptionMessage('Document save failure. Found the following errors (articles.0.title.0: "Bad value")');

        $document = new Document([
            'username' => 'bad',
            'articles' => [
                new Document(['title' => 'not an entity']),
            ],
        ]);
        $document->articles[0]->setError('title', 'Bad value');

        $table = $this->getCollectionLocator()->get('Users');
        $table->hasMany('Articles');

        $table->saveOrFail($document);
    }

    /**
     * Tests that saveOrFail returns the right entity
     */
    public function testSaveOrFailGetDocument(): void
    {
        $document = new Document([
            'foo' => 'bar',
        ]);
        $table = $this->getCollectionLocator()->get('users');

        try {
            $table->saveOrFail($document);
        } catch (PersistenceFailedException $e) {
            $this->assertSame($document, $e->getEntity());
        }
    }

    /**
     * Tests that deleteOrFail triggers an exception on not successful delete
     */
    public function testDeleteOrFail(): void
    {
        $this->expectException(PersistenceFailedException::class);
        $this->expectExceptionMessage('Document delete failure.');
        $document = new Document([
            '_id' => '000000000000000000000999',
        ]);
        $table = $this->getCollectionLocator()->get('users');

        $table->deleteOrFail($document);
    }

    /**
     * Tests that the PersistenceFailedException raised by deleteOrFail carries the failing entity.
     */
    public function testDeleteOrFailGetDocument(): void
    {
        $document = new Document([
            '_id' => '000000000000000000000999',
        ]);
        $table = $this->getCollectionLocator()->get('users');

        try {
            $table->deleteOrFail($document);
        } catch (PersistenceFailedException $e) {
            $this->assertSame($document, $e->getEntity());
        }
    }

    /**
     * Tests that passing an entity from a different table to delete()
     * throws when both tables declare a specific entity class.
     */
    public function testDeleteRejectsDocumentFromOtherCollection(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $tag = new Tag(['_id' => '000000000000000000000001']);
        $tag->setNew(false);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Document of class `TestApp\Model\Document\Tag` does not match the entity class '
            . '`TestApp\Model\Document\Article` configured for table `Articles`.',
        );

        $articles->delete($tag);
    }

    /**
     * Tests that passing an entity from a different table to save()
     * throws when both tables declare a specific entity class.
     */
    public function testSaveRejectsDocumentFromOtherCollection(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $tag = new Tag(['name' => 'new']);

        $this->expectException(InvalidArgumentException::class);
        $articles->save($tag);
    }

    /**
     * Tests that the generic Document class is accepted as an escape hatch,
     * allowing ad-hoc operations such as ``$table->delete(new Document(['_id' => '000000000000000000000001']))``.
     */
    public function testDeleteAcceptsGenericDocument(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $document = new Document(['_id' => '000000000000000000000001']);
        $document->setNew(false);

        $this->assertTrue($articles->delete($document));
    }

    /**
     * Tests that a table without a custom entity class accepts any entity
     * subclass, as there is nothing specific to validate against.
     */
    public function testDeleteOnGenericTableAcceptsAnyDocument(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $articles->setDocumentClass(Document::class);

        $tag = new Tag(['_id' => '000000000000000000000001']);
        $tag->setNew(false);

        $this->assertTrue($articles->delete($tag));
    }

    /**
     * Tests that the cross-table check also fires for deleteMany().
     */
    public function testDeleteManyRejectsDocumentFromOtherCollection(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $article = $articles->get('000000000000000000000001');
        $tag = new Tag(['_id' => '000000000000000000000001']);
        $tag->setNew(false);

        $this->expectException(InvalidArgumentException::class);
        $articles->deleteMany([$article, $tag]);
    }

    /**
     * Tests that the cross-table check also fires for saveMany().
     */
    public function testSaveManyRejectsDocumentFromOtherCollection(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $article = new Article(['title' => 'a', 'body' => 'b']);
        $tag = new Tag(['name' => 'new']);

        $this->expectException(InvalidArgumentException::class);
        $articles->saveMany([$article, $tag]);
    }

    /**
     * Tests that patchDocument() rejects a document from another collection.
     */
    public function testPatchDocumentRejectsEntityFromOtherCollection(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $tag = new Tag(['_id' => '000000000000000000000001', 'name' => 'foo']);

        $this->expectException(InvalidArgumentException::class);
        $articles->patchDocument($tag, ['title' => 'updated']);
    }

    /**
     * Tests that loadInto() rejects an entity from another table.
     */
    public function testLoadIntoRejectsDocumentFromOtherCollection(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $tag = new Tag(['_id' => '000000000000000000000001']);
        $tag->setNew(false);

        $this->expectException(InvalidArgumentException::class);
        $articles->loadInto($tag, ['Tags']);
    }

    /**
     * Tests that an entity whose source matches the table's registry alias
     * short-circuits the assertion: it is treated as unambiguously belonging
     * to this table even if its concrete class would not pass the class check.
     */
    public function testAssertDocumentClassAcceptsMatchingSource(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');

        $tag = new Tag(['_id' => '000000000000000000000001']);
        $tag->setNew(false);
        $tag->setSource('Articles');

        $this->assertTrue($articles->delete($tag));
    }

    /**
     * Tests that disableDocumentClassAssertion() skips the class check, restoring
     * pre-19428 behavior for tables that intentionally accept foreign entities.
     */
    public function testDisableDocumentClassAssertionSkipsClassCheck(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');
        $articles->disableDocumentClassAssertion();

        $tag = new Tag(['_id' => '000000000000000000000001']);
        $tag->setNew(false);

        $this->assertTrue($articles->delete($tag));
    }

    /**
     * Tests the enable/disable/isEnabled accessor trio for the entity-class
     * assertion. Setters are chainable and reflect in isDocumentClassAssertionEnabled().
     */
    public function testDocumentClassAssertionAccessors(): void
    {
        $articles = $this->getCollectionLocator()->get('Articles');

        $this->assertTrue($articles->isDocumentClassAssertionEnabled(), 'Defaults to enabled');
        $this->assertSame($articles, $articles->disableDocumentClassAssertion());
        $this->assertFalse($articles->isDocumentClassAssertionEnabled());
        $this->assertSame($articles, $articles->enableDocumentClassAssertion());
        $this->assertTrue($articles->isDocumentClassAssertionEnabled());
        $articles->enableDocumentClassAssertion(false);
        $this->assertFalse($articles->isDocumentClassAssertionEnabled(), 'enableDocumentClassAssertion(false) disables');
    }

    /**
     * Helper method to skip tests when connection is SQLServer.
     */
    public function skipIfSqlServer(): void
    {
        $this->skipIf(
            $this->connection->getDriver() instanceof Sqlserver,
            'SQLServer does not support the requirements of this test.',
        );
    }
}

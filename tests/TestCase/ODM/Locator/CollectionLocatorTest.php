<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM\Locator;

use Cake\Core\Exception\CakeException;
use Cake\Database\Exception\DatabaseException;
use Cake\Datasource\ConnectionManager;
use Cake\Validation\Validator;
use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\ODM\Exception\MissingCollectionException;
use Crustum\Mongo\ODM\Locator\CollectionLocator;
use Crustum\Mongo\ODM\Query\QueryFactory;
use Crustum\Mongo\Test\TestCase\ODM\TestCase;
use Mockery;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionProperty;
use TestApp\Infrastructure\Collection\AddressesCollection;
use TestApp\Model\Collection\ArticlesCollection;
use TestApp\Model\Collection\AuthorsCollection;
use TestApp\Model\Collection\MyUsersCollection;
use TestApp\Model\Document\Article;
use TestPlugin\Infrastructure\Collection\AddressesCollection as PluginAddressesCollection;
use TestPlugin\Model\Collection\CommentsCollection;
use TestPlugin\Model\Collection\TestPluginCommentsCollection;
use TestPluginTwo\Model\Collection\CommentsCollection as PluginTwoCommentsCollection;

/**
 * Test case for CollectionLocator
 *
 * @ported-from \Cake\Test\TestCase\ORM\Locator\TableLocatorTest
 */
#[AllowMockObjectsWithoutExpectations]
#[CoversClass(CollectionLocator::class)]
class CollectionLocatorTest extends TestCase
{
    /**
     * CollectionLocator instance.
     *
     * @var \Crustum\Mongo\ODM\Locator\CollectionLocator
     */
    protected $locator;

    /**
     * setup
     */
    protected function setUp(): void
    {
        parent::setUp();
        static::setAppNamespace();

        $this->locator = new CollectionLocator();
    }

    /**
     * tearDown
     */
    protected function tearDown(): void
    {
        $this->clearPlugins();
        parent::tearDown();
    }

    /**
     * Test getConfig() method.
     */
    public function testGetConfig(): void
    {
        $this->assertEquals([], $this->locator->getConfig('Tests'));

        $data = [
            'connection' => 'test_mongo',
            'documentClass' => Article::class,
        ];
        $result = $this->locator->setConfig('Tests', $data);
        $this->assertSame($this->locator, $result, 'Returns locator');

        $result = $this->locator->getConfig();
        $expected = ['Tests' => $data];
        $this->assertEquals($expected, $result);
    }

    /**
     * Test getConfig() method with plugin syntax aliases
     */
    public function testConfigPlugin(): void
    {
        $this->loadPlugins(['TestPlugin']);

        $data = [
            'connection' => 'test_mongo',
            'documentClass' => Article::class,
        ];

        $result = $this->locator->setConfig('TestPlugin.TestPluginComments', $data);
        $this->assertSame($this->locator, $result, 'Returns locator');
    }

    /**
     * Test calling getConfig() on existing instances throws an error.
     */
    public function testConfigOnDefinedInstance(): void
    {
        $users = $this->locator->get('Users');
        $this->assertNotEmpty($users);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('You cannot configure `Users`, it has already been constructed.');

        $this->locator->setConfig('Users', ['collection' => 'my_users']);
    }

    /**
     * Test the exists() method.
     */
    public function testExists(): void
    {
        $this->assertFalse($this->locator->exists('Articles'));

        $this->locator->setConfig('Articles', ['collection' => 'articles']);
        $this->assertFalse($this->locator->exists('Articles'));

        $this->locator->get('Articles', ['collection' => 'articles']);
        $this->assertTrue($this->locator->exists('Articles'));
    }

    /**
     * Tests the casing and locator. Using collection name directly is not
     * the same as using conventional aliases anymore.
     */
    public function testCasing(): void
    {
        $this->assertFalse($this->locator->exists('Articles'));

        $Article = $this->locator->get('Articles', ['collection' => 'articles']);
        $this->assertTrue($this->locator->exists('Articles'));

        $this->assertFalse($this->locator->exists('articles'));

        $article = $this->locator->get('articles');
        $this->assertTrue($this->locator->exists('articles'));

        $this->assertNotSame($Article, $article);
    }

    /**
     * Test the exists() method with plugin-prefixed models.
     */
    public function testExistsPlugin(): void
    {
        $this->assertFalse($this->locator->exists('Comments'));
        $this->assertFalse($this->locator->exists('TestPlugin.Comments'));

        $this->locator->setConfig('TestPlugin.Comments', ['collection' => 'comments']);
        $this->assertFalse($this->locator->exists('Comments'), 'The Comments key should not be populated');
        $this->assertFalse($this->locator->exists('TestPlugin.Comments'), 'The plugin.alias key should not be populated');

        $this->locator->get('TestPlugin.Comments', ['collection' => 'comments']);
        $this->assertFalse($this->locator->exists('Comments'), 'The Comments key should not be populated');
        $this->assertTrue($this->locator->exists('TestPlugin.Comments'), 'The plugin.alias key should now be populated');
    }

    /**
     * Test getting instances from the registry.
     */
    public function testGet(): void
    {
        $result = $this->locator->get('Articles', [
            'collection' => 'my_articles',
        ]);
        $this->assertInstanceOf(BaseCollection::class, $result);
        $this->assertSame('my_articles', $result->getCollection());

        $result2 = $this->locator->get('Articles');
        $this->assertSame($result, $result2);
        $this->assertSame('my_articles', $result->getCollection());

        $this->assertSame($this->locator, $result->associations()->getCollectionLocator());

        $result = $this->locator->get(ArticlesCollection::class);
        $this->assertSame('Articles', $result->getAlias());
        $this->assertSame(ArticlesCollection::class, $result->getRegistryAlias());

        $result2 = $this->locator->get($result->getRegistryAlias());
        $this->assertSame($result, $result2);
    }

    /**
     * Are auto-models instantiated correctly? How about when they have an alias?
     */
    public function testGetFallbacks(): void
    {
        $result = $this->locator->get('Droids');
        $this->assertInstanceOf(BaseCollection::class, $result);
        $this->assertSame('droids', $result->getCollection());
        $this->assertSame('Droids', $result->getAlias());

        $result = $this->locator->get('R2D2', ['className' => 'Droids']);
        $this->assertInstanceOf(BaseCollection::class, $result);
        $this->assertSame('droids', $result->getCollection(), 'The collection should be derived from the className');
        $this->assertSame('R2D2', $result->getAlias());

        $result = $this->locator->get('C3P0', ['className' => 'Droids', 'collection' => 'rebels']);
        $this->assertInstanceOf(BaseCollection::class, $result);
        $this->assertSame('rebels', $result->getCollection(), 'The collection should be taken from options');
        $this->assertSame('C3P0', $result->getAlias());

        $result = $this->locator->get('Funky.Chipmunks');
        $this->assertInstanceOf(BaseCollection::class, $result);
        $this->assertSame('chipmunks', $result->getCollection(), 'The collection should be derived from the alias');
        $this->assertSame('Chipmunks', $result->getAlias());

        $result = $this->locator->get('Awesome', ['className' => 'Funky.Monkies']);
        $this->assertInstanceOf(BaseCollection::class, $result);
        $this->assertSame('monkies', $result->getCollection(), 'The collection should be derived from the classname');
        $this->assertSame('Awesome', $result->getAlias());

        $result = $this->locator->get('Stuff', ['className' => BaseCollection::class]);
        $this->assertInstanceOf(BaseCollection::class, $result);
        $this->assertSame('stuff', $result->getCollection(), 'The collection should be derived from the alias');
        $this->assertSame('Stuff', $result->getAlias());
    }

    public function testExceptionForAliasWhenFallbackTurnedOff(): void
    {
        $this->expectException(MissingCollectionException::class);
        $this->expectExceptionMessage('Collection class for alias `Droids` could not be found.');

        $this->locator->get('Droids', ['allowFallbackClass' => false]);
    }

    public function testExceptionForFQCNWhenFallbackTurnedOff(): void
    {
        $this->expectException(MissingCollectionException::class);
        $this->expectExceptionMessage('Collection class `App\Model\DroidsCollection` could not be found.');

        $this->locator->get('App\Model\DroidsCollection', ['allowFallbackClass' => false]);
    }

    /**
     * Test that get() uses config data set with getConfig()
     */
    public function testGetWithGetConfig(): void
    {
        $this->locator->setConfig('Articles', [
            'collection' => 'my_articles',
        ]);
        $result = $this->locator->get('Articles');
        $this->assertSame('my_articles', $result->getCollection(), 'Should use getConfig() data.');
    }

    /**
     * Test that get() uses config data set with getConfig()
     */
    public function testGetWithConnectionName(): void
    {
        $result = $this->locator->get('Articles', [
            'connectionName' => 'test_mongo',
        ]);
        $this->assertSame('articles', $result->getCollection());
        $this->assertSame('test_mongo', $result->getConnection()->configName());
    }

    /**
     * Test that get() uses config data `className` set with getConfig()
     */
    public function testGetWithConfigClassName(): void
    {
        $this->locator->setConfig('MyUsersCollectionAlias', [
            'className' => MyUsersCollection::class,
        ]);
        $result = $this->locator->get('MyUsersCollectionAlias');
        $this->assertInstanceOf(MyUsersCollection::class, $result, 'Should use getConfig() data className option.');
    }

    /**
     * Test get with config throws an exception if the alias exists already.
     */
    public function testGetExistingWithConfigData(): void
    {
        $users = $this->locator->get('Users');
        $this->assertNotEmpty($users);

        $this->expectException(CakeException::class);
        $this->expectExceptionMessage('You cannot configure `Users`, it already exists in the registry.');

        $this->locator->get('Users', ['collection' => 'my_users']);
    }

    /**
     * Test get() can be called several times with the same option without
     * throwing an exception.
     */
    public function testGetWithSameOption(): void
    {
        $result = $this->locator->get('Users', ['className' => MyUsersCollection::class]);
        $result2 = $this->locator->get('Users', ['className' => MyUsersCollection::class]);
        $this->assertEquals($result, $result2);
    }

    /**
     * Tests that collections can be instantiated based on conventions
     * and using plugin notation
     */
    public function testGetWithConventions(): void
    {
        $collection = $this->locator->get('Articles');
        $this->assertInstanceOf(ArticlesCollection::class, $collection);

        $collection = $this->locator->get('Authors');
        $this->assertInstanceOf(AuthorsCollection::class, $collection);
    }

    /**
     * Test get() with plugin syntax aliases
     */
    public function testGetPlugin(): void
    {
        $this->loadPlugins(['TestPlugin']);
        $collection = $this->locator->get('TestPlugin.TestPluginComments');

        $this->assertInstanceOf(TestPluginCommentsCollection::class, $collection);
        $this->assertFalse(
            $this->locator->exists('TestPluginComments'),
            'Short form should NOT exist',
        );
        $this->assertTrue(
            $this->locator->exists('TestPlugin.TestPluginComments'),
            'Long form should exist',
        );

        $second = $this->locator->get('TestPlugin.TestPluginComments');
        $this->assertSame($collection, $second, 'Can fetch long form');
    }

    /**
     * Test get() with same-alias models in different plugins
     *
     * There should be no internal cache-confusion
     */
    public function testGetMultiplePlugins(): void
    {
        $this->loadPlugins(['TestPlugin', 'TestPluginTwo']);

        $app = $this->locator->get('Comments');
        $plugin1 = $this->locator->get('TestPlugin.Comments');
        $plugin2 = $this->locator->get('TestPluginTwo.Comments');

        $this->assertInstanceOf(BaseCollection::class, $app, 'Should be an app collection instance');
        $this->assertInstanceOf(CommentsCollection::class, $plugin1, 'Should be a plugin 1 collection instance');
        $this->assertInstanceOf(PluginTwoCommentsCollection::class, $plugin2, 'Should be a plugin 2 collection instance');

        $plugin2 = $this->locator->get('TestPluginTwo.Comments');
        $plugin1 = $this->locator->get('TestPlugin.Comments');
        $app = $this->locator->get('Comments');

        $this->assertInstanceOf(BaseCollection::class, $app, 'Should still be an app collection instance');
        $this->assertInstanceOf(CommentsCollection::class, $plugin1, 'Should still be a plugin 1 collection instance');
        $this->assertInstanceOf(PluginTwoCommentsCollection::class, $plugin2, 'Should still be a plugin 2 collection instance');
    }

    /**
     * Test get() with plugin aliases + className option.
     */
    public function testGetPluginWithClassNameOption(): void
    {
        $this->loadPlugins(['TestPlugin']);
        $collection = $this->locator->get('Comments', [
            'className' => 'TestPlugin.TestPluginComments',
        ]);
        $class = TestPluginCommentsCollection::class;
        $this->assertInstanceOf($class, $collection);
        $this->assertFalse($this->locator->exists('TestPluginComments'), 'Class name should not exist');
        $this->assertFalse($this->locator->exists('TestPlugin.TestPluginComments'), 'Full class alias should not exist');
        $this->assertTrue($this->locator->exists('Comments'), 'Class name should exist');

        $second = $this->locator->get('Comments');
        $this->assertSame($collection, $second);
    }

    /**
     * Test get() with full namespaced classname
     */
    public function testGetPluginWithFullNamespaceName(): void
    {
        $this->loadPlugins(['TestPlugin']);
        $class = TestPluginCommentsCollection::class;
        $collection = $this->locator->get('Comments', [
            'className' => $class,
        ]);
        $this->assertInstanceOf($class, $collection);
        $this->assertFalse($this->locator->exists('TestPluginComments'), 'Class name should not exist');
        $this->assertFalse($this->locator->exists('TestPlugin.TestPluginComments'), 'Full class alias should not exist');
        $this->assertTrue($this->locator->exists('Comments'), 'Class name should exist');
    }

    /**
     * Tests that collection options can be pre-configured for the factory method
     */
    public function testConfigAndBuild(): void
    {
        $this->locator->clear();
        $map = $this->locator->getConfig();
        $this->assertEquals([], $map);

        $connection = ConnectionManager::get('test_mongo', false);
        $options = ['connection' => $connection];
        $this->locator->setConfig('users', $options);
        $map = $this->locator->getConfig();
        $this->assertEquals(['users' => $options], $map);
        $this->assertEquals($options, $this->locator->getConfig('users'));

        $schema = ['id' => ['type' => 'string']];
        $options += ['schema' => $schema];
        $this->locator->setConfig('users', $options);

        $collection = $this->locator->get('users', ['collection' => 'users']);
        $this->assertInstanceOf(BaseCollection::class, $collection);
        $this->assertSame('users', $collection->getCollection());
        $this->assertSame('users', $collection->getAlias());
        $this->assertSame($connection, $collection->getConnection());
        $this->assertEquals(array_keys($schema), $collection->getSchema()->columns());
        $this->assertSame($schema['id']['type'], $collection->getSchema()->getColumnType('id'));

        $this->locator->clear();
        $this->assertEmpty($this->locator->getConfig());

        $this->locator->setConfig('users', $options);
        $collection = $this->locator->get('users', ['className' => MyUsersCollection::class]);
        $this->assertInstanceOf(MyUsersCollection::class, $collection);
        $this->assertSame('users', $collection->getCollection());
        $this->assertSame('users', $collection->getAlias());
        $this->assertSame($connection, $collection->getConnection());
        $this->assertEquals(array_keys($schema), $collection->getSchema()->columns());
        $this->assertSame($schema['id']['type'], $collection->getSchema()->getColumnType('id'));
    }

    /**
     * Tests that collection options can be pre-configured with a single validator
     */
    public function testConfigWithSingleValidator(): void
    {
        $validator = new Validator();

        $this->locator->setConfig('users', ['validator' => $validator]);
        $collection = $this->locator->get('users');

        $this->assertSame($collection->getValidator('default'), $validator);
    }

    /**
     * Tests that collection options can be pre-configured with multiple validators
     */
    public function testConfigWithMultipleValidators(): void
    {
        $validator1 = new Validator();
        $validator2 = new Validator();
        $validator3 = new Validator();

        $this->locator->setConfig('users', [
            'validator' => [
                'default' => $validator1,
                'secondary' => $validator2,
                'tertiary' => $validator3,
            ],
        ]);
        $collection = $this->locator->get('users');

        $this->assertSame($collection->getValidator('default'), $validator1);
        $this->assertSame($collection->getValidator('secondary'), $validator2);
        $this->assertSame($collection->getValidator('tertiary'), $validator3);
    }

    /**
     * Test setting an instance.
     */
    public function testSet(): void
    {
        $mock = Mockery::mock(BaseCollection::class);
        $this->assertSame($mock, $this->locator->set('Articles', $mock));
        $this->assertSame($mock, $this->locator->get('Articles'));
    }

    /**
     * Test setting an instance with plugin syntax aliases
     */
    public function testSetPlugin(): void
    {
        $this->loadPlugins(['TestPlugin']);

        $mock = Mockery::mock(CommentsCollection::class);

        $this->assertSame($mock, $this->locator->set('TestPlugin.Comments', $mock));
        $this->assertSame($mock, $this->locator->get('TestPlugin.Comments'));
    }

    /**
     * Tests genericInstances
     */
    public function testGenericInstances(): void
    {
        $foos = $this->locator->get('Foos');
        $bars = $this->locator->get('Bars');
        $this->locator->get('Articles');
        $expected = ['Foos' => $foos, 'Bars' => $bars];
        $this->assertEquals($expected, $this->locator->genericInstances());
    }

    /**
     * Tests remove an instance
     */
    public function testRemove(): void
    {
        $first = $this->locator->get('Comments');

        $this->assertTrue($this->locator->exists('Comments'));

        $this->locator->remove('Comments');
        $this->assertFalse($this->locator->exists('Comments'));

        $second = $this->locator->get('Comments');

        $this->assertNotSame($first, $second, 'Should be different objects, as the reference to the first was destroyed');
        $this->assertTrue($this->locator->exists('Comments'));
    }

    /**
     * testRemovePlugin
     *
     * Removing a plugin-prefixed model should not affect any other
     * plugin-prefixed model, or app model.
     * Removing an app model should not affect any other
     * plugin-prefixed model.
     */
    public function testRemovePlugin(): void
    {
        $this->loadPlugins(['TestPlugin', 'TestPluginTwo']);

        $app = $this->locator->get('Comments');
        $this->locator->get('TestPlugin.Comments');
        $plugin = $this->locator->get('TestPluginTwo.Comments');

        $this->assertTrue($this->locator->exists('Comments'));
        $this->assertTrue($this->locator->exists('TestPlugin.Comments'));
        $this->assertTrue($this->locator->exists('TestPluginTwo.Comments'));

        $this->locator->remove('TestPlugin.Comments');

        $this->assertTrue($this->locator->exists('Comments'));
        $this->assertFalse($this->locator->exists('TestPlugin.Comments'));
        $this->assertTrue($this->locator->exists('TestPluginTwo.Comments'));

        $app2 = $this->locator->get('Comments');
        $plugin2 = $this->locator->get('TestPluginTwo.Comments');

        $this->assertSame($app, $app2, 'Should be the same Comments object');
        $this->assertSame($plugin, $plugin2, 'Should be the same TestPluginTwo.Comments object');

        $this->locator->remove('Comments');

        $this->assertFalse($this->locator->exists('Comments'));
        $this->assertFalse($this->locator->exists('TestPlugin.Comments'));
        $this->assertTrue($this->locator->exists('TestPluginTwo.Comments'));

        $plugin3 = $this->locator->get('TestPluginTwo.Comments');

        $this->assertSame($plugin, $plugin3, 'Should be the same TestPluginTwo.Comments object');
    }

    /**
     * testCustomLocation
     *
     * Tests that the correct collection is returned when non-standard namespace is defined.
     */
    public function testCustomLocation(): void
    {
        $locator = new CollectionLocator(['Infrastructure/Collection']);

        $collection = $locator->get('Addresses');
        $this->assertInstanceOf(AddressesCollection::class, $collection);
    }

    /**
     * testCustomLocationPlugin
     *
     * Tests that the correct plugin collection is returned when non-standard namespace is defined.
     */
    public function testCustomLocationPlugin(): void
    {
        $this->loadPlugins(['TestPlugin']);
        $locator = new CollectionLocator(['Infrastructure/Collection']);

        $collection = $locator->get('TestPlugin.Addresses');
        $this->assertInstanceOf(PluginAddressesCollection::class, $collection);
    }

    /**
     * testCustomLocationDefaultWhenNone
     *
     * Tests that the default collection is returned when no namespace is defined.
     */
    public function testCustomLocationDefaultWhenNone(): void
    {
        $locator = new CollectionLocator([]);

        $collection = $locator->get('Addresses');
        $this->assertInstanceOf(BaseCollection::class, $collection);
    }

    /**
     * testCustomLocationDefaultWhenMissing
     *
     * Tests that the default collection is returned when the class cannot be found in a non-standard namespace.
     */
    public function testCustomLocationDefaultWhenMissing(): void
    {
        $locator = new CollectionLocator(['Infrastructure/Collection']);

        $collection = $locator->get('Articles');
        $this->assertInstanceOf(BaseCollection::class, $collection);
    }

    /**
     * testCustomLocationMultiple
     *
     * Tests that the correct collection is returned when multiple namespaces are defined.
     */
    public function testCustomLocationMultiple(): void
    {
        $locator = new CollectionLocator([
            'Infrastructure/Collection',
            'Model/Collection',
        ]);

        $collection = $locator->get('Articles');
        $this->assertInstanceOf(ArticlesCollection::class, $collection);
    }

    /**
     * testAddLocation
     *
     * Tests that adding a namespace takes effect.
     */
    public function testAddLocation(): void
    {
        $locator = new CollectionLocator([]);

        $collection = $locator->get('Addresses');
        $this->assertInstanceOf(BaseCollection::class, $collection);

        $locator->clear();
        $locator->addLocation('Infrastructure/Collection');

        $collection = $locator->get('Addresses');
        $this->assertInstanceOf(AddressesCollection::class, $collection);
    }

    public function testSetFallbackClassName(): void
    {
        $this->locator->setFallbackClassName(ArticlesCollection::class);

        $collection = $this->locator->get('FooBar');
        $this->assertInstanceOf(ArticlesCollection::class, $collection);
    }

    /**
     * testInstanceSetButNotOptions
     *
     * Tests that mock model will not throw an exception if model fetched with options.
     */
    public function testInstanceSetButNotOptions(): void
    {
        $this->setCollectionLocator($this->locator);
        $mock = $this->getMockForCollection('Articles');
        $collection = $this->locator->get('Articles', ['className' => ArticlesCollection::class]);

        $this->assertSame($collection, $mock);
    }

    public function testQueryFactoryInstance(): void
    {
        $articles = $this->locator->get(ArticlesCollection::class);
        $prop1 = new ReflectionProperty($articles, 'queryFactory');

        $users = $this->locator->get(MyUsersCollection::class);
        $prop2 = new ReflectionProperty($users, 'queryFactory');

        $this->assertInstanceOf(QueryFactory::class, $prop1->getValue($articles));
        $this->assertSame($prop1->getValue($articles), $prop2->getValue($users));

        $addresses = $this->locator->get(AddressesCollection::class, ['queryFactory' => new QueryFactory()]);
        $prop3 = new ReflectionProperty($addresses, 'queryFactory');
        $this->assertNotSame($prop1->getValue($articles), $prop3->getValue($addresses));
    }
}

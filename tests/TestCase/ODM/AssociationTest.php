<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM;

use Cake\Core\Configure;
use Cake\Database\Exception\DatabaseException;
use Cake\Datasource\Locator\LocatorInterface;
use Crustum\Mongo\ODM\Association;
use Crustum\Mongo\ODM\BaseCollection;
use InvalidArgumentException;
use Mockery;
use TestApp\Model\Collection\AuthorsCollection;
use TestApp\Model\Collection\TestCollection;
use TestPlugin\Model\Collection\CommentsCollection;

/**
 * Tests Association class
 */
class AssociationTest extends TestCase
{
    /**
     * @var \TestApp\Model\Collection\TestCollection
     */
    protected $source;

    /**
     * @var \Cake\ORM\Association&\Mockery\MockInterface
     */
    protected $association;

    /**
     * Set up
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->source = new TestCollection();
        $config = [
            'className' => TestCollection::class,
            'foreignKey' => 'a_key',
            'conditions' => ['field' => 'value'],
            'dependent' => true,
            'joinType' => 'INNER',
            'propertyName' => 'associated',
        ];
        $this->association = Mockery::mock(
            Association::class . '[options,attachTo,joinCondition,cascadeDelete,isOwningSide,saveAssociated,eagerLoader,type]',
            ['Foo', $this->source, $config],
        )
            ->makePartial()
            ->shouldAllowMockingProtectedMethods()
            ->shouldIgnoreMissing();
    }

    /**
     * Tests that options acts as a callback where subclasses can add their own
     * initialization code based on the passed configuration array
     */
    public function testOptionsIsCalled(): void
    {
        $options = ['foo' => 'bar'];
        $this->association->shouldReceive('options')->once()->with($options);
        $this->association->__construct('Name', $this->source, $options);
    }

    /**
     * Test that _className property is set to alias when "className" config
     * if not explicitly set.
     */
    public function testSetttingClassNameFromAlias(): void
    {
        /** @var \Cake\ORM\Association&\Mockery\MockInterface $association */
        $association = Mockery::mock(
            Association::class . '[type,eagerLoader,cascadeDelete,isOwningSide,saveAssociated]',
            ['Foo', $this->source],
        )
            ->makePartial()
            ->shouldIgnoreMissing();
        $association->shouldReceive('type')
            ->byDefault()
            ->andReturn(Association::MANY_TO_ONE);

        $this->assertSame('Foo', $association->getClassName());
    }

    /**
     * Tests that setClassName() succeeds before the target table is resolved.
     */
    public function testSetClassNameBeforeTarget(): void
    {
        $this->assertSame(TestCollection::class, $this->association->getClassName());
        $this->assertSame($this->association, $this->association->setClassName(AuthorsCollection::class));
        $this->assertSame(AuthorsCollection::class, $this->association->getClassName());
    }

    /**
     * Tests that setClassName() fails after the target table is resolved.
     */
    public function testSetClassNameAfterTarget(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The class name `' . AuthorsCollection::class . "` doesn't match the target table class name of");
        $this->association->getTarget();
        $this->association->setClassName(AuthorsCollection::class);
    }

    /**
     * Tests that setClassName() fails after the target table is resolved.
     */
    public function testSetClassNameWithShortSyntaxAfterTarget(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The class name `Authors` doesn't match the target table class name of");
        $this->association->getTarget();
        $this->association->setClassName('Authors');
    }

    /**
     * Tests that setClassName() succeeds if name equals target table's class name.
     */
    public function testSetClassNameToTargetClassName(): void
    {
        $className = $this->association->getTarget()::class;
        $this->association->setClassName($className);
        $this->assertSame($className, $this->association->getClassName());
    }

    /**
     * Tests that setClassName() succeeds if the short name resolves to the target table's class name.
     */
    public function testSetClassNameWithShortSyntaxToTargetClassName(): void
    {
        Configure::write('App.namespace', 'TestApp');

        $this->association->setClassName(AuthorsCollection::class);
        $className = $this->association->getTarget()::class;
        $this->assertSame(AuthorsCollection::class, $className);
        $this->association->setClassName('Authors');
        $this->assertSame('Authors', $this->association->getClassName());
    }

    /**
     * Tests that className() returns the correct (unnormalized) className
     */
    public function testClassNameUnnormalized(): void
    {
        $config = [
            'className' => 'Test',
        ];
        $this->association = Mockery::mock(
            Association::class . '[options,attachTo,joinCondition,cascadeDelete,isOwningSide,saveAssociated,eagerLoader,type]',
            ['Foo', $this->source, $config],
        )
            ->makePartial()
            ->shouldAllowMockingProtectedMethods()
            ->shouldIgnoreMissing();
        $this->association->shouldReceive('type')
            ->byDefault()
            ->andReturn(Association::MANY_TO_ONE);

        $this->assertSame('Test', $this->association->getClassName());
    }

    /**
     * Tests that an exception is thrown when invalid target table is fetched
     * from a registry.
     */
    public function testInvalidTableFetchedFromRegistry(): void
    {
        $this->expectException(DatabaseException::class);

        $config = [
            'className' => TestCollection::class,
        ];
        $this->association = Mockery::mock(
            Association::class . '[options,attachTo,joinCondition,cascadeDelete,isOwningSide,saveAssociated,eagerLoader,type]',
            ['Test', $this->source, $config],
        )
            ->makePartial()
            ->shouldAllowMockingProtectedMethods()
            ->shouldIgnoreMissing();
        $this->association->shouldReceive('type')
            ->byDefault()
            ->andReturn(Association::MANY_TO_ONE);
        $this->association->setSource($this->getCollectionLocator()->get('Test'));

        $this->association->getTarget();
    }

    /**
     * Tests that a descendant table could be fetched from a registry.
     */
    public function testTargetTableDescendant(): void
    {
        $this->getCollectionLocator()->get('Test', [
            'className' => TestCollection::class,
        ]);
        $className = BaseCollection::class;

        $config = [
            'className' => $className,
        ];
        $this->association = Mockery::mock(
            Association::class . '[options,attachTo,joinCondition,cascadeDelete,isOwningSide,saveAssociated,eagerLoader,type]',
            ['Test', $this->source, $config],
        )
            ->makePartial()
            ->shouldAllowMockingProtectedMethods()
            ->shouldIgnoreMissing();
        $this->association->shouldReceive('type')
            ->byDefault()
            ->andReturn(Association::MANY_TO_ONE);

        $target = $this->association->getTarget();
        $this->assertInstanceOf($className, $target);
    }

    /**
     * Tests that cascadeCallbacks() returns the correct configured value
     */
    public function testSetCascadeCallbacks(): void
    {
        $this->assertFalse($this->association->getCascadeCallbacks());
        $this->assertSame($this->association, $this->association->setCascadeCallbacks(true));
        $this->assertTrue($this->association->getCascadeCallbacks());
    }

    /**
     * Tests the bindingKey method as a setter/getter
     */
    public function testSetBindingKey(): void
    {
        $this->assertSame($this->association, $this->association->setBindingKey('foo_id'));
        $this->assertSame('foo_id', $this->association->getBindingKey());
    }

    /**
     * Tests the bindingKey() method when called with its defaults
     */
    public function testBindingKeyDefault(): void
    {
        $this->source->setPrimaryKey(['_id', 'site_id']);
        $this->association
            ->shouldReceive('isOwningSide')
            ->once()
            ->andReturn(true);
        $result = $this->association->getBindingKey();
        $this->assertEquals(['_id', 'site_id'], $result);
    }

    /**
     * Tests the bindingKey() method when the association source is not the
     * owning side
     */
    public function testBindingDefaultNoOwningSide(): void
    {
        $target = new BaseCollection();
        $target->setPrimaryKey(['foo', 'site_id']);

        $this->association->setTarget($target);

        $this->association
            ->shouldReceive('isOwningSide')
            ->once()
            ->andReturn(false);
        $result = $this->association->getBindingKey();
        $this->assertEquals(['foo', 'site_id'], $result);
    }

    /**
     * Tests setForeignKey()
     */
    public function testSetForeignKey(): void
    {
        $this->assertSame('a_key', $this->association->getForeignKey());
        $this->assertSame($this->association, $this->association->setForeignKey('another_key'));
        $this->assertSame('another_key', $this->association->getForeignKey());
    }

    /**
     * Tests setConditions()
     */
    public function testSetConditions(): void
    {
        $this->assertEquals(['field' => 'value'], $this->association->getConditions());
        $conds = ['another_key' => 'another value'];
        $this->assertSame($this->association, $this->association->setConditions($conds));
        $this->assertEquals($conds, $this->association->getConditions());
    }

    /**
     * Tests that canBeJoined() returns the correct configured value
     *
     * ODM has no SQL join strategy; Mongo documents are linked via
     * lookup/select/embed pipelines, so associations can never be "joined".
     */
    public function testCanBeJoined(): void
    {
        $this->assertFalse($this->association->canBeJoined());
    }

    /**
     * Tests that setTarget()
     */
    public function testSetTarget(): void
    {
        $collection = $this->association->getTarget();
        $this->assertInstanceOf(TestCollection::class, $collection);

        $other = new BaseCollection();
        $this->assertSame($this->association, $this->association->setTarget($other));
        $this->assertSame($other, $this->association->getTarget());
    }

    /**
     * Tests that target() returns the correct BaseCollection object for plugins
     */
    public function testTargetPlugin(): void
    {
        $this->loadPlugins(['TestPlugin']);
        $config = [
            'className' => 'TestPlugin.Comments',
            'foreignKey' => 'a_key',
            'conditions' => ['field' => 'value'],
            'dependent' => true,
            'joinType' => 'INNER',
        ];

        $this->association = Mockery::mock(
            Association::class . '[type,eagerLoader,cascadeDelete,isOwningSide,saveAssociated]',
            ['ThisAssociationName', $this->source, $config],
        )
            ->makePartial()
            ->shouldIgnoreMissing();
        $this->association->shouldReceive('type')
            ->byDefault()
            ->andReturn(Association::MANY_TO_ONE);

        $collection = $this->association->getTarget();
        $this->assertInstanceOf(CommentsCollection::class, $collection);

        $this->assertTrue(
            $this->getCollectionLocator()->exists('TestPlugin.ThisAssociationName'),
            'The association class will use this registry key',
        );
        $this->assertFalse($this->getCollectionLocator()->exists('TestPlugin.Comments'), 'The association class will NOT use this key');
        $this->assertFalse($this->getCollectionLocator()->exists('Comments'), 'Should also not be set');
        $this->assertFalse($this->getCollectionLocator()->exists('ThisAssociationName'), 'Should also not be set');

        $plugin = $this->getCollectionLocator()->get('TestPlugin.ThisAssociationName');
        $this->assertSame($collection, $plugin, 'Should be an instance of TestPlugin.Comments');
        $this->assertSame('TestPlugin.ThisAssociationName', $collection->getRegistryAlias());
        $this->assertSame('comments', $collection->getCollection());
        $this->assertSame('ThisAssociationName', $collection->getAlias());
        $this->clearPlugins();
    }

    /**
     * Tests that source() returns the correct BaseCollection object
     */
    public function testSetSource(): void
    {
        $collection = $this->association->getSource();
        $this->assertSame($this->source, $collection);

        $other = new BaseCollection();
        $this->assertSame($this->association, $this->association->setSource($other));
        $this->assertSame($other, $this->association->getSource());
    }

    /**
     * Tests property method
     */
    public function testSetProperty(): void
    {
        $this->assertSame('associated', $this->association->getProperty());
        $this->assertSame($this->association, $this->association->setProperty('thing'));
        $this->assertSame('thing', $this->association->getProperty());
    }

    /**
     * Test that warning is shown if property name clashes with table field.
     */
    public function testPropertyNameClash(): void
    {
        $this->expectWarningMessageMatches('/^Association property name `foo` clashes with field of same name of table `test`/', function (): void {
            $this->source->setSchemaFromArray(['foo' => ['type' => 'string']]);
            $this->association->setProperty('foo');
            $this->association->getProperty('foo');
        });
    }

    /**
     * Tests strategy method
     *
     * ODM strategies are select/lookup/embed; the SQL join/subquery strategies
     * have no Mongo equivalent.
     */
    public function testSetStrategy(): void
    {
        $this->assertSame('select', $this->association->getStrategy());

        $this->association->setStrategy('lookup');
        $this->assertSame('lookup', $this->association->getStrategy());

        $this->association->setStrategy('embed');
        $this->assertSame('embed', $this->association->getStrategy());
    }

    /**
     * Tests that providing an invalid strategy throws an exception
     */
    public function testInvalidStrategy(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->association->setStrategy('anotherThing');
    }

    /**
     * Tests test setFinder() method
     */
    public function testSetFinderMethod(): void
    {
        $this->assertSame('all', $this->association->getFinder());
        $this->assertSame($this->association, $this->association->setFinder('published'));
        $this->assertSame('published', $this->association->getFinder());
    }

    /**
     * Tests that `finder` is a valid option for the association constructor
     */
    public function testFinderInConstructor(): void
    {
        $config = [
            'className' => TestCollection::class,
            'foreignKey' => 'a_key',
            'conditions' => ['field' => 'value'],
            'dependent' => true,
            'joinType' => 'INNER',
            'finder' => 'published',
        ];
        $assoc = Mockery::mock(
            Association::class . '[type,eagerLoader,cascadeDelete,isOwningSide,saveAssociated]',
            ['Foo', $this->source, $config],
        )
            ->makePartial()
            ->shouldIgnoreMissing();
        $assoc->shouldReceive('type')
            ->byDefault()
            ->andReturn(Association::MANY_TO_ONE);
        $this->assertSame('published', $assoc->getFinder());
    }

    public function testCustomFinderWithTypedArgs(): void
    {
        $this->association->setFinder('publishedWithArgOnly');
        $this->assertEquals(
            ['this' => 'custom'],
            $this->association->find(null, 'custom')->getOptions(),
        );
        $this->assertEquals(
            ['what' => 'custom', 'this' => 'custom'],
            $this->association->find(null, what: 'custom')->getOptions(),
        );
        $this->assertEquals(
            ['what' => 'custom', 'this' => 'custom'],
            $this->association->find(what: 'custom')->getOptions(),
        );
    }

    /**
     * Tests that `locator` is a valid option for the association constructor
     */
    public function testLocatorInConstructor(): void
    {
        $locator = Mockery::mock(LocatorInterface::class);
        $config = [
            'className' => TestCollection::class,
            'collectionLocator' => $locator,
        ];
        $assoc = Mockery::mock(
            Association::class . '[type,eagerLoader,cascadeDelete,isOwningSide,saveAssociated]',
            ['Foo', $this->source, $config],
        )
            ->makePartial()
            ->shouldIgnoreMissing();
        $assoc->shouldReceive('type')
            ->byDefault()
            ->andReturn(Association::MANY_TO_ONE);
        $this->assertEquals($locator, $assoc->getCollectionLocator());
    }
}

<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM;

use Cake\Core\Exception\CakeException;
use Cake\Datasource\Locator\LocatorInterface;
use Crustum\Mongo\ODM\Association\BelongsTo;
use Crustum\Mongo\ODM\Association\BelongsToMany;
use Crustum\Mongo\ODM\Association\HasMany;
use Crustum\Mongo\ODM\Association\HasOne;
use Crustum\Mongo\ODM\AssociationCollection;
use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\ODM\Document;
use InvalidArgumentException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * AssociationCollection test case.
 */
class AssociationCollectionTest extends TestCase
{
    /**
     * @var AssociationCollection
     */
    protected $associations;

    /**
     * setup
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->associations = new AssociationCollection();
    }

    /**
     * Test the constructor.
     */
    public function testConstructor(): void
    {
        $this->assertSame($this->getCollectionLocator(), $this->associations->getCollectionLocator());

        $collectionLocator = Mockery::mock(LocatorInterface::class);
        $associations = new AssociationCollection($collectionLocator);
        $this->assertSame($collectionLocator, $associations->getCollectionLocator());
    }

    /**
     * Test the simple add/has and get methods.
     */
    public function testAddHasRemoveAndGet(): void
    {
        $this->assertFalse($this->associations->has('users'));
        $this->assertFalse($this->associations->has('Users'));

        $this->assertNull($this->associations->get('users'));
        $this->assertNull($this->associations->get('Users'));

        $belongsTo = new BelongsTo('', new BaseCollection());
        $this->assertSame($belongsTo, $this->associations->add('Users', $belongsTo));
        $this->assertFalse($this->associations->has('users'));
        $this->assertTrue($this->associations->has('Users'));

        $this->assertSame($belongsTo, $this->associations->get('Users'));

        $this->associations->remove('Users');

        $this->assertFalse($this->associations->has('Users'));
        $this->assertNull($this->associations->get('Users'));
    }

    /**
     * Test the load method.
     */
    public function testLoad(): void
    {
        $this->associations->load(BelongsTo::class, 'Users', new BaseCollection());
        $this->assertTrue($this->associations->has('Users'));
        $this->assertInstanceOf(BelongsTo::class, $this->associations->get('Users'));
        $this->assertSame($this->associations->getCollectionLocator(), $this->associations->get('Users')->getCollectionLocator());
    }

    /**
     * Test the load method with custom locator.
     */
    public function testLoadCustomLocator(): void
    {
        $locator = Mockery::mock(LocatorInterface::class);
        $collection = new BaseCollection(['alias' => 'Users', 'collection' => 'users']);
        $this->associations->load(BelongsTo::class, 'Users', $collection, [
            'collectionLocator' => $locator,
        ]);
        $this->assertTrue($this->associations->has('Users'));
        $this->assertInstanceOf(BelongsTo::class, $this->associations->get('Users'));
        $this->assertSame($locator, $this->associations->get('Users')->getCollectionLocator());
    }

    /**
     * Test removeAll method
     */
    public function testRemoveAll(): void
    {
        $this->assertEmpty($this->associations->keys());

        $belongsTo = new BelongsTo('', new BaseCollection());
        $this->assertSame($belongsTo, $this->associations->add('Users', $belongsTo));
        $belongsToMany = new BelongsToMany('', new BaseCollection());
        $this->assertSame($belongsToMany, $this->associations->add('Cart', $belongsToMany));

        $this->associations->removeAll();
        $this->assertEmpty($this->associations->keys());
    }

    /**
     * Test getting associations by property.
     */
    public function testGetByProperty(): void
    {
        $collection = new BaseCollection(['alias' => 'Clients', 'collection' => 'clients']);
        $collection->setSchemaFromArray([]);
        $belongsTo = new BelongsTo('Users', $collection);
        $this->assertSame('user', $belongsTo->getProperty());
        $this->associations->add('Users', $belongsTo);
        $this->assertNull($this->associations->get('user'));

        $this->assertSame($belongsTo, $this->associations->getByProperty('user'));
    }

    /**
     * Test associations with plugin names.
     */
    public function testAddHasRemoveGetWithPlugin(): void
    {
        $this->assertFalse($this->associations->has('Photos.Photos'));
        $this->assertFalse($this->associations->has('Photos'));

        $belongsTo = new BelongsTo('', new BaseCollection());
        $this->assertSame($belongsTo, $this->associations->add('Photos.Photos', $belongsTo));
        $this->assertTrue($this->associations->has('Photos'));
        $this->assertFalse($this->associations->has('Photos.Photos'));
    }

    /**
     * Test keys()
     */
    public function testKeys(): void
    {
        $belongsTo = new BelongsTo('', new BaseCollection());
        $this->associations->add('Users', $belongsTo);
        $this->associations->add('Categories', $belongsTo);
        $this->assertEquals(['Users', 'Categories'], $this->associations->keys());

        $this->associations->remove('Categories');
        $this->assertEquals(['Users'], $this->associations->keys());
    }

    /**
     *  Data provider for AssociationCollection::getByType
     */
    public static function associationCollectionType(): array
    {
        return [
            ['BelongsTo', 'BelongsToMany'],
            ['belongsTo', 'belongsToMany'],
            ['belongsto', 'belongstomany'],
        ];
    }

    /**
     * Test getting association names by getByType.
     *
     * @param string $belongsToStr
     * @param string $belongsToManyStr
     */
    #[DataProvider('associationCollectionType')]
    public function testGetByType(string $belongsToStr, string $belongsToManyStr): void
    {
        $belongsTo = new BelongsTo('', new BaseCollection());
        $this->associations->add('Users', $belongsTo);

        $belongsToMany = new BelongsToMany('', new BaseCollection());
        $this->associations->add('Tags', $belongsToMany);

        $this->assertSame([$belongsTo], $this->associations->getByType($belongsToStr));
        $this->assertSame([$belongsToMany], $this->associations->getByType($belongsToManyStr));
        $this->assertSame([$belongsTo, $belongsToMany], $this->associations->getByType([$belongsToStr, $belongsToManyStr]));
    }

    /**
     * Type should return empty array.
     */
    public function hasTypeReturnsEmptyArray(): void
    {
        foreach (['HasMany', 'hasMany', 'FooBar', 'DoesNotExist'] as $value) {
            $this->assertSame([], $this->associations->getByType($value));
        }
    }

    /**
     * test cascading deletes.
     */
    public function testCascadeDelete(): void
    {
        $collection = new BaseCollection(['alias' => 'Users', 'collection' => 'users']);
        $mockOne = Mockery::mock(new BelongsTo('', $collection))->makePartial();
        $mockTwo = Mockery::mock(new HasMany('', $collection))->makePartial();

        $entity = new Document();
        $options = ['option' => 'value'];
        $this->associations->add('One', $mockOne);
        $this->associations->add('Two', $mockTwo);

        $mockOne->shouldReceive('cascadeDelete')
            ->once()
            ->with($entity, $options)
            ->andReturn(true);

        $mockTwo->shouldReceive('cascadeDelete')
            ->once()
            ->with($entity, $options)
            ->andReturn(true);

        $result = $this->associations->cascadeDelete($entity, $options);
        $this->assertTrue($result);
    }

    /**
     * Test saving parent associations
     */
    public function testSaveParents(): void
    {
        $collection = new BaseCollection(['alias' => 'Users', 'collection' => 'users']);
        $collection->setSchemaFromArray([]);
        $mockOne = Mockery::mock(new BelongsTo('Parent', $collection))->makePartial();
        $mockTwo = Mockery::mock(new HasMany('Child', $collection))->makePartial();

        $this->associations->add('Parent', $mockOne);
        $this->associations->add('Child', $mockTwo);

        $entity = new Document();
        $entity->set('parent', ['key' => 'value']);
        $entity->set('child', ['key' => 'value']);

        $options = ['option' => 'value'];

        $mockOne->shouldReceive('saveAssociated')
            ->once()
            ->with($entity, $options)
            ->andReturn($entity);

        $mockTwo->shouldReceive('saveAssociated')->never();

        $result = $this->associations->saveParents(
            $collection,
            $entity,
            ['Parent', 'Child'],
            $options,
        );
        $this->assertTrue($result, 'Save should work.');
    }

    /**
     * Test saving filtered parent associations.
     */
    public function testSaveParentsFiltered(): void
    {
        $collection = new BaseCollection(['alias' => 'Users', 'collection' => 'users']);
        $collection->setSchemaFromArray([]);
        $mockOne = Mockery::mock(new BelongsTo('Parents', $collection))->makePartial();
        $mockTwo = Mockery::mock(new BelongsTo('Categories', $collection))->makePartial();

        $this->associations->add('Parents', $mockOne);
        $this->associations->add('Categories', $mockTwo);

        $entity = new Document();
        $entity->set('parent', ['key' => 'value']);
        $entity->set('category', ['key' => 'value']);

        $options = ['atomic' => true];

        $mockOne->shouldReceive('saveAssociated')
            ->once()
            ->with($entity, ['atomic' => true, 'associated' => ['Others']])
            ->andReturn($entity);

        $mockTwo->shouldReceive('saveAssociated')->never();

        $result = $this->associations->saveParents(
            $collection,
            $entity,
            ['Parents' => ['associated' => ['Others']]],
            $options,
        );
        $this->assertTrue($result, 'Save should work.');
    }

    /**
     * Test saving filtered child associations.
     */
    public function testSaveChildrenFiltered(): void
    {
        $collection = new BaseCollection(['alias' => 'Users', 'collection' => 'users']);
        $collection->setSchemaFromArray([]);
        $mockOne = Mockery::mock(new HasMany('Comments', $collection))->makePartial();
        $mockTwo = Mockery::mock(new HasOne('Profiles', $collection))->makePartial();

        $this->associations->add('Comments', $mockOne);
        $this->associations->add('Profiles', $mockTwo);

        $entity = new Document();
        $entity->set('comments', ['key' => 'value']);
        $entity->set('profile', ['key' => 'value']);

        $options = ['atomic' => true];

        $mockOne->shouldReceive('saveAssociated')
            ->once()
            ->with($entity, $options + ['associated' => ['Other']])
            ->andReturn($entity);

        $mockTwo->shouldReceive('saveAssociated')->never();

        $result = $this->associations->saveChildren(
            $collection,
            $entity,
            ['Comments' => ['associated' => ['Other']]],
            $options,
        );
        $this->assertTrue($result, 'Should succeed.');
    }

    /**
     * Test exceptional case.
     */
    public function testErrorOnUnknownAlias(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot save `Profiles`, it is not associated to `Users`');
        $collection = new BaseCollection(['alias' => 'Users', 'collection' => 'users']);

        $entity = new Document();
        $entity->set('profile', ['key' => 'value']);

        $this->associations->saveChildren(
            $collection,
            $entity,
            ['Profiles'],
            ['atomic' => true],
        );
    }

    /**
     * Tests the normalizeKeys method
     */
    public function testNormalizeKeys(): void
    {
        $this->assertSame([], $this->associations->normalizeKeys([]));
        $this->assertSame([], $this->associations->normalizeKeys(false));

        $assocs = ['a', 'b', 'd' => ['associated' => ['something']]];
        $expected = ['a' => [], 'b' => [], 'd' => ['associated' => ['something']]];
        $this->assertSame($expected, $this->associations->normalizeKeys($assocs));

        $assocs = ['a', 'b', 'd' => ['something']];
        $expected = ['a' => [], 'b' => [], 'd' => ['associated' => ['something' => []]]];
        $this->assertSame($expected, $this->associations->normalizeKeys($assocs));

        $belongsTo = new BelongsTo('', new BaseCollection());
        $this->associations->add('users', $belongsTo);
        $this->associations->add('categories', $belongsTo);
        $expected = ['users' => [], 'categories' => []];
        $this->assertSame($expected, $this->associations->normalizeKeys(true));
    }

    /**
     * Ensure that the association collection can be iterated.
     */
    public function testAssociationsCanBeIterated(): void
    {
        $belongsTo = new BelongsTo('', new BaseCollection());
        $this->associations->add('Users', $belongsTo);
        $belongsToMany = new BelongsToMany('', new BaseCollection());
        $this->associations->add('Cart', $belongsToMany);

        $expected = ['Users' => $belongsTo, 'Cart' => $belongsToMany];
        $result = iterator_to_array($this->associations, true);
        $this->assertSame($expected, $result);
    }

    public function testExceptionOnDuplicateAlias(): void
    {
        $this->expectException(CakeException::class);
        $this->expectExceptionMessage('Association alias `Users` is already set.');

        $belongsTo = new BelongsTo('Test', new BaseCollection());
        $this->associations->add('Users', $belongsTo);
        $this->associations->add('Users', $belongsTo);
    }
}

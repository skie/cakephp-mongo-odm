<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM\Locator;

use Cake\Core\PluginApplicationInterface;
use Cake\Datasource\EntityInterface;
use Cake\Datasource\FactoryLocator;
use Crustum\Mongo\Exception\MissingCollectionException;
use Crustum\Mongo\MongoPlugin;
use Crustum\Mongo\ODM\Locator\CollectionContainer;
use Crustum\Mongo\ODM\Locator\CollectionLocator;
use Crustum\Mongo\Test\TestCase\ODM\TestCase;
use TestApp\Model\Collection\UsersCollection;

class CollectionLocatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (new MongoPlugin())->bootstrap($this->createStub(PluginApplicationInterface::class));
    }

    public function testGetExistsAndClear(): void
    {
        $locator = new CollectionLocator();
        $collection = $locator->get('Users');

        $this->assertInstanceOf(UsersCollection::class, $collection);
        $this->assertSame($collection, $locator->get('Users'));
        $this->assertTrue($locator->exists('Users'));

        $locator->clear();

        $this->assertFalse($locator->exists('Users'));
    }

    public function testResolvesApplicationAndPluginClasses(): void
    {
        $locator = new CollectionLocator();

        $this->assertInstanceOf(UsersCollection::class, $locator->get('Users'));
    }

    public function testMissingClassRaisesException(): void
    {
        $this->expectException(MissingCollectionException::class);

        (new CollectionLocator())->get('Missing', ['allowFallbackClass' => false]);
    }

    public function testFactoryLocatorWiring(): void
    {
        $this->assertInstanceOf(CollectionLocator::class, FactoryLocator::get('Collection'));
        $this->assertSame(FactoryLocator::get('Collection'), FactoryLocator::get('Mongo'));
    }

    public function testContainerAccess(): void
    {
        $container = new CollectionContainer();

        $this->assertTrue($container->has(UsersCollection::class));
        $this->assertFalse($container->has(EntityInterface::class));
        $this->assertInstanceOf(UsersCollection::class, $container->get('Users'));
    }
}

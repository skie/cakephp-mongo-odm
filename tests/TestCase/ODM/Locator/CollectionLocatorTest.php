<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM\Locator;

use Cake\Core\PluginApplicationInterface;
use Cake\Datasource\FactoryLocator;
use Cake\ORM\Table;
use Crustum\Mongo\Exception\MissingCollectionException;
use Crustum\Mongo\MongoPlugin;
use Crustum\Mongo\ODM\Locator\CollectionContainer;
use Crustum\Mongo\ODM\Locator\CollectionLocator;
use Crustum\Mongo\Test\TestCase\ODM\TestCase;
use TestApp\Model\Collection\UsersCollection;

require_once TESTS . 'TestApp/Model/Collection/PluginCollection.php';

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

        $this->assertInstanceOf(Table::class, $collection);
        $this->assertSame($collection, $locator->get('Users'));
        $this->assertTrue($locator->exists('Users'));

        $locator->clear();

        $this->assertFalse($locator->exists('Users'));
    }

    public function testResolvesApplicationAndPluginClasses(): void
    {
        $locator = new CollectionLocator();

        $this->assertInstanceOf(Table::class, $locator->get('Users'));
        $this->assertInstanceOf(Table::class, $locator->get('Crustum/Mongo.Plugin'));
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
        $this->assertFalse($container->has(Table::class));
        $this->assertInstanceOf(Table::class, $container->get('Users'));
    }
}

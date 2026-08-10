<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM;

use BadMethodCallException;
use Cake\Core\Exception\CakeException;
use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\ODM\BehaviorRegistry;
use Crustum\Mongo\ODM\Exception\MissingBehaviorException;
use Crustum\Mongo\Test\TestCase\ODM\TestCase;
use LogicException;
use TestApp\Model\Behavior\DuplicateBehavior;
use TestApp\Model\Behavior\SluggableBehavior;
use TestApp\Model\Behavior\TreeBehavior;
use TestPlugin\Model\Behavior\PersisterOneBehavior;

/**
 * Port of `Cake\Test\TestCase\ORM\BehaviorRegistryTest` for the ODM layer.
 */
class BehaviorRegistryTest extends TestCase
{
    /**
     * @var \Crustum\Mongo\ODM\BehaviorRegistry
     */
    protected $Behaviors;

    /**
     * @var \Crustum\Mongo\ODM\BaseCollection
     */
    protected $Collection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->Collection = new BaseCollection(['alias' => 'Articles', 'collection' => 'articles']);
        $this->Behaviors = new BehaviorRegistry($this->Collection);
        $this->setAppNamespace('TestApp');
    }

    protected function tearDown(): void
    {
        unset($this->Collection, $this->Behaviors);
        parent::tearDown();
    }

    public function testClassName(): void
    {
        $expected = \Crustum\Mongo\ODM\Behavior\SluggableBehavior::class ?? SluggableBehavior::class;
        $result = BehaviorRegistry::className('Sluggable');
        $this->assertSame(SluggableBehavior::class, $result);

        $result = BehaviorRegistry::className('TestPlugin.PersisterOne');
        $this->assertSame(PersisterOneBehavior::class, $result);

        $this->assertNull(BehaviorRegistry::className('NonExistent'));
    }

    public function testLoad(): void
    {
        $config = ['alias' => 'Sluggable', 'replacement' => '-'];
        $result = $this->Behaviors->load('Sluggable', $config);
        $this->assertInstanceOf(SluggableBehavior::class, $result);
        $this->assertEquals($config, $result->getConfig());

        $result = $this->Behaviors->load('TestPlugin.PersisterOne');
        $this->assertInstanceOf(PersisterOneBehavior::class, $result);

        $this->Behaviors->unload('PersisterOne');
        $config = ['className' => 'TestPlugin.PersisterOne'];
        $this->Behaviors->load('TestPlugin.PersisterOne', $config);
        $this->assertInstanceOf(PersisterOneBehavior::class, $this->Behaviors->get('PersisterOne'));
    }

    public function testLoadBindEvents(): void
    {
        $eventManager = $this->Collection->getEventManager();
        // The collection subscribes itself to its own conventional hooks.
        $result = $eventManager->listeners('Collection.beforeFind');
        $this->assertCount(1, $result);

        $sluggable = $this->Behaviors->load('Sluggable');
        $result = $eventManager->listeners('Collection.beforeFind');
        $this->assertCount(2, $result);
        $this->assertEquals($sluggable->beforeFind(...), $result[1]['callable']);
    }

    public function testLoadEnabledFalse(): void
    {
        $eventManager = $this->Collection->getEventManager();
        $result = $eventManager->listeners('Collection.beforeFind');
        $this->assertCount(1, $result);

        $this->Behaviors->load('Sluggable', ['enabled' => false]);
        $result = $eventManager->listeners('Collection.beforeFind');
        $this->assertCount(1, $result);
    }

    public function testLoadPlugin(): void
    {
        $result = $this->Behaviors->load('TestPlugin.PersisterOne');

        $expected = PersisterOneBehavior::class;
        $this->assertInstanceOf($expected, $result);
        $this->assertInstanceOf($expected, $this->Behaviors->get('PersisterOne'));

        $this->Behaviors->unload('PersisterOne');

        $result = $this->Behaviors->load('TestPlugin.PersisterOne', ['foo' => 'bar']);
        $this->assertInstanceOf($expected, $result);
        $this->assertInstanceOf($expected, $this->Behaviors->get('PersisterOne'));
    }

    public function testLoadMissingClass(): void
    {
        $this->expectException(MissingBehaviorException::class);
        $this->Behaviors->load('DoesNotExist');
    }

    public function testLoadDuplicateFinderError(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Duplicate finder `children`.');
        $this->Behaviors->load('Tree');
        $this->Behaviors->load('Duplicate');
    }

    public function testLoadDuplicateFinderAliasing(): void
    {
        $this->Behaviors->load('Tree');
        $this->Behaviors->load('Duplicate', [
            'implementedFinders' => [
                'renamed' => 'findChildren',
            ],
        ]);
        $this->assertTrue($this->Behaviors->hasFinder('renamed'));
    }

    public function testHasFinder(): void
    {
        $this->Behaviors->load('Sluggable');

        $this->assertTrue($this->Behaviors->hasFinder('noSlug'));
        $this->assertTrue($this->Behaviors->hasFinder('noslug'));
        $this->assertTrue($this->Behaviors->hasFinder('NOSLUG'));

        $this->assertFalse($this->Behaviors->hasFinder('slugify'));
        $this->assertFalse($this->Behaviors->hasFinder('beforeFind'));
        $this->assertFalse($this->Behaviors->hasFinder('nope'));
    }

    public function testGetFinder(): void
    {
        $this->Behaviors->load('Sluggable');

        $return = $this->Behaviors->getFinder('noSlug');
        $this->assertEquals($this->Behaviors->get('Sluggable')->findNoSlug(...), $return);
    }

    public function testGetFinderError(): void
    {
        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Finder `nope` is not implemented by an attached behavior.');
        $this->Behaviors->load('Sluggable');
        $this->Behaviors->getFinder('nope');
    }

    public function testUnloadBehaviorThenGetFinder(): void
    {
        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Finder `noslug` is not implemented by an attached behavior.');
        $this->Behaviors->load('Sluggable');
        $this->assertTrue($this->Behaviors->hasFinder('noSlug'));
        $this->Behaviors->unload('Sluggable');

        $this->assertFalse($this->Behaviors->hasFinder('noSlug'));
        $this->Behaviors->getFinder('noSlug');
    }

    public function testUnloadBehaviorThenReload(): void
    {
        $this->Behaviors->load('Sluggable');
        $this->Behaviors->unload('Sluggable');

        $this->assertEmpty($this->Behaviors->loaded());

        $this->Behaviors->load('Sluggable');

        $this->assertEquals(['Sluggable'], $this->Behaviors->loaded());
    }

    public function testUnload(): void
    {
        $this->Behaviors->load('Sluggable');
        $this->assertTrue($this->Behaviors->hasFinder('noSlug'));

        $this->Behaviors->unload('Sluggable');

        $this->assertEmpty($this->Behaviors->loaded());
        $this->assertCount(1, $this->Collection->getEventManager()->listeners('Collection.beforeFind'));
        $this->assertFalse($this->Behaviors->hasFinder('noSlug'));
        $this->assertFalse($this->Behaviors->hasFinder('noslug'));
    }

    public function testUnloadUnknown(): void
    {
        $this->expectException(CakeException::class);
        $this->expectExceptionMessage('Unknown object `Foo`');
        $this->Behaviors->unload('Foo');
    }

    public function testSetCollection(): void
    {
        $collection = new BaseCollection(['alias' => 'Users', 'collection' => 'users']);

        $this->Behaviors->setCollection($collection);
        $this->assertSame($collection->getEventManager(), $this->Behaviors->getEventManager());
    }
}

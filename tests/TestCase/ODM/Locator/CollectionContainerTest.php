<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM\Locator;

use Cake\Core\Container;
use Crustum\Mongo\ODM\Locator\CollectionContainer;
use Crustum\Mongo\Test\TestCase\ODM\TestCase;
use League\Container\Exception\NotFoundException;
use PHPUnit\Framework\Attributes\CoversClass;
use TestApp\Model\Collection\ArticlesCollection;
use TestApp\Model\Collection\FakeCollection;

#[CoversClass(CollectionContainer::class)]
class CollectionContainerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        static::setAppNamespace();
    }

    public function testCollectionContainer(): void
    {
        $container = new Container();
        $container->delegate(new CollectionContainer());

        $collection = $container->get(ArticlesCollection::class);
        $this->assertInstanceOf(ArticlesCollection::class, $collection);
        $this->assertSame($collection, $container->get(ArticlesCollection::class));
    }

    public function testCollectionContainerMissingCollection(): void
    {
        $container = new Container();
        $container->delegate(new CollectionContainer());

        $this->expectException(NotFoundException::class);
        $container->get(FakeCollection::class);
    }
}

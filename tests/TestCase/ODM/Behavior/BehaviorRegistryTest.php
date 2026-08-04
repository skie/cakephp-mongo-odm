<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM\Behavior;

use BadMethodCallException;
use Crustum\Mongo\ODM\BehaviorRegistry;
use Crustum\Mongo\Test\TestCase\ODM\TestCase;

final class BehaviorRegistryTest extends TestCase
{
    public function testFindersAndExplicitCalls(): void
    {
        $registry = new BehaviorRegistry(new RegistryCollection());
        $registry->set('Example', new RegistryBehavior(new RegistryCollection()));

        $this->assertTrue($registry->hasFinder('active'));
        $this->assertSame('active', $registry->getFinder('ACTIVE')('active'));
        $this->assertSame('called', $registry->call('custom'));
    }

    public function testUnknownFinderThrows(): void
    {
        $registry = new BehaviorRegistry(new RegistryCollection());

        $this->expectException(BadMethodCallException::class);
        $registry->getFinder('missing');
    }
}

<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM\Behavior;

use Cake\Event\EventManager;

final class RegistryCollection
{
    public function getEventManager(): EventManager
    {
        return new EventManager();
    }
}

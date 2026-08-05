<?php
declare(strict_types=1);

namespace TestPlugin\Model\Behavior;

use Crustum\Mongo\ODM\Behavior;

class PersisterOneBehavior extends Behavior
{
    public function persist(): void
    {
    }
}

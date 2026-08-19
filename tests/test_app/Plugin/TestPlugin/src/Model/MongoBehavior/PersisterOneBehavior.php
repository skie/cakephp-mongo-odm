<?php
declare(strict_types=1);

namespace TestPlugin\Model\MongoBehavior;

use Crustum\Mongo\ODM\Behavior;

class PersisterOneBehavior extends Behavior
{
    public function persist(): void
    {
    }
}

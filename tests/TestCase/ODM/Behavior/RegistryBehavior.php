<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM\Behavior;

use Crustum\Mongo\ODM\Behavior;

final class RegistryBehavior extends Behavior
{
    public function findActive(mixed $value): string
    {
        return (string)$value;
    }

    public function custom(): string
    {
        return 'called';
    }
}

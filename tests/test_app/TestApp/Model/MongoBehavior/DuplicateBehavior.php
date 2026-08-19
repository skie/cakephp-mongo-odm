<?php
declare(strict_types=1);

namespace TestApp\Model\MongoBehavior;

use Crustum\Mongo\ODM\Behavior;

/**
 * Test class for triggering duplicate method errors.
 */
class DuplicateBehavior extends Behavior
{
    protected array $defaultConfig = [
        'implementedFinders' => [
            'children' => 'findChildren',
        ],
        'implementedMethods' => [
            'slugify' => 'slugify',
        ],
    ];

    public function findChildren(): void
    {
    }

    public function slugify(): void
    {
    }
}

<?php
declare(strict_types=1);

namespace TestApp\Model\MongoBehavior;

use Crustum\Mongo\ODM\Behavior;

class Test2Behavior extends Behavior
{
    protected array $defaultConfig = [
        'implementedFinders' => [
            'foo' => 'findFoo',
        ],
        'implementedMethods' => [
            'doSomething' => 'doSomething',
        ],
    ];

    /**
     * Test for event bindings.
     */
    public function beforeFind(): void
    {
    }

    /**
     * Test finder
     */
    public function findFoo(): void
    {
    }

    /**
     * Test method
     */
    public function doSomething(): void
    {
    }
}

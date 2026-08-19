<?php
declare(strict_types=1);

namespace TestApp\Model\MongoBehavior;

use Crustum\Mongo\ODM\Behavior;

class TestBehavior extends Behavior
{
    /**
     * Test for event bindings.
     */
    public function beforeFind(): void
    {
    }

    /**
     * Test for event bindings.
     */
    public function beforeRules(): void
    {
    }

    /**
     * Test for event bindings.
     */
    public function afterRules(): void
    {
    }

    /**
     * Test for event bindings.
     */
    public function buildRules(): void
    {
    }

    /**
     * Test for event bindings.
     */
    public function afterSaveCommit(): void
    {
    }

    /**
     * Test for event bindings.
     */
    public function afterDeleteCommit(): void
    {
    }
}

<?php
declare(strict_types=1);

namespace Crustum\Mongo\Migration\Db\Plan;

use Crustum\Mongo\Migration\Db\Action\Action;

/**
 * An intent is a collection of actions for one migration operation.
 *
 * Ported from cakephp/migrations `Db\Plan\Intent`.
 *
 * @ported-from \Migrations\Db\Plan\Intent
 */
class Intent
{
    /**
     * List of actions to be executed.
     *
     * @var list<\Crustum\Mongo\Migration\Db\Action\Action>
     */
    protected array $actions = [];

    /**
     * Adds a new action to the collection.
     *
     * @param \Crustum\Mongo\Migration\Db\Action\Action $action The action to add
     * @return $this
     */
    public function addAction(Action $action): static
    {
        $this->actions[] = $action;

        return $this;
    }

    /**
     * Returns the full list of actions.
     *
     * @return list<\Crustum\Mongo\Migration\Db\Action\Action>
     */
    public function getActions(): array
    {
        return $this->actions;
    }

    /**
     * Merges another intent into this one.
     *
     * @param \Crustum\Mongo\Migration\Db\Plan\Intent $another The other intent
     * @return void
     */
    public function merge(Intent $another): void
    {
        $this->actions = array_merge($this->actions, $another->getActions());
    }
}

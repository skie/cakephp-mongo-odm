<?php
declare(strict_types=1);

namespace Crustum\Mongo\Migration\Db\Action;

/**
 * Base class for migration actions.
 *
 * Ported and reduced from cakephp/migrations `Db\Action\Action` for the Mongo
 * operation set (collections, fields, indexes, validators).
 *
 * @inspired-by \Migrations\Db\Action\Action
 */
abstract class Action
{
    /**
     * Returns a human readable name for the action.
     *
     * @return string
     */
    abstract public function getName(): string;
}

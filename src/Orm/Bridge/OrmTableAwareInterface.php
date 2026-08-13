<?php
declare(strict_types=1);

namespace Crustum\Mongo\Orm\Bridge;

use Cake\ORM\Table;

/**
 * Contract for resolving a Cake ORM table by alias.
 *
 * Direction-2 helper for the ORM↔Mongo association bridge. Implemented by
 * Mongo ODM `Collection`s (and other services) that need to reach a Cake `Table`
 * declaratively. The concrete implementation reuses Cake's ORM
 * `LocatorAwareTrait::fetchTable()`.
 *
 * @see docs/reference/29-orm-mongo-association-bridge.md §4 (P1)
 */
interface OrmTableAwareInterface
{
    /**
     * Gets a Cake ORM table instance by alias.
     *
     * @param string $alias Table alias (e.g. `Orders`).
     * @param array<string, mixed> $options Construction options.
     * @return \Cake\ORM\Table
     */
    public function getOrmTable(string $alias, array $options = []): Table;
}

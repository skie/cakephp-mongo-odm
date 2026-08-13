<?php
declare(strict_types=1);

namespace Crustum\Mongo\Orm\Bridge;

use Crustum\Mongo\ODM\BaseCollection;

/**
 * Contract for resolving a Mongo ODM collection by alias.
 *
 * Direction-1 helper for the ORM↔Mongo association bridge. Implemented by
 * ORM `Table`s (and other services) that need to reach a Mongo `BaseCollection`
 * declaratively. The concrete implementation reuses the existing
 * `Crustum\Mongo\ODM\Locator\CollectionAwareTrait::fetchCollection()`.
 *
 * @see docs/reference/29-orm-mongo-association-bridge.md §4 (P1)
 */
interface MongoCollectionAwareInterface
{
    /**
     * Gets a Mongo collection instance by alias.
     *
     * @param string $alias Collection alias (e.g. `OrderDocuments`).
     * @param array<string, mixed> $options Construction options.
     * @return \Crustum\Mongo\ODM\BaseCollection
     */
    public function getMongoCollection(string $alias, array $options = []): BaseCollection;
}

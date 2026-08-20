<?php
declare(strict_types=1);

namespace TestApp\Model\Table;

use Cake\ORM\Table;
use Crustum\Mongo\Orm\Bridge\MongoAssociationsTrait;
use Crustum\Mongo\Orm\Bridge\MongoCollectionAwareInterface;
use Crustum\Mongo\Orm\Bridge\MongoCollectionAwareTrait;

/**
 * Minimal ORM Table for the cross-boundary bridge tests.
 *
 * Not backed by any database: it only carries an alias, a primary key, and the
 * `MongoCollectionAwareInterface` contract so `Orm\Bridge\Association` can
 * resolve a Mongo target collection declaratively. Persistence is out of scope
 * for the load-only bridge phases.
 */
class BridgeOrdersTable extends Table implements MongoCollectionAwareInterface
{
    use MongoAssociationsTrait;
    use MongoCollectionAwareTrait;

    /**
     * Returns the primary key without hitting a database schema.
     *
     * The bridge test table is not backed by SQL, so the inherited
     * `Table::getPrimaryKey()` (which introspects the schema) would fail with
     * "Cannot describe bridge_orders".
     *
     * @return array<string>|string
     */
    #[\Override]
    public function getPrimaryKey(): array|string
    {
        return 'id';
    }
}

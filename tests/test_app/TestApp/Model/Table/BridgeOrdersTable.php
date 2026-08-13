<?php
declare(strict_types=1);

namespace TestApp\Model\Table;

use Cake\Datasource\FactoryLocator;
use Cake\ORM\Table;
use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\Orm\Bridge\MongoCollectionAwareInterface;
use InvalidArgumentException;

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
    /**
     * @inheritDoc
     */
    public function getMongoCollection(string $alias, array $options = []): BaseCollection
    {
        $collection = FactoryLocator::get('Collection')->get($alias, $options);
        if (!$collection instanceof BaseCollection) {
            throw new InvalidArgumentException(sprintf(
                '`%s` did not resolve to a BaseCollection.',
                $alias,
            ));
        }

        return $collection;
    }

    /**
     * Returns the primary key without hitting a database schema.
     *
     * The bridge test table is not backed by SQL, so the inherited
     * `Table::getPrimaryKey()` (which introspects the schema) would fail with
     * "Cannot describe bridge_orders".
     *
     * @return array<string>|string
     */
    public function getPrimaryKey(): array|string
    {
        return 'id';
    }
}

<?php
declare(strict_types=1);

namespace Crustum\Mongo\Orm\Bridge;

use Cake\Datasource\FactoryLocator;
use Crustum\Mongo\ODM\BaseCollection;
use InvalidArgumentException;

/**
 * Default `MongoCollectionAwareInterface` implementation.
 *
 * Resolves a Mongo collection alias through the Collection locator, enforcing
 * that the result is a `Crustum\Mongo\ODM\BaseCollection`.
 *
 * @see docs/reference/29-orm-mongo-association-bridge.md §4
 */
trait MongoCollectionAwareTrait
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
}

<?php
declare(strict_types=1);

namespace Crustum\Mongo\Orm\Bridge;

use Cake\Datasource\FactoryLocator;
use Cake\ORM\Table;

/**
 * Default `OrmTableAwareInterface` implementation.
 *
 * Resolves an ORM table alias through the Cake Table locator.
 *
 * @see docs/reference/29-orm-mongo-association-bridge.md §4
 */
trait OrmTableAwareTrait
{
    /**
     * @inheritDoc
     */
    public function getOrmTable(string $alias, array $options = []): Table
    {
        return FactoryLocator::get('Table')->get($alias, $options);
    }
}

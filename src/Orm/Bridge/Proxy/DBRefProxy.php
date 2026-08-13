<?php
declare(strict_types=1);

namespace Crustum\Mongo\Orm\Bridge\Proxy;

use Cake\ORM\Association;
use Cake\ORM\Association\BelongsTo;
use Cake\ORM\Table;
use Crustum\Mongo\Orm\Bridge\Association as BridgeAssociation;

/**
 * ORM proxy for the Direction-1 `DBRef` bridge association.
 *
 * A SQL column holds a Mongo DBRef pointer; the proxy registers on
 * `Table->associations()` so `contain()`, entity property access, and `__call`
 * forwarding work like a native Cake BelongsTo while the load is a batched
 * Mongo query.
 *
 * @extends \Cake\ORM\Association\BelongsTo<\Cake\ORM\Table>
 * @see docs/reference/29-orm-mongo-association-bridge.md §4
 */
class DBRefProxy extends BelongsTo
{
    use ProxyTrait;

    /**
     * Constructor.
     *
     * @param string $alias Association alias.
     * @param \Cake\ORM\Table $source The ORM source table.
     * @param \Crustum\Mongo\Orm\Bridge\Association $bridge The bridge association.
     * @param array<string, mixed> $options Extra cake association options.
     */
    public function __construct(string $alias, Table $source, BridgeAssociation $bridge, array $options = [])
    {
        $this->setBridge($bridge);

        parent::__construct($alias, [
            'className' => $source::class,
            'sourceTable' => $source,
            'propertyName' => $bridge->getProperty(),
            'foreignKey' => $bridge->getForeignKey(),
            'bindingKey' => $bridge->getBindingKey(),
            'conditions' => $bridge->getConditions(),
            'strategy' => Association::STRATEGY_SELECT,
        ] + $options);
    }
}

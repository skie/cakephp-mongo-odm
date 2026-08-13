<?php
declare(strict_types=1);

namespace Crustum\Mongo\Orm\Bridge\Proxy;

use Cake\ORM\Association;
use Cake\ORM\Association\HasMany;
use Cake\ORM\Table;
use Crustum\Mongo\Orm\Bridge\Association as BridgeAssociation;

/**
 * ORM proxy for the Direction-1 `HasMany` bridge association.
 *
 * Registers on `Table->associations()` so `contain('Posts')`, entity property
 * access, and `__call` forwarding work like a native Cake HasMany while the
 * load is a batched Mongo query.
 *
 * @extends \Cake\ORM\Association\HasMany<\Cake\ORM\Table>
 * @see docs/reference/29-orm-mongo-association-bridge.md §4
 */
class HasManyProxy extends HasMany
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

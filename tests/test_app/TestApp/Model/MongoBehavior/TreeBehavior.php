<?php
declare(strict_types=1);

namespace TestApp\Model\MongoBehavior;

use Cake\Datasource\EntityInterface;
use Crustum\Mongo\ODM\Behavior;
use Crustum\Mongo\ODM\Query\SelectQuery;

/**
 * Minimal ODM port of the Tree behavior, exposing the `children` finder used
 * by registry tests.
 */
class TreeBehavior extends Behavior
{
    protected array $defaultConfig = [
        'implementedFinders' => [
            'children' => 'findChildren',
        ],
    ];

    public function findChildren(SelectQuery $query, array $options = []): SelectQuery
    {
        return $query;
    }

    public function childCount(EntityInterface $node, bool $direct = false, bool $includeSelf = false): int
    {
        return 0;
    }
}

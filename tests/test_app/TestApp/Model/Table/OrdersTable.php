<?php
declare(strict_types=1);

namespace TestApp\Model\Table;

use Cake\ORM\Table;
use Crustum\Mongo\Orm\Bridge\MongoAssociationsTrait;
use Crustum\Mongo\Orm\Bridge\MongoCollectionAwareInterface;
use Crustum\Mongo\Orm\Bridge\MongoCollectionAwareTrait;

/**
 * SQL ORM Orders table — full bridge source model (Direction 1).
 *
 * Declares every cross-boundary association type against Mongo targets:
 * BelongsTo (Authors), HasOne (Profiles), HasMany (Documents),
 * BelongsToMany (Tags) via the `mongo*()` sugar from
 * `MongoAssociationsTrait`.
 *
 * @see docs/reference/29-orm-mongo-association-bridge.md §4/§9
 */
class OrdersTable extends Table implements MongoCollectionAwareInterface
{
    use MongoAssociationsTrait;
    use MongoCollectionAwareTrait;

    /**
     * @inheritDoc
     */
    public function initialize(array $config): void
    {
        $this->setTable('orders');

        $this->mongoBelongsTo('Authors', ['property' => 'author']);
        $this->mongoHasOne('Profiles', ['property' => 'profile']);
        $this->mongoHasMany('Documents', ['property' => 'documents']);
        $this->mongoBelongsToMany('Tags', ['property' => 'tags']);
    }
}

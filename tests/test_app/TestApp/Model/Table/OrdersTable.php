<?php
declare(strict_types=1);

namespace TestApp\Model\Table;

use Cake\Datasource\FactoryLocator;
use Cake\ORM\Table;
use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\Orm\Bridge\Association;
use Crustum\Mongo\Orm\Bridge\BelongsTo;
use Crustum\Mongo\Orm\Bridge\BelongsToMany;
use Crustum\Mongo\Orm\Bridge\HasMany;
use Crustum\Mongo\Orm\Bridge\HasOne;
use Crustum\Mongo\Orm\Bridge\MongoCollectionAwareInterface;
use InvalidArgumentException;

/**
 * SQL ORM Orders table — full bridge source model (Direction 1).
 *
 * Declares every cross-boundary association type against Mongo targets:
 * BelongsTo (Author), HasOne (Profile), HasMany (Documents),
 * BelongsToMany (Tags). The `mongo*` methods are the P3 sugar on
 * `Orm\Bridge\Association`; until then the associations are plain properties.
 *
 * @see docs/reference/29-orm-mongo-association-bridge.md §4/§9
 */
class OrdersTable extends Table implements MongoCollectionAwareInterface
{
    /**
     * Declared bridge associations.
     *
     * @var array<string, \Crustum\Mongo\Orm\Bridge\Association>
     */
    protected array $bridgeAssociations = [];

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
     * Gets a declared bridge association by alias.
     *
     * @param string $name Association alias.
     * @return \Crustum\Mongo\Orm\Bridge\Association|null
     */
    public function getBridgeAssociation(string $name): ?Association
    {
        return $this->bridgeAssociations[$name] ?? null;
    }

    /**
     * Registers a BelongsTo bridge association.
     *
     * @param string $name Association alias.
     * @param array<string, mixed> $options Association options.
     * @return $this
     */
    public function mongoBelongsTo(string $name, array $options = []): static
    {
        $this->bridgeAssociations[$name] = new BelongsTo($name, $this, $options);

        return $this;
    }

    /**
     * Registers a HasOne bridge association.
     *
     * @param string $name Association alias.
     * @param array<string, mixed> $options Association options.
     * @return $this
     */
    public function mongoHasOne(string $name, array $options = []): static
    {
        $this->bridgeAssociations[$name] = new HasOne($name, $this, $options);

        return $this;
    }

    /**
     * Registers a HasMany bridge association.
     *
     * @param string $name Association alias.
     * @param array<string, mixed> $options Association options.
     * @return $this
     */
    public function mongoHasMany(string $name, array $options = []): static
    {
        $this->bridgeAssociations[$name] = new HasMany($name, $this, $options);

        return $this;
    }

    /**
     * Registers a BelongsToMany bridge association.
     *
     * @param string $name Association alias.
     * @param array<string, mixed> $options Association options.
     * @return $this
     */
    public function mongoBelongsToMany(string $name, array $options = []): static
    {
        $this->bridgeAssociations[$name] = new BelongsToMany($name, $this, $options);

        return $this;
    }
}

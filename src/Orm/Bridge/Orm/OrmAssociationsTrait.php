<?php
declare(strict_types=1);

namespace Crustum\Mongo\Orm\Bridge\Orm;

/**
 * Declarative Direction-2 sugar for an ODM Collection.
 *
 * Add `use OrmAssociationsTrait;` to a `Crustum\Mongo\ODM\BaseCollection`
 * (together with `OrmTableAwareTrait`) to register reverse cross-boundary
 * associations against Cake ORM Tables:
 *
 * ```
 * $this->belongsToOrm('Orders', ['foreignKey' => 'order_id']);
 * $this->hasManyOrm('Reports', ['foreignKey' => 'order_id']);
 * ```
 *
 * @see docs/reference/29-orm-mongo-association-bridge.md §8–§9
 */
trait OrmAssociationsTrait
{
    /**
     * The registered Direction-2 associations by alias.
     *
     * @var array<string, \Crustum\Mongo\Orm\Bridge\Orm\OrmAssociation>
     */
    protected array $ormAssociations = [];

    /**
     * Registers a Direction-2 BelongsTo bridge association.
     *
     * @param string $name Association alias.
     * @param array<string, mixed> $options Bridge association options.
     * @return \Crustum\Mongo\Orm\Bridge\Orm\BelongsToOrm
     */
    public function belongsToOrm(string $name, array $options = []): BelongsToOrm
    {
        return $this->ormAssociations[$name] = new BelongsToOrm($name, $this, $options);
    }

    /**
     * Registers a Direction-2 HasOne bridge association.
     *
     * @param string $name Association alias.
     * @param array<string, mixed> $options Bridge association options.
     * @return \Crustum\Mongo\Orm\Bridge\Orm\HasOneOrm
     */
    public function hasOneOrm(string $name, array $options = []): HasOneOrm
    {
        return $this->ormAssociations[$name] = new HasOneOrm($name, $this, $options);
    }

    /**
     * Registers a Direction-2 HasMany bridge association.
     *
     * @param string $name Association alias.
     * @param array<string, mixed> $options Bridge association options.
     * @return \Crustum\Mongo\Orm\Bridge\Orm\HasManyOrm
     */
    public function hasManyOrm(string $name, array $options = []): HasManyOrm
    {
        return $this->ormAssociations[$name] = new HasManyOrm($name, $this, $options);
    }

    /**
     * Gets a registered Direction-2 association by alias.
     *
     * @param string $name Association alias.
     * @return \Crustum\Mongo\Orm\Bridge\Orm\OrmAssociation|null
     */
    public function getOrmAssociation(string $name): ?OrmAssociation
    {
        return $this->ormAssociations[$name] ?? null;
    }

    /**
     * Loads all registered Direction-2 associations for the given documents.
     *
     * @param iterable<\Cake\Datasource\EntityInterface> $documents Source documents.
     * @return void
     */
    public function loadOrmAssociations(iterable $documents): void
    {
        foreach ($this->ormAssociations as $association) {
            $association->load($documents);
        }
    }
}

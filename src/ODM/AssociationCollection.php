<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM;

use ArrayIterator;
use Cake\Core\Exception\CakeException;
use Cake\Datasource\EntityInterface;
use Cake\Datasource\Locator\LocatorInterface;
use Countable;
use Crustum\Mongo\ODM\Association\BelongsTo;
use Crustum\Mongo\ODM\Locator\LocatorAwareTrait;
use InvalidArgumentException;
use IteratorAggregate;
use Traversable;
use function Cake\Core\namespaceSplit;
use function Cake\Core\pluginSplit;

/**
 * A registry for ODM association objects.
 *
 * @see cake60/src/ORM/AssociationCollection.php
 * @implements \IteratorAggregate<string, \Crustum\Mongo\ODM\Association>
 */
class AssociationCollection implements Countable, IteratorAggregate
{
    use AssociationsNormalizerTrait;
    use LocatorAwareTrait;

    /**
     * Registered associations keyed by alias.
     *
     * @var array<string, \Crustum\Mongo\ODM\Association>
     */
    protected array $items = [];

    /**
     * Constructor.
     *
     * Sets the default collection locator for associations.
     * If no locator is provided, the global one will be used.
     *
     * @param \Cake\Datasource\Locator\LocatorInterface<covariant \Cake\Datasource\RepositoryInterface>|null $collectionLocator Collection locator instance.
     */
    public function __construct(?LocatorInterface $collectionLocator = null)
    {
        if ($collectionLocator instanceof LocatorInterface) {
            $this->collectionLocator = $collectionLocator;
        }
    }

    /**
     * Adds an association to the registry.
     *
     * @template T of \Crustum\Mongo\ODM\Association
     * @param string $alias Association alias.
     * @param T $association Association instance.
     * @return T
     * @throws \Cake\Core\Exception\CakeException If the alias is already registered.
     */
    public function add(string $alias, Association $association): Association
    {
        [, $alias] = pluginSplit($alias);
        if (isset($this->items[$alias])) {
            throw new CakeException(sprintf('Association alias `%s` is already set.', $alias));
        }

        $this->items[$alias] = $association;

        return $association;
    }

    /**
     * Creates and registers an association.
     *
     * @template T of \Crustum\Mongo\ODM\Association
     * @param class-string<T> $className Association class.
     * @param string $associated Target alias.
     * @param \Crustum\Mongo\ODM\BaseCollection $sourceCollection Source collection.
     * @param array<string, mixed> $options Association options.
     * @return T
     * @throws \InvalidArgumentException If the class is not an association.
     */
    public function load(string $className, string $associated, BaseCollection $sourceCollection, array $options = []): Association
    {
        if (!class_exists($className) || !is_subclass_of($className, Association::class)) {
            throw new InvalidArgumentException(sprintf('`%s` must extend `%s`.', $className, Association::class));
        }

        $association = new $className($associated, $sourceCollection, $options);

        return $this->add($association->getName(), $association);
    }

    /**
     * Checks whether an alias is registered.
     *
     * @param string $alias Association alias.
     * @return bool
     */
    public function has(string $alias): bool
    {
        return isset($this->items[$alias]);
    }

    /**
     * Removes an association.
     *
     * @param string $alias Association alias.
     * @return void
     */
    public function remove(string $alias): void
    {
        unset($this->items[$alias]);
    }

    /**
     * Gets associations, optionally filtered by class.
     *
     * @param class-string<\Crustum\Mongo\ODM\Association>|null $type Association class.
     * @return array<int, \Crustum\Mongo\ODM\Association>
     */
    public function type(?string $type = null): array
    {
        return $type === null ? array_values($this->items) : array_values(array_filter($this->items, fn(Association $a): bool => $a instanceof $type));
    }

    /**
     * Gets all registered aliases.
     *
     * @return array<string>
     */
    public function keys(): array
    {
        return array_keys($this->items);
    }

    /**
     * Iterates over registered associations.
     *
     * @return \Traversable<string, \Crustum\Mongo\ODM\Association>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    /**
     * Removes all registered associations.
     *
     * @return void
     */
    public function clear(): void
    {
        $this->items = [];
    }

    /**
     * Returns the number of registered associations.
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Gets an association by alias.
     *
     * @param string $alias Association alias.
     * @return \Crustum\Mongo\ODM\Association|null
     */
    public function get(string $alias): ?Association
    {
        return $this->items[$alias] ?? null;
    }

    /**
     * Gets associations matching one or more class names.
     *
     * @param array<class-string<\Crustum\Mongo\ODM\Association>>|class-string<\Crustum\Mongo\ODM\Association> $class Association classes.
     * @return array<int, \Crustum\Mongo\ODM\Association>
     */
    public function getByType(array|string $class): array
    {
        $classes = array_map(strtolower(...), (array)$class);

        return array_values(array_filter($this->items, function (Association $association) use ($classes): bool {
            [, $name] = namespaceSplit($association::class);

            return in_array(strtolower($name), $classes, true);
        }));
    }

    /**
     * Gets an association by its entity property.
     *
     * @param string $property Entity property name.
     * @return \Crustum\Mongo\ODM\Association|null
     */
    public function getByProperty(string $property): ?Association
    {
        foreach ($this->items as $association) {
            if ($association->getProperty() === $property) {
                return $association;
            }
        }

        return null;
    }

    /**
     * Removes all registered associations.
     *
     * Once removed associations will no longer be reachable
     *
     * @return void
     */
    public function removeAll(): void
    {
        foreach (array_keys($this->items) as $alias) {
            $this->remove($alias);
        }
    }

    /**
     * Save all the associations that are parents of the given entity.
     *
     * Parent associations include any association where the given collection
     * is the owning side.
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $table The collection entity is for.
     * @param \Cake\Datasource\EntityInterface $entity The entity to save associated data for.
     * @param array<int|string, mixed> $associations The list of associations to save parents from.
     *   associations not in this list will not be saved.
     * @param array<string, mixed> $options The options for the save operation.
     * @return bool Success
     */
    public function saveParents(BaseCollection $table, EntityInterface $entity, array $associations, array $options = []): bool
    {
        if ($associations === []) {
            return true;
        }

        return $this->saveAssociations($table, $entity, $associations, $options, false);
    }

    /**
     * Save all the associations that are children of the given entity.
     *
     * Child associations include any association where the given collection
     * is not the owning side.
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $table The collection entity is for.
     * @param \Cake\Datasource\EntityInterface $entity The entity to save associated data for.
     * @param array<int|string, mixed> $associations The list of associations to save children from.
     *   associations not in this list will not be saved.
     * @param array<string, mixed> $options The options for the save operation.
     * @return bool Success
     */
    public function saveChildren(BaseCollection $table, EntityInterface $entity, array $associations, array $options): bool
    {
        if ($associations === []) {
            return true;
        }

        return $this->saveAssociations($table, $entity, $associations, $options, true);
    }

    /**
     * Helper method for saving an association's data.
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $table The collection the save is currently operating on
     * @param \Cake\Datasource\EntityInterface $entity The entity to save
     * @param array<int|string, mixed> $associations Array of associations to save.
     * @param array<string, mixed> $options Original options
     * @param bool $owningSide Compared with association classes'
     *   isOwningSide method.
     * @return bool Success
     * @throws \InvalidArgumentException When an unknown alias is used.
     */
    protected function saveAssociations(
        BaseCollection $table,
        EntityInterface $entity,
        array $associations,
        array $options,
        bool $owningSide,
    ): bool {
        unset($options['associated']);
        foreach ($associations as $alias => $nested) {
            if (is_int($alias)) {
                $alias = $nested;
                $nested = [];
            }

            $relation = $this->get($alias);
            if (!$relation instanceof Association) {
                $msg = sprintf(
                    'Cannot save `%s`, it is not associated to `%s`.',
                    $alias,
                    $table->getAlias(),
                );
                throw new InvalidArgumentException($msg);
            }

            $isParent = $relation instanceof BelongsTo;
            if ($isParent === $owningSide) {
                continue;
            }

            $nested = is_array($nested) ? $nested : [];
            if (!$this->save($relation, $entity, $nested, $options)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Helper method for saving an association's data.
     *
     * @param \Crustum\Mongo\ODM\Association $association The association object to save with.
     * @param \Cake\Datasource\EntityInterface $entity The entity to save
     * @param array<string, mixed> $nested Options for deeper associations
     * @param array<string, mixed> $options Original options
     * @return bool Success
     */
    protected function save(
        Association $association,
        EntityInterface $entity,
        array $nested,
        array $options,
    ): bool {
        if (!$entity->isDirty($association->getProperty())) {
            return true;
        }

        if ($nested !== []) {
            $options = $nested + $options;
        }

        return (bool)$association->saveAssociated($entity, $options);
    }

    /**
     * Cascade a delete across the various associations.
     * Cascade first across associations for which cascadeCallbacks is true.
     *
     * @param \Cake\Datasource\EntityInterface $entity The entity to delete associations for.
     * @param array<string, mixed> $options The options used in the delete operation.
     * @return bool
     */
    public function cascadeDelete(EntityInterface $entity, array $options): bool
    {
        $noCascade = [];
        foreach ($this->items as $assoc) {
            if (!$assoc->getCascadeCallbacks()) {
                $noCascade[] = $assoc;
                continue;
            }

            $success = $assoc->cascadeDelete($entity, $options);
            if (!$success) {
                return false;
            }
        }

        foreach ($noCascade as $assoc) {
            $success = $assoc->cascadeDelete($entity, $options);
            if (!$success) {
                return false;
            }
        }

        return true;
    }

    /**
     * Returns an associative array of association names out a mixed
     * array. If true is passed, then it returns all association names
     * in this collection.
     *
     * @param array<string, \Crustum\Mongo\ODM\Association>|string|bool $keys the list of association names to normalize
     * @return array<string, \Crustum\Mongo\ODM\Association>
     */
    public function normalizeKeys(array|string|bool $keys): array
    {
        if ($keys === true) {
            $keys = $this->keys();
        }

        if (!$keys) {
            return [];
        }

        return $this->normalizeAssociations($keys);
    }
}

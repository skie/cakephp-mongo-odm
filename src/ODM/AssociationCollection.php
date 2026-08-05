<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM;

use ArrayIterator;
use Cake\Core\Exception\CakeException;
use Countable;
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

    /**
     * Registered associations keyed by alias.
     *
     * @var array<string, \Crustum\Mongo\ODM\Association>
     */
    protected array $items = [];

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
}

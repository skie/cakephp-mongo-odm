<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM;

use BadMethodCallException;
use Cake\Core\App;
use Cake\Core\ObjectRegistry;
use Cake\Event\EventDispatcherInterface;
use Cake\Event\EventDispatcherTrait;
use Closure;
use Crustum\Mongo\Exception\MissingBehaviorException;
use InvalidArgumentException;
use LogicException;

/**
 * Registry and event dispatcher for ODM behaviors.
 *
 * The registry owns behavior construction, event registration, finder
 * dispatch, and explicit behavior method dispatch for a collection.
 *
 * @extends \Cake\Core\ObjectRegistry<\Crustum\Mongo\ODM\Behavior>
 * @see cake60/src/ORM/BehaviorRegistry.php
 */
final class BehaviorRegistry extends ObjectRegistry implements EventDispatcherInterface
{
    use EventDispatcherTrait;

    /**
     * Finder aliases mapped to behavior aliases and method names.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    protected array $finderMap = [];

    /**
     * Explicit behavior method aliases mapped to behavior aliases and method names.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    protected array $methodMap = [];

    /**
     * Constructor.
     *
     * @param \Crustum\Mongo\ODM\BaseCollection|null $collection The collection using this registry.
     */
    public function __construct(protected ?BaseCollection $collection = null)
    {
        if ($collection instanceof BaseCollection) {
            $this->setEventManager($collection->getEventManager());
        }
    }

    /**
     * Attaches a collection to this registry.
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $collection The collection to attach.
     * @return void
     */
    public function setCollection(BaseCollection $collection): void
    {
        $this->collection = $collection;
        $this->setEventManager($collection->getEventManager());
    }

    /**
     * Resolves a behavior class name.
     *
     * @param string $class The short or fully-qualified class name.
     * @return class-string<\Crustum\Mongo\ODM\Behavior>|null
     */
    protected function _resolveClassName(string $class): ?string
    {
        if (class_exists($class)) {
            return is_a($class, Behavior::class, true) ? $class : null;
        }

        $candidate = App::className($class, 'Model/Behavior', 'Behavior')
            ?: App::className($class, 'ODM/Behavior', 'Behavior');
        if ($candidate !== null && is_a($candidate, Behavior::class, true)) {
            return $candidate;
        }

        $candidate = __NAMESPACE__ . '\\Behavior\\' . $class . 'Behavior';

        return class_exists($candidate) && is_a($candidate, Behavior::class, true) ? $candidate : null;
    }

    /**
     * Throws when a behavior class cannot be resolved.
     *
     * @param string $class The requested class.
     * @param string|null $plugin The plugin name, if any.
     * @return never
     */
    protected function _throwMissingClassError(string $class, ?string $plugin): never
    {
        throw new MissingBehaviorException([
            'class' => $class . 'Behavior',
            'plugin' => $plugin,
        ]);
    }

    /**
     * Creates and initializes a behavior.
     *
     * @param object|class-string<\Crustum\Mongo\ODM\Behavior> $class The behavior class or instance.
     * @param string $alias The registry alias.
     * @param array<string, mixed> $config Configuration options.
     * @return \Crustum\Mongo\ODM\Behavior
     */
    protected function _create(object|string $class, string $alias, array $config): Behavior
    {
        if (is_object($class)) {
            $instance = $class;
        } else {
            if (!$this->collection instanceof BaseCollection) {
                throw new LogicException('A collection is required before loading a behavior.');
            }

            $instance = new $class($this->collection, $config);
        }

        if (!$instance instanceof Behavior) {
            throw new InvalidArgumentException('Behavior classes must extend the ODM Behavior base class.');
        }

        if ($config['enabled'] ?? true) {
            $this->getEventManager()->on($instance);
        }

        $this->registerMethods($instance, $alias);

        return $instance;
    }

    /**
     * Sets a behavior instance directly into the registry.
     *
     * @param string $name The behavior alias.
     * @param object $object The behavior instance.
     * @return $this
     */
    public function set(string $name, object $object): static
    {
        if (!$object instanceof Behavior) {
            throw new InvalidArgumentException('Behavior instances must extend the ODM Behavior base class.');
        }

        parent::set($name, $object);
        $this->registerMethods($object, $name);

        return $this;
    }

    /**
     * Removes a behavior from the registry.
     *
     * @param string $name The behavior alias.
     * @return $this
     */
    public function unload(string $name): static
    {
        $behavior = $this->get($name);
        parent::unload($name);

        $this->getEventManager()->off($behavior);

        foreach ($behavior->implementedFinders() as $finder) {
            unset($this->finderMap[strtolower((string)$finder)]);
        }

        foreach ($this->methodMap as $method => $binding) {
            if ($binding[0] === $name) {
                unset($this->methodMap[$method]);
            }
        }

        return $this;
    }

    /**
     * Calls an explicitly exposed behavior method.
     *
     * @param string $method The method alias.
     * @param mixed ...$args Arguments passed to the behavior method.
     * @return mixed The behavior method result.
     * @throws \BadMethodCallException If no behavior exposes the method.
     */
    public function call(string $method, mixed ...$args): mixed
    {
        $binding = $this->methodMap[strtolower($method)] ?? null;
        if ($binding !== null && $this->has($binding[0])) {
            return $this->get($binding[0])->{$binding[1]}(...$args);
        }

        throw new BadMethodCallException(sprintf('Cannot call `%s`, it does not belong to an attached behavior.', $method));
    }

    /**
     * Checks whether a behavior implements a finder.
     *
     * @param string $method The finder alias.
     * @return bool
     */
    public function hasFinder(string $method): bool
    {
        return isset($this->finderMap[strtolower($method)]);
    }

    /**
     * Gets a finder callable from an attached behavior.
     *
     * @param string $method The finder alias.
     * @return \Closure
     * @throws \BadMethodCallException If no behavior exposes the finder.
     */
    public function getFinder(string $method): Closure
    {
        $binding = $this->finderMap[strtolower($method)] ?? null;
        if ($binding !== null && $this->has($binding[0])) {
            return $this->get($binding[0])->getFinder($binding[1]);
        }

        throw new BadMethodCallException(sprintf('Finder `%s` is not implemented by an attached behavior.', $method));
    }

    /**
     * Registers a behavior's finders and methods in the registry maps.
     *
     * @param string $alias The behavior alias.
     * @return void
     */
    private function registerMethods(Behavior $behavior, string $alias): void
    {
        foreach ($behavior->implementedFinders() as $finder => $method) {
            $key = strtolower((string)$finder);
            if (isset($this->finderMap[$key]) && $this->has($this->finderMap[$key][0])) {
                throw new LogicException(sprintf('Duplicate finder `%s`.', $finder));
            }

            $this->finderMap[$key] = [$alias, $method];
        }

        foreach ($behavior->implementedMethods() as $method => $methodName) {
            $this->methodMap[strtolower((string)$method)] = [$alias, (string)$methodName];
        }
    }
}

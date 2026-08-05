<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM;

use Cake\Core\Exception\CakeException;
use Cake\Core\InstanceConfigTrait;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventListenerInterface;
use Closure;
use ReflectionClass;
use ReflectionMethod;

/**
 * Base class for ODM behaviors.
 *
 * Behaviors provide reusable event listeners and finder methods for ODM
 * collections. Method dispatch is explicit through {@see BehaviorRegistry};
 * this class intentionally does not provide a magic `__call()` bridge.
 *
 * @see cake60/src/ORM/Behavior.php
 */
abstract class Behavior implements EventListenerInterface
{
    use InstanceConfigTrait;

    /**
     * Reflection-derived finder and method maps, indexed by behavior class.
     *
     * @var array<class-string, array{finders: array<string, string>, methods: array<string, string>}>
     */
    protected static array $reflectionCache = [];

    /**
     * Default behavior configuration.
     *
     * @var array<string, mixed>
     */
    protected array $defaultConfig = [];

    /**
     * Constructor.
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $collection The collection the behavior is attached to.
     * @param array<string, mixed> $config Behavior configuration.
     */
    public function __construct(protected BaseCollection $collection, array $config = [])
    {
        $this->_config = array_replace($this->defaultConfig, $config);
        $this->_configInitialized = true;
        $this->initialize($this->getConfig());
    }

    /**
     * Constructor hook.
     *
     * @param array<string, mixed> $config The resolved configuration.
     * @return void
     */
    public function initialize(array $config): void
    {
    }

    /**
     * Gets the collection this behavior is attached to.
     *
     * @return \Crustum\Mongo\ODM\BaseCollection
     */
    public function collection(): BaseCollection
    {
        return $this->collection;
    }

    /**
     * Gets the model callbacks implemented by this behavior.
     *
     * @return array<string, mixed>
     */
    public function implementedEvents(): array
    {
        $events = [];
        foreach (
            [
            'Collection.beforeMarshal' => 'beforeMarshal',
            'Collection.afterMarshal' => 'afterMarshal',
            'Collection.beforeFind' => 'beforeFind',
            'Collection.beforeSave' => 'beforeSave',
            'Collection.afterSave' => 'afterSave',
            'Collection.afterSaveCommit' => 'afterSaveCommit',
            'Collection.beforeDelete' => 'beforeDelete',
            'Collection.afterDelete' => 'afterDelete',
            'Collection.afterDeleteCommit' => 'afterDeleteCommit',
            'Collection.buildValidator' => 'buildValidator',
            'Collection.buildRules' => 'buildRules',
            'Collection.beforeRules' => 'beforeRules',
            'Collection.afterRules' => 'afterRules',
            ] as $event => $method
        ) {
            if (!method_exists($this, $method)) {
                continue;
            }

            $priority = $this->getConfig('priority');
            $events[$event] = $priority === null
                ? $method
                : ['callable' => $method, 'priority' => $priority];
        }

        return $events;
    }

    /**
     * Gets the finder aliases implemented by this behavior.
     *
     * @return array<string, string>
     */
    public function implementedFinders(): array
    {
        $configured = $this->getConfig('implementedFinders');
        if (is_array($configured)) {
            return $configured;
        }

        return $this->reflectionFinders();
    }

    /**
     * Gets the public methods exposed through the behavior registry.
     *
     * @return array<string, string>
     */
    public function implementedMethods(): array
    {
        $configured = $this->getConfig('implementedMethods');
        if (is_array($configured)) {
            return $configured;
        }

        $this->reflectionFinders();

        return self::$reflectionCache[static::class]['methods'];
    }

    /**
     * Gets a finder callable.
     *
     * @param string $type The finder alias or method name.
     * @return \Closure
     * @throws \Cake\Core\Exception\CakeException If the finder is not implemented.
     */
    public function getFinder(string $type): Closure
    {
        $finders = array_change_key_case($this->implementedFinders());
        $method = $finders[strtolower($type)] ?? $type;
        if (!is_callable([$this, $method])) {
            throw new CakeException(sprintf('Finder `%s` is not implemented by `%s`.', $type, static::class));
        }

        return $this->{$method}(...);
    }

    /**
     * Verifies configured finder aliases.
     *
     * @return void
     * @throws \Cake\Core\Exception\CakeException If a configured finder is not callable.
     */
    public function verifyConfig(): void
    {
        $finders = $this->getConfig('implementedFinders');
        if (!is_array($finders)) {
            return;
        }

        foreach ($finders as $method) {
            if (!is_string($method) || !is_callable([$this, $method])) {
                throw new CakeException(sprintf('The finder method `%s` is not callable on `%s`.', (string)$method, static::class));
            }
        }
    }

    /**
     * Builds the finder and method maps from public methods.
     *
     * @return array<string, string>
     */
    private function reflectionFinders(): array
    {
        $class = static::class;
        if (isset(self::$reflectionCache[$class])) {
            return self::$reflectionCache[$class]['finders'];
        }

        $events = [];
        foreach ($this->implementedEvents() as $binding) {
            $events[] = is_array($binding) ? $binding['callable'] : $binding;
        }

        $baseMethods = get_class_methods(self::class);
        $finders = [];
        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $name = $method->getName();
            if (!in_array($name, $baseMethods, true) && !in_array($name, $events, true) && str_starts_with($name, 'find')) {
                $finders[lcfirst(substr($name, 4))] = $name;
            }
        }

        $methods = [];
        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $name = $method->getName();
            if (in_array($name, $baseMethods, true)) {
                continue;
            }

            if (in_array($name, $events, true)) {
                continue;
            }

            if (str_starts_with($name, 'find')) {
                continue;
            }

            $methods[$name] = $name;
        }

        self::$reflectionCache[$class] = ['finders' => $finders, 'methods' => $methods];

        return $finders;
    }

    /**
     * Whether the given value is a new entity.
     *
     * @param mixed $entity The value to check.
     * @return bool
     */
    protected function isNewEntity(mixed $entity): bool
    {
        return $entity instanceof EntityInterface && $entity->isNew();
    }
}

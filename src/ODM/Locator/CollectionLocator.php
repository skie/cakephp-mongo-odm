<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Locator;

use Cake\Core\App;
use Cake\Database\Exception\DatabaseException;
use Cake\Datasource\ConnectionManager;
use Cake\Datasource\Locator\AbstractLocator;
use Cake\Datasource\Locator\LocatorInterface;
use Cake\Datasource\RepositoryInterface;
use Cake\Utility\Inflector;
use Crustum\Mongo\ODM\AssociationCollection;
use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\ODM\Exception\MissingCollectionException;
use Crustum\Mongo\ODM\Query\QueryFactory;
use function Cake\Core\pluginSplit;

/**
 * Factory and registry for ODM collections.
 *
 * @see cake60/src/ORM/Locator/TableLocator.php
 * @implements \Cake\Datasource\Locator\LocatorInterface<\Cake\Datasource\RepositoryInterface>
 * @extends \Cake\Datasource\Locator\AbstractLocator<\Cake\Datasource\RepositoryInterface>
 */
class CollectionLocator extends AbstractLocator implements LocatorInterface
{
    /**
     * Contains a list of locations where collection classes should be looked for.
     *
     * @var array<string>
     */
    protected array $locations = [];

    /**
     * Configuration for aliases.
     *
     * @var array<string, array<string, mixed>|null>
     */
    protected array $config = [];

    /**
     * Contains a list of collection objects that were created out of the
     * built-in BaseCollection class. The list is indexed by collection alias.
     *
     * @var array<\Cake\Datasource\RepositoryInterface>
     */
    protected array $fallbacked = [];

    /**
     * Fallback class to use.
     *
     * @var class-string<\Cake\Datasource\RepositoryInterface>
     */
    protected string $fallbackClassName = BaseCollection::class;

    /**
     * Whether fallback class should be used if a collection class could not be found.
     *
     * @var bool
     */
    protected bool $allowFallbackClass = true;

    /**
     * Query factory used to build ODM queries.
     *
     * @var \Crustum\Mongo\ODM\Query\QueryFactory
     */
    protected QueryFactory $queryFactory;

    /**
     * Constructor.
     *
     * @param array<string>|null $locations Locations where collections should be looked for.
     *   If none provided, the default `Model\Collection` under your app's namespace is used.
     * @param \Crustum\Mongo\ODM\Query\QueryFactory|null $queryFactory Query factory to share across collections.
     */
    public function __construct(?array $locations = null, ?QueryFactory $queryFactory = null)
    {
        if ($locations === null) {
            $locations = [
                'Model/Collection',
            ];
        }

        foreach ($locations as $location) {
            $this->addLocation($location);
        }

        $this->queryFactory = $queryFactory ?: new QueryFactory();
    }

    /**
     * Set if fallback class should be used.
     *
     * Controls whether a fallback class should be used to create a collection
     * instance if a concrete class for alias used in `get()` could not be found.
     *
     * @param bool $allow Flag to enable or disable fallback.
     * @return $this
     */
    public function allowFallbackClass(bool $allow): static
    {
        $this->allowFallbackClass = $allow;

        return $this;
    }

    /**
     * Set fallback class name.
     *
     * The class that should be used to create a collection instance if a
     * concrete class for alias used in `get()` could not be found. Defaults to
     * `Crustum\Mongo\ODM\BaseCollection`.
     *
     * @param class-string<\Cake\Datasource\RepositoryInterface> $className Fallback class name.
     * @return $this
     */
    public function setFallbackClassName(string $className): static
    {
        $this->fallbackClassName = $className;

        return $this;
    }

    /**
     * Sets configuration for an alias, or replaces all configuration.
     *
     * @param array<string, array<string, mixed>|null>|string $alias Alias name or full config map.
     * @param array<string, mixed>|null $options Options for the alias.
     * @return $this
     * @throws \Cake\Database\Exception\DatabaseException When the alias has already been constructed.
     */
    public function setConfig(array|string $alias, ?array $options = null): static
    {
        if (!is_string($alias)) {
            $this->config = $alias;

            return $this;
        }

        if (isset($this->instances[$alias])) {
            throw new DatabaseException(sprintf(
                'You cannot configure `%s`, it has already been constructed.',
                $alias,
            ));
        }

        $this->config[$alias] = $options;

        return $this;
    }

    /**
     * Gets configuration for an alias, or the entire config map.
     *
     * @param string|null $alias Alias name, or `null` for all configuration.
     * @return array<string, array<string, mixed>|null>|array<string, mixed>
     */
    public function getConfig(?string $alias = null): array
    {
        if ($alias === null) {
            return $this->config;
        }

        return $this->config[$alias] ?? [];
    }

    /**
     * Gets a collection instance from the registry.
     *
     * @param string $alias The collection alias.
     * @param array<string, mixed> $options Construction options.
     * @return \Cake\Datasource\RepositoryInterface
     */
    public function get(string $alias, array $options = []): RepositoryInterface
    {
        return parent::get($alias, $options);
    }

    /**
     * @inheritDoc
     */
    protected function createInstance(string $alias, array $options): RepositoryInterface
    {
        if (!str_contains($alias, '\\')) {
            [, $classAlias] = pluginSplit($alias);
            $options = ['alias' => $classAlias] + $options;
        } elseif (!isset($options['alias'])) {
            $options['className'] = $alias;
        }

        if (isset($this->config[$alias])) {
            $options += $this->config[$alias];
        }

        $allowFallbackClass = $options['allowFallbackClass'] ?? $this->allowFallbackClass;
        $className = $this->getClassName($alias, $options);
        if ($className) {
            $options['className'] = $className;
        } elseif ($allowFallbackClass) {
            if (empty($options['className'])) {
                $options['className'] = $alias;
            }

            if (!isset($options['collection']) && !str_contains($options['className'], '\\')) {
                [, $table] = pluginSplit($options['className']);
                $options['collection'] = Inflector::underscore($table);
            }

            $options['className'] = $this->fallbackClassName;
        } else {
            $message = $options['className'] ?? $alias;
            $message = '`' . $message . '`';
            if (!str_contains($message, '\\')) {
                $message = 'for alias ' . $message;
            }

            throw new MissingCollectionException([$message]);
        }

        if (empty($options['connection'])) {
            if (!empty($options['connectionName'])) {
                $connectionName = $options['connectionName'];
            } else {
                /** @var class-string<\Crustum\Mongo\ODM\BaseCollection> $className */
                $className = $options['className'];
                $connectionName = $className::defaultConnectionName();
            }

            $options['connection'] = ConnectionManager::get($connectionName);
        }

        if (empty($options['associations'])) {
            $options['associations'] = new AssociationCollection($this);
        }

        if (empty($options['queryFactory'])) {
            $options['queryFactory'] = $this->queryFactory;
        }

        $options['registryAlias'] = $alias;
        $instance = $this->create($options);

        if ($options['className'] === $this->fallbackClassName) {
            $this->fallbacked[$alias] = $instance;
        }

        return $instance;
    }

    /**
     * Gets the collection class name.
     *
     * @param string $alias The alias name you want to get. Should be in CamelCase format.
     * @param array<string, mixed> $options Collection options array.
     * @return class-string<\Cake\Datasource\RepositoryInterface>|null
     */
    protected function getClassName(string $alias, array $options = []): ?string
    {
        if (empty($options['className'])) {
            $options['className'] = $alias;
        }

        if (str_contains($options['className'], '\\') && class_exists($options['className'])) {
            return is_a($options['className'], RepositoryInterface::class, true)
                ? $options['className']
                : null;
        }

        foreach ($this->locations as $location) {
            $class = App::className($options['className'], $location, 'Collection');
            if ($class !== null && is_a($class, RepositoryInterface::class, true)) {
                return $class;
            }
        }

        return null;
    }

    /**
     * Wrapper for creating collection instances.
     *
     * @param array<string, mixed> $options Options used to create the collection.
     * @return \Cake\Datasource\RepositoryInterface
     */
    protected function create(array $options): RepositoryInterface
    {
        /** @var class-string<\Cake\Datasource\RepositoryInterface> $class */
        $class = $options['className'];

        return new $class($options);
    }

    /**
     * @inheritDoc
     */
    public function clear(): void
    {
        parent::clear();

        $this->fallbacked = [];
        $this->config = [];
    }

    /**
     * Returns the list of collections that were created by this registry that
     * could not be instantiated from a specific subclass. This method is useful
     * for debugging common mistakes when setting up associations or creating
     * new collection classes.
     *
     * @return array<\Cake\Datasource\RepositoryInterface>
     */
    public function genericInstances(): array
    {
        return $this->fallbacked;
    }

    /**
     * @inheritDoc
     */
    public function remove(string $alias): void
    {
        parent::remove($alias);

        unset($this->fallbacked[$alias]);
    }

    /**
     * Adds a location where collection classes should be looked for.
     *
     * @param string $location Location to add.
     * @return $this
     */
    public function addLocation(string $location): static
    {
        $location = str_replace('\\', '/', $location);
        $this->locations[] = trim($location, '/');

        return $this;
    }
}

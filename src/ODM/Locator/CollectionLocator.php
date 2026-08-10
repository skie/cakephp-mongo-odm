<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Locator;

use Cake\Core\App;
use Cake\Datasource\Locator\AbstractLocator;
use Cake\Datasource\Locator\LocatorInterface;
use Cake\Datasource\RepositoryInterface;
use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\ODM\Exception\MissingCollectionException;
use function Cake\Core\pluginSplit;

/**
 * Factory and registry for ODM collections.
 *
 * @see cake60/src/ORM/Locator/TableLocator.php
 * @see src/ODM/Locator/CollectionLocator.php
 * @implements \Cake\Datasource\Locator\LocatorInterface<\Cake\Datasource\RepositoryInterface>
 * @extends \Cake\Datasource\Locator\AbstractLocator<\Cake\Datasource\RepositoryInterface>
 */
class CollectionLocator extends AbstractLocator implements LocatorInterface
{
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
     * Creates a collection instance for an alias.
     *
     * @param string $alias The registry alias.
     * @param array<string, mixed> $options Construction options.
     * @return \Cake\Datasource\RepositoryInterface
     * @throws \Crustum\Mongo\ODM\Exception\MissingCollectionException If no valid class can be resolved.
     */
    protected function createInstance(string $alias, array $options): RepositoryInterface
    {
        [, $classAlias] = pluginSplit($alias);
        $options = ['alias' => $classAlias] + $options;
        $options['className'] ??= $alias;

        $className = $this->resolveClassName($options['className']);
        if ($className === null && ($options['allowFallbackClass'] ?? true)) {
            $className = BaseCollection::class;
        }

        if ($className === null) {
            throw new MissingCollectionException([$options['className']]);
        }

        $options['className'] = $className;
        $options['registryAlias'] = $alias;

        /** @var class-string<\Cake\Datasource\RepositoryInterface> $className */
        return new $className($options);
    }

    /**
     * Resolves an application or plugin collection class.
     *
     * @param string $className Alias or fully-qualified class name.
     * @return class-string<\Cake\Datasource\RepositoryInterface>|null
     */
    protected function resolveClassName(string $className): ?string
    {
        if (str_contains($className, '\\') && class_exists($className)) {
            return is_a($className, RepositoryInterface::class, true) ? $className : null;
        }

        $className = App::className($className, 'Model/Collection', 'Collection');

        if ($className === null || !is_a($className, RepositoryInterface::class, true)) {
            return null;
        }

        return $className;
    }
}

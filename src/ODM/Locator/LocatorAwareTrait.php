<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Locator;

use Cake\Datasource\FactoryLocator;
use Cake\Datasource\RepositoryInterface;
use UnexpectedValueException;

/**
 * Provides access to the application ODM collection locator.
 *
 * @see cake50/src/ORM/Locator/LocatorAwareTrait.php
 * @see REF: src/ODM/Locator/LocatorAwareTrait.php
 */
trait LocatorAwareTrait
{
    /**
     * The default collection alias.
     *
     * @var string|null
     */
    protected ?string $defaultCollection = null;

    /**
     * The collection locator instance.
     *
     * @var \Crustum\Mongo\ODM\Locator\CollectionLocator|null
     */
    protected ?CollectionLocator $collectionLocator = null;

    /**
     * Sets the collection locator.
     *
     * @param \Crustum\Mongo\ODM\Locator\CollectionLocator $collectionLocator Locator to use for fetching collections.
     * @return $this
     */
    public function setCollectionLocator(CollectionLocator $collectionLocator): static
    {
        $this->collectionLocator = $collectionLocator;

        return $this;
    }

    /**
     * Gets the collection locator.
     *
     * Falls back to the factory-registered locator for the `Collection` type.
     *
     * @return \Crustum\Mongo\ODM\Locator\CollectionLocator The configured or factory-registered locator.
     * @throws \UnexpectedValueException If the factory contains another locator type.
     */
    public function getCollectionLocator(): CollectionLocator
    {
        if ($this->collectionLocator !== null) {
            return $this->collectionLocator;
        }

        $locator = FactoryLocator::get('Collection');
        if (!$locator instanceof CollectionLocator) {
            throw new UnexpectedValueException(
                '`FactoryLocator` must return a CollectionLocator for type `Collection`.',
            );
        }

        return $this->collectionLocator = $locator;
    }

    /**
     * Fetches a collection instance by alias.
     *
     * @param string|null $alias Collection alias, or the configured default.
     * @param array<string, mixed> $options Collection construction options.
     * @return \Cake\Datasource\RepositoryInterface
     * @throws \UnexpectedValueException If no alias is supplied or configured.
     */
    public function fetchCollection(?string $alias = null, array $options = []): RepositoryInterface
    {
        $alias ??= $this->defaultCollection;
        if (!$alias) {
            throw new UnexpectedValueException(
                'You must provide an `$alias` or set the `$defaultCollection` property to a non empty string.',
            );
        }

        return $this->getCollectionLocator()->get($alias, $options);
    }
}

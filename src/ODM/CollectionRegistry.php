<?php
declare(strict_types=1);

/**
 * Migrated from Cake core `Cake\ORM\TableRegistry`.
 *
 * Provides a static accessor for the `Collection` locator registered in the
 * datasource `FactoryLocator`.
 */
namespace Crustum\Mongo\ODM;

use Cake\Datasource\FactoryLocator;
use Cake\Datasource\Locator\LocatorInterface;

/**
 * Provides a registry/factory for Collection objects.
 *
 * This registry allows you to centralize the configuration for collections,
 * their connections and other meta-data.
 *
 * ```
 * CollectionRegistry::getCollectionLocator()->get('Users', $config);
 * ```
 */
class CollectionRegistry
{
    /**
     * Returns the singleton locator for collections.
     *
     * @return \Cake\Datasource\Locator\LocatorInterface<\Cake\Datasource\RepositoryInterface>
     */
    public static function getCollectionLocator(): LocatorInterface
    {
        /** @var \Cake\Datasource\Locator\LocatorInterface<\Cake\Datasource\RepositoryInterface> */
        return FactoryLocator::get('Collection');
    }

    /**
     * Sets the singleton locator for collections.
     *
     * @param \Cake\Datasource\Locator\LocatorInterface<\Cake\Datasource\RepositoryInterface> $collectionLocator Locator instance to use.
     * @return void
     */
    public static function setCollectionLocator(LocatorInterface $collectionLocator): void
    {
        FactoryLocator::add('Collection', $collectionLocator);
    }
}

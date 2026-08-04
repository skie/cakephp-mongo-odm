<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Locator;

use Cake\Datasource\RepositoryInterface;
use Psr\Container\ContainerInterface;

/**
 * PSR-11 access to ODM collections.
 *
 * @see cake60/src/ORM/Locator/TableContainer.php
 */
class CollectionContainer implements ContainerInterface
{
    use LocatorAwareTrait;

    /**
     * Gets a collection from the configured locator.
     *
     * @param string $id The collection alias or class name.
     * @return \Cake\Datasource\RepositoryInterface
     */
    public function get(string $id): RepositoryInterface
    {
        return $this->fetchCollection($id);
    }

    /**
     * Checks whether an identifier names a concrete collection class.
     *
     * @param string $id The identifier to check.
     * @return bool
     */
    public function has(string $id): bool
    {
        return str_ends_with($id, 'Collection')
            && is_subclass_of($id, RepositoryInterface::class);
    }
}

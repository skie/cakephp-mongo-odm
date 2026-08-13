<?php
declare(strict_types=1);

namespace TestApp\Model\Collection;

/**
 * Users collection bound to the dedicated `users_embedded` test collection,
 * reusing the embedded Addresses/Profile associations.
 */
class UsersEmbeddedCollection extends UsersCollection
{
    /**
     * The collection name.
     *
     * @var string
     */
    protected ?string $collection = 'users_embedded';
}

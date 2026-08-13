<?php
declare(strict_types=1);

namespace TestApp\Model\Collection;

use Crustum\Mongo\ODM\BaseCollection;
use TestApp\Model\Document\Address;

class UsersCollection extends BaseCollection
{
    /**
     * @inheritDoc
     */
    public function initialize(array $config): void
    {
        $this->embedMany('Addresses', [
            'documentClass' => Address::class,
            'propertyName' => 'addresses',
        ]);
        $this->embedOne('Profile', [
            'documentClass' => Address::class,
            'propertyName' => 'profile',
        ]);
    }
}

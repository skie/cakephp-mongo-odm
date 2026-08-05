<?php
declare(strict_types=1);

namespace TestApp\Model\Collection;

use Crustum\Mongo\ODM\BaseCollection;

class MyUsersCollection extends BaseCollection
{
    protected ?string $collection = 'users';
}

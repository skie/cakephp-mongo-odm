<?php
declare(strict_types=1);

namespace TestApp\Model\Collection;

use Crustum\Mongo\ODM\BaseCollection;

class PostsCollection extends BaseCollection
{
    protected ?string $collection = 'posts';
}

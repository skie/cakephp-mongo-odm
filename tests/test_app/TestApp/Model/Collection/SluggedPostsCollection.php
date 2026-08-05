<?php
declare(strict_types=1);

namespace TestApp\Model\Collection;

use Crustum\Mongo\ODM\BaseCollection;

class SluggedPostsCollection extends BaseCollection
{
    public function initialize(array $config): void
    {
        $this->setCollection('posts');
        $this->addBehavior('Sluggable');
    }
}

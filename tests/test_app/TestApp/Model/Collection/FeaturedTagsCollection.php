<?php
declare(strict_types=1);

namespace TestApp\Model\Collection;

use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\ODM\CollectionRegistry;

class FeaturedTagsCollection extends BaseCollection
{
    protected mixed $Posts;

    public function initialize(array $config): void
    {
        $this->Posts = CollectionRegistry::getCollectionLocator()->get('Posts');
    }
}

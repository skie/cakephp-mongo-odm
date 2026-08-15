<?php
declare(strict_types=1);

namespace TestApp\Model\Collection;

use Crustum\Mongo\ODM\BaseCollection;

class BridgePostsCollection extends BaseCollection
{
    protected ?string $collection = 'bridge_posts';
}

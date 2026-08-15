<?php
declare(strict_types=1);

namespace TestApp\Model\Collection;

use Crustum\Mongo\ODM\BaseCollection;

class BridgeTagsCollection extends BaseCollection
{
    protected ?string $collection = 'bridge_tags';
}

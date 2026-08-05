<?php
declare(strict_types=1);

namespace TestApp\Model\Collection;

use Crustum\Mongo\ODM\BaseCollection;

class GreedyCommentsCollection extends BaseCollection
{
    public function initialize(array $config): void
    {
        $this->setCollection('comments');
        $this->setAlias('Comments');
    }
}

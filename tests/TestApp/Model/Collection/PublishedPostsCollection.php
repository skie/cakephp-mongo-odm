<?php
declare(strict_types=1);

namespace TestApp\Model\Collection;

use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\ODM\Query\SelectQuery;

class PublishedPostsCollection extends BaseCollection
{
    public function findPublished(SelectQuery $query, array $options = []): SelectQuery
    {
        return $query->where(['published' => true]);
    }
}

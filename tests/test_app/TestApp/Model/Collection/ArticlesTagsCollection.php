<?php
declare(strict_types=1);

namespace TestApp\Model\Collection;

use Crustum\Mongo\ODM\BaseCollection;

class ArticlesTagsCollection extends BaseCollection
{
    public function initialize(array $config): void
    {
        $this->belongsTo('Articles');
        $this->belongsTo('Tags');
    }
}

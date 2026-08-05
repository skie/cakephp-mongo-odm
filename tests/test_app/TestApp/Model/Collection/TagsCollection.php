<?php
declare(strict_types=1);

namespace TestApp\Model\Collection;

use Cake\Utility\Text;
use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\ODM\Query\SelectQuery;

class TagsCollection extends BaseCollection
{
    public function initialize(array $config): void
    {
        $this->belongsTo('Authors');
        $this->belongsToMany('Articles');
        $this->hasMany('ArticlesTags', ['propertyName' => 'extraInfo']);
    }

    public function findSlugged(SelectQuery $query): SelectQuery
    {
        return $query->applyOptions(['preserveKeys' => true])
            ->formatResults(fn($results) => $results->indexBy(fn($record): string => Text::slug($record->name)));
    }
}

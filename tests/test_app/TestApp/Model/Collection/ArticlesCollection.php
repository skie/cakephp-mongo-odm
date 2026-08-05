<?php
declare(strict_types=1);

namespace TestApp\Model\Collection;

use Cake\Utility\Text;
use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\ODM\Query\SelectQuery;

class ArticlesCollection extends BaseCollection
{
    public function initialize(array $config): void
    {
        $this->belongsTo('Authors');
        $this->belongsToMany('Tags');
        $this->hasMany('ArticlesTags');
    }

    public function findPublished(SelectQuery $query, ?string $title = null): SelectQuery
    {
        $query->where(['published' => 'Y']);

        if ($title !== null) {
            $query->andWhere(['title' => $title]);
        }

        return $query;
    }

    public function findWithAuthors(SelectQuery $query, array $options = []): SelectQuery
    {
        return $query->contain('Authors');
    }

    public function findSlugged(SelectQuery $query): SelectQuery
    {
        return $query->formatResults(function ($results) {
            return $results->indexBy(function ($row) {
                return Text::slug($row['title']);
            });
        });
    }

    public function doSomething(): void
    {
    }

    public function doSomethingElse(): void
    {
    }

    protected function innerMethod(): void
    {
    }
}

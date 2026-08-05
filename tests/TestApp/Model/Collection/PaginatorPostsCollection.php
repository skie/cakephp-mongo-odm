<?php
declare(strict_types=1);

namespace TestApp\Model\Collection;

use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\ODM\Query\SelectQuery;

class PaginatorPostsCollection extends BaseCollection
{
    public function initialize(array $config): void
    {
        $this->setCollection('posts');
        $this->belongsTo('PaginatorAuthor', [
            'foreignKey' => 'author_id',
        ]);
    }

    public function findPopular(SelectQuery $query): SelectQuery
    {
        $field = $this->getAlias() . '.' . $this->getPrimaryKey();
        $query->where([$field . ' >' => '1']);

        return $query;
    }

    public function findPublished(SelectQuery $query): SelectQuery
    {
        $query->where(['published' => 'Y']);

        return $query;
    }

    public function findAuthor(SelectQuery $query, ?int $authorId = null): SelectQuery
    {
        if ($authorId) {
            $query->where(['PaginatorPosts.author_id' => $authorId]);
        }

        return $query;
    }
}

<?php
declare(strict_types=1);

namespace TestApp\Model\Collection;

use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\ODM\Query\SelectQuery;

class AuthorsCollection extends BaseCollection
{
    public function initialize(array $config): void
    {
        $this->hasMany('Articles');
    }

    public function findByAuthor(SelectQuery $query, ?int $authorId = null): SelectQuery
    {
        if ($authorId !== null) {
            $query->where(['articles.id' => $authorId]);
        }

        return $query;
    }

    public function findFormatted(SelectQuery $query, array $options = []): SelectQuery
    {
        return $query->formatResults(fn($results) => $results->map(function ($author) {
            $author->formatted = $author->name . '!!';

            return $author;
        }));
    }

    public function findWithIdArgument(SelectQuery $query, int $id): SelectQuery
    {
        return $query->where(['id' => $id]);
    }

    public function findCustom(SelectQuery $query, array $id = [], bool $second = true): SelectQuery
    {
        return $query;
    }

    public function findCustom2(SelectQuery $query, array $id, bool $second): SelectQuery
    {
        return $query;
    }
}

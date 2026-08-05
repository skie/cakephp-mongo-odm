<?php
declare(strict_types=1);

namespace TestApp\Model\Collection;

use Cake\Core\Exception\CakeException;
use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\ODM\Query\SelectQuery;

class AuthUsersCollection extends BaseCollection
{
    public function findAuth(SelectQuery $query, array $options): SelectQuery
    {
        $query->select(['id', 'username', 'password']);
        if (!empty($options['return_created'])) {
            $query->select(['created']);
        }

        return $query;
    }

    public function findUsername(SelectQuery $query, array $options): SelectQuery
    {
        if (empty($options['username'])) {
            throw new CakeException(__('Username not defined'));
        }

        $query = $this->find()
            ->where(['username' => $options['username']])
            ->select(['id', 'username', 'password']);

        return $query;
    }
}

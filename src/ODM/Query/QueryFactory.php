<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Query;

use Cake\Datasource\RepositoryInterface;
use Crustum\Mongo\Database\Connection;

/**
 * Creates ODM queries bound to a repository.
 *
 * @see cake60/src/ORM/Query/QueryFactory.php
 */
final class QueryFactory
{
    /**
     * Creates a hydrated select query.
     *
     * @param \Cake\Datasource\RepositoryInterface $repository Repository.
     * @return \Crustum\Mongo\ODM\Query\SelectQuery
     */
    public function select(RepositoryInterface $repository): SelectQuery
    {
        return new SelectQuery($this->connection($repository), $repository->getAlias(), $repository);
    }

    /**
     * Creates an unhydrated select query.
     *
     * @param \Cake\Datasource\RepositoryInterface $repository Repository.
     * @return \Crustum\Mongo\ODM\Query\UnhydratedSelectQuery
     */
    public function unhydratedSelect(RepositoryInterface $repository): UnhydratedSelectQuery
    {
        return new UnhydratedSelectQuery($this->connection($repository), $repository->getAlias(), $repository);
    }

    /** @param \Cake\Datasource\RepositoryInterface $repository @return \Crustum\Mongo\ODM\Query\InsertQuery */
    public function insert(RepositoryInterface $repository): InsertQuery
    {
        return new InsertQuery($this->connection($repository), $repository->getAlias(), $repository);
    }

    /** @param \Cake\Datasource\RepositoryInterface $repository @return \Crustum\Mongo\ODM\Query\UpdateQuery */
    public function update(RepositoryInterface $repository): UpdateQuery
    {
        return new UpdateQuery($this->connection($repository), $repository->getAlias(), $repository);
    }

    /** @param \Cake\Datasource\RepositoryInterface $repository @return \Crustum\Mongo\ODM\Query\DeleteQuery */
    public function delete(RepositoryInterface $repository): DeleteQuery
    {
        return new DeleteQuery($this->connection($repository), $repository->getAlias(), $repository);
    }

    /** @return \Crustum\Mongo\Database\Connection|null */
    private function connection(RepositoryInterface $repository): ?Connection
    {
        $connection = method_exists($repository, 'getConnection') ? $repository->getConnection() : null;

        return $connection instanceof Connection ? $connection : null;
    }
}

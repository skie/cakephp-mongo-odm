<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Query;

use Crustum\Mongo\ODM\BaseCollection;

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
     * @param \Crustum\Mongo\ODM\BaseCollection $repository Repository.
     * @return \Crustum\Mongo\ODM\Query\SelectQuery
     */
    public function select(BaseCollection $repository): SelectQuery
    {
        return new SelectQuery($repository);
    }

    /**
     * Creates an unhydrated select query.
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $repository Repository.
     * @return \Crustum\Mongo\ODM\Query\UnhydratedSelectQuery
     */
    public function unhydratedSelect(BaseCollection $repository): UnhydratedSelectQuery
    {
        return new UnhydratedSelectQuery($repository);
    }

    /**
     * Creates an insert query.
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $repository Repository.
     * @return \Crustum\Mongo\ODM\Query\InsertQuery
     */
    public function insert(BaseCollection $repository): InsertQuery
    {
        return new InsertQuery($repository->getConnection(), $repository->getCollection(), $repository);
    }

    /**
     * Creates an update query.
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $repository Repository.
     * @return \Crustum\Mongo\ODM\Query\UpdateQuery
     */
    public function update(BaseCollection $repository): UpdateQuery
    {
        return new UpdateQuery($repository->getConnection(), $repository->getCollection(), $repository);
    }

    /**
     * Creates a delete query.
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $repository Repository.
     * @return \Crustum\Mongo\ODM\Query\DeleteQuery
     */
    public function delete(BaseCollection $repository): DeleteQuery
    {
        return new DeleteQuery($repository->getConnection(), $repository->getCollection(), $repository);
    }
}

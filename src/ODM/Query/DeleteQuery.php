<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Query;

use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Database\Query\DeleteQuery as DatabaseDeleteQuery;
use Crustum\Mongo\ODM\BaseCollection;

/**
 * ODM delete query bound to a repository.
 *
 * @see cake60/src/ORM/Query/DeleteQuery.php
 */
class DeleteQuery extends DatabaseDeleteQuery
{
    use CommonQueryTrait;

    /**
     * Constructor.
     *
     * Accepts either a `BaseCollection` (cake-compatible: `new DeleteQuery($collection)`)
     * or the low-level `(connection, collection, repository)` signature.
     *
     * @param \Crustum\Mongo\ODM\BaseCollection|\Crustum\Mongo\Database\Connection|null $connection Repository or connection.
     * @param string $collection BaseCollection name.
     * @param \Crustum\Mongo\ODM\BaseCollection|null $repository Repository.
     */
    public function __construct(
        BaseCollection|Connection|null $connection = null,
        string $collection = '',
        ?BaseCollection $repository = null,
    ) {
        if ($connection instanceof BaseCollection) {
            $repository = $connection;
            $connection = $repository->getConnection();
            $collection = $repository->getCollection();
        }

        parent::__construct($connection, $collection);
        if ($repository instanceof BaseCollection) {
            $this->setRepository($repository);
            $this->addDefaultTypes();
        }
    }
}

<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Query;

use Cake\Datasource\RepositoryInterface;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Database\Query\UpdateQuery as DatabaseUpdateQuery;

/**
 * ODM update query bound to a repository schema.
 *
 * @see cake60/src/ORM/Query/UpdateQuery.php
 */
class UpdateQuery extends DatabaseUpdateQuery
{
    use CommonQueryTrait;

    /**
     * Constructor.
     *
     * @param \Crustum\Mongo\Database\Connection|null $connection Connection.
     * @param string $collection Collection name.
     * @param \Cake\Datasource\RepositoryInterface|null $repository Repository.
     */
    public function __construct(
        ?Connection $connection = null,
        string $collection = '',
        ?RepositoryInterface $repository = null,
    ) {
        parent::__construct($connection, $collection);
        if ($repository instanceof RepositoryInterface) {
            $this->setRepository($repository);
            $this->addDefaultTypes();
        }
    }
}

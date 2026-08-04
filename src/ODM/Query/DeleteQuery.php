<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Query;

use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Database\Query\DeleteQuery as DatabaseDeleteQuery;
use Crustum\Mongo\ODM\Collection;

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
     * @param \Crustum\Mongo\Database\Connection|null $connection Connection.
     * @param string $collection Collection name.
     * @param \Crustum\Mongo\ODM\Collection|null $repository Repository.
     */
    public function __construct(
        ?Connection $connection = null,
        string $collection = '',
        ?Collection $repository = null,
    ) {
        parent::__construct($connection, $collection);
        if ($repository instanceof Collection) {
            $this->setRepository($repository);
            $this->addDefaultTypes();
        }
    }
}

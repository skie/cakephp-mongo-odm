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
     * @param \Crustum\Mongo\Database\Connection|null $connection Connection.
     * @param string $collection BaseCollection name.
     * @param \Crustum\Mongo\ODM\BaseCollection|null $repository Repository.
     */
    public function __construct(
        ?Connection $connection = null,
        string $collection = '',
        ?BaseCollection $repository = null,
    ) {
        parent::__construct($connection, $collection);
        if ($repository instanceof BaseCollection) {
            $this->setRepository($repository);
            $this->addDefaultTypes();
        }
    }
}

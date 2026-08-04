<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Query;

use Cake\Datasource\EntityInterface;
use Cake\Datasource\RepositoryInterface;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Database\Query\InsertQuery as DatabaseInsertQuery;

/**
 * ODM insert query that accepts Documents and arrays.
 *
 * @see cake60/src/ORM/Query/InsertQuery.php
 */
class InsertQuery extends DatabaseInsertQuery
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

    /**
     * Sets a document or array to insert.
     *
     * @param \Cake\Datasource\EntityInterface|array<string, mixed> $values Document values.
     * @param bool $overwrite Whether to replace queued values.
     * @return $this
     */
    public function values(array|EntityInterface $values, bool $overwrite = false): static
    {
        return parent::values($values instanceof EntityInterface ? $values->toArray() : $values, $overwrite);
    }

    /**
     * Sets multiple documents to insert.
     *
     * @param array<int, array<string, mixed>|\Cake\Datasource\EntityInterface> $values Documents.
     * @return $this
     */
    public function valuesMany(array $values): static
    {
        $values = array_values(array_map(
            static fn(array|EntityInterface $value): array => $value instanceof EntityInterface
                ? $value->toArray()
                : $value,
            $values,
        ));

        return parent::valuesMany($values);
    }
}

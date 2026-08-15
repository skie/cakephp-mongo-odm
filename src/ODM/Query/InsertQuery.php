<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Query;

use Cake\Datasource\EntityInterface;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Database\Query\InsertQuery as DatabaseInsertQuery;
use Crustum\Mongo\Database\Type\TypeFactory;
use Crustum\Mongo\ODM\BaseCollection;

/**
 * ODM insert query that accepts Documents and arrays.
 *
 * Values are converted through the repository schema type map before reaching
 * the database query, and a generated `_id` is back-filled into source
 * Documents so they reflect the persisted identifier.
 *
 * @see cake60/src/ORM/Query/InsertQuery.php
 */
class InsertQuery extends DatabaseInsertQuery
{
    use CommonQueryTrait;

    /**
     * Constructor.
     *
     * Accepts either a `BaseCollection` (cake-compatible: `new InsertQuery($collection)`)
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

    /**
     * Sets a document or array to insert.
     *
     * The document is converted through the schema type map and given a
     * generated `_id` when missing; the `_id` is back-filled into the source
     * Document.
     *
     * @param \Cake\Datasource\EntityInterface|array<string, mixed> $values Document values.
     * @param bool $overwrite Whether to replace queued values.
     * @return $this
     */
    public function values(array|EntityInterface $values, bool $overwrite = false): static
    {
        return parent::values($this->prepareDocument($values), $overwrite);
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
            $this->prepareDocument(...),
            $values,
        ));

        return parent::valuesMany($values);
    }

    /**
     * Converts a document through the schema type map and ensures an `_id` exists.
     *
     * @param \Cake\Datasource\EntityInterface|array<string, mixed> $values The document.
     * @return array<string, mixed>
     */
    protected function prepareDocument(array|EntityInterface $values): array
    {
        $document = $this->convertToDatabaseValues($values instanceof EntityInterface ? $values->toArray() : $values);

        if (!isset($document['_id'])) {
            $document['_id'] = $this->newId();
            if ($values instanceof EntityInterface) {
                $values->set('_id', $document['_id']);
            }
        }

        return $document;
    }

    /**
     * Generates a new identifier through the configured `_id` type.
     *
     * @return mixed
     */
    protected function newId(): mixed
    {
        $types = $this->getTypeMap()->toArray();
        $type = $types['_id'] ?? 'objectid';

        return TypeFactory::build($type)->newId();
    }
}

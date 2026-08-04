<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Query;

use Cake\Datasource\EntityInterface;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Database\Query\UpdateQuery as DatabaseUpdateQuery;
use Crustum\Mongo\ODM\Collection;

/**
 * ODM update query bound to a repository schema.
 *
 * Values passed to `set()` are converted through the repository schema type map.
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

    /**
     * Adds a `$set` assignment, converting values through the schema type map.
     *
     * Accepts a `Document`/`EntityInterface` whose fields become the `$set` map.
     *
     * @param \Cake\Datasource\EntityInterface|array<string, mixed>|string $field Field name, map, or document.
     * @param mixed $value The value (when `$field` is a single name).
     * @return $this
     */
    public function set(array|string|EntityInterface $field, mixed $value = null): static
    {
        if ($field instanceof EntityInterface) {
            return parent::set($this->convertToDatabaseValues($field->toArray()));
        }

        if (is_array($field)) {
            return parent::set($this->convertToDatabaseValues($field));
        }

        if ($value !== null) {
            $converted = $this->convertToDatabaseValues([$field => $value]);

            return parent::set($field, $converted[$field]);
        }

        return parent::set($field);
    }
}

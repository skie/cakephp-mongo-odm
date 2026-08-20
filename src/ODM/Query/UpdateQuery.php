<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Query;

use Cake\Datasource\EntityInterface;
use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Database\Expression\MongoExpressionInterface;
use Crustum\Mongo\Database\Expression\UpdateOperatorExpression;
use Crustum\Mongo\Database\Query\SelectQuery;
use Crustum\Mongo\Database\Query\UpdateQuery as DatabaseUpdateQuery;
use Crustum\Mongo\ODM\BaseCollection;
use InvalidArgumentException;
use Override;

/**
 * ODM update query bound to a repository schema.
 *
 * Values passed to `set()` are converted through the repository schema type map.
 *
 * @rewritten-from \Cake\ORM\Query\UpdateQuery
 */
class UpdateQuery extends DatabaseUpdateQuery
{
    use CommonQueryTrait;

    /**
     * Constructor.
     *
     * Accepts either a `BaseCollection` (cake-compatible: `new UpdateQuery($collection)`)
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
     * Adds a `$set` assignment, converting values through the schema type map.
     *
     * Accepts a `Document`/`EntityInterface` whose fields become the `$set` map.
     * `SelectQuery` / expression values (cake subquery-in-SET) are materialized to
     * BSON before type casting — classic Mongo `$set` cannot embed a query object.
     *
     * @param \Cake\Datasource\EntityInterface|array<string, mixed>|string $field Field name, map, or document.
     * @param mixed $value The value (when `$field` is a single name).
     * @return $this
     */
    #[Override]
    public function set(array|string|EntityInterface $field, mixed $value = null): static
    {
        if ($field instanceof EntityInterface) {
            return parent::set($this->convertToDatabaseValues($field->toArray()));
        }

        if (is_array($field)) {
            $set = [];
            foreach ($field as $key => $value) {
                if ($value instanceof UpdateOperatorExpression) {
                    $this->applyUpdateOperator((string)$key, $value);

                    continue;
                }

                $set[$key] = $this->resolveSetValue($value);
            }

            if ($set !== []) {
                parent::set($this->convertToDatabaseValues($set));
            }

            return $this;
        }

        if ($value instanceof UpdateOperatorExpression) {
            $this->applyUpdateOperator($field, $value);

            return $this;
        }

        if ($value !== null) {
            $converted = $this->convertToDatabaseValues([$field => $this->resolveSetValue($value)]);

            return parent::set($field, $converted[$field]);
        }

        return parent::set($field);
    }

    /**
     * Materializes expression / SelectQuery values in a `$set` map.
     *
     * @param array<string, mixed> $fields Field => value map.
     * @return array<string, mixed>
     */
    protected function resolveSetMap(array $fields): array
    {
        foreach ($fields as $key => $value) {
            $fields[$key] = $this->resolveSetValue($value);
        }

        return $fields;
    }

    /**
     * Turns cake-style SET expressions into values Mongo `$set` can store.
     *
     * A `SelectQuery` used as an update value (CounterCache subquery) must select a
     * single constant / `$literal` expression — there is no SQL subquery-in-SET.
     *
     * @param mixed $value The SET right-hand side.
     * @return mixed
     * @throws \InvalidArgumentException When a SelectQuery cannot be materialized.
     */
    protected function resolveSetValue(mixed $value): mixed
    {
        if ($value instanceof SelectQuery) {
            $projection = $value->clause('select');
            if (!is_array($projection) || $projection === []) {
                throw new InvalidArgumentException(
                    'SelectQuery used as an update value must select a single constant or expression.',
                );
            }

            return $this->resolveSetValue(reset($projection));
        }

        if ($value instanceof MongoExpressionInterface) {
            $value = $value->getConditions();
        }

        if (is_array($value) && count($value) === 1 && array_key_exists('$literal', $value)) {
            return $value['$literal'];
        }

        return $value;
    }
}

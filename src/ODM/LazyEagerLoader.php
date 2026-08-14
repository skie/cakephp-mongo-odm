<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM;

use Cake\Datasource\EntityInterface;
use Crustum\Mongo\ODM\Query\SelectQuery;

/**
 * Contains methods that are capable of injecting eagerly loaded associations into
 * documents or lists of documents by using the same syntax as the EagerLoader.
 *
 * @internal
 * @see cake60/src/ORM/LazyEagerLoader.php
 */
class LazyEagerLoader
{
    /**
     * Loads the specified associations in the passed document or list of documents
     * by executing extra queries in the collection and merging the results in the
     * appropriate properties.
     *
     * The properties for the associations to be loaded will be overwritten on each document.
     *
     * @param \Cake\Datasource\EntityInterface|array<\Cake\Datasource\EntityInterface> $entities a single document or list of documents
     * @param array<int|string, mixed> $contain A `contain()` compatible array.
     * @see \Crustum\Mongo\ODM\Query\SelectQuery::contain()
     * @param \Crustum\Mongo\ODM\BaseCollection $source The collection to use for fetching the top level documents
     * @return \Cake\Datasource\EntityInterface|array<\Cake\Datasource\EntityInterface>
     */
    public function loadInto(EntityInterface|array $entities, array $contain, BaseCollection $source): EntityInterface|array
    {
        $returnSingle = false;

        if ($entities instanceof EntityInterface) {
            $entities = [$entities];
            $returnSingle = true;
        }

        $query = $this->getQuery($entities, $contain, $source);
        $associations = array_values(array_filter(
            array_keys($query->getContain()),
            is_string(...),
        ));

        $entities = $this->injectResults($entities, $query, $associations, $source);

        return $returnSingle ? array_shift($entities) : $entities;
    }

    /**
     * Builds a query that loads the passed documents plus the requested
     * associations, mirroring cake60 `LazyEagerLoader::getQuery()`.
     *
     * @param array<\Cake\Datasource\EntityInterface> $entities The original documents.
     * @param array<int|string, mixed> $contain The associations to be loaded.
     * @param \Crustum\Mongo\ODM\BaseCollection $source The collection the documents came from.
     * @return \Crustum\Mongo\ODM\Query\SelectQuery
     */
    protected function getQuery(array $entities, array $contain, BaseCollection $source): SelectQuery
    {
        $primaryKey = $source->getPrimaryKey();
        $method = is_string($primaryKey) ? 'get' : 'extract';

        $keys = [];
        foreach ($entities as $entity) {
            $keys[] = $entity->{$method}($primaryKey);
        }

        return $source
            ->find()
            ->select((array)$primaryKey)
            ->where(function ($exp) use ($primaryKey, $keys, $source): mixed {
                if (is_array($primaryKey) && count($primaryKey) === 1) {
                    $primary = current($primaryKey);
                } else {
                    $primary = is_string($primaryKey) ? $primaryKey : (string)current($primaryKey);
                }

                return $exp->in($source->aliasField($primary), $keys);
            })
            ->contain($contain);
    }

    /**
     * Returns a map of property names where the association results should be injected
     * in the top level documents.
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $source The collection having the top level associations
     * @param array<string> $associations The name of the top level associations
     * @return array<string, string>
     */
    protected function getPropertyMap(BaseCollection $source, array $associations): array
    {
        $map = [];
        $container = $source->associations();
        foreach ($associations as $assoc) {
            /** @var \Crustum\Mongo\ODM\Association $association */
            $association = $container->get($assoc);
            $map[$assoc] = $association->getProperty();
        }

        return $map;
    }

    /**
     * Injects the results of the eager loader query into the original list of
     * documents.
     *
     * @param array<\Cake\Datasource\EntityInterface> $entities The original list of documents
     * @param \Crustum\Mongo\ODM\Query\SelectQuery $query The eager-loading query
     * @param array<string> $associations The top level associations that were loaded
     * @param \Crustum\Mongo\ODM\BaseCollection $source The collection where the documents came from
     * @return array<\Cake\Datasource\EntityInterface>
     */
    protected function injectResults(
        array $entities,
        SelectQuery $query,
        array $associations,
        BaseCollection $source,
    ): array {
        $injected = [];
        $properties = $this->getPropertyMap($source, $associations);
        $primaryKey = (array)$source->getPrimaryKey();
        $indexBy = static fn(EntityInterface $entity): string => implode(';', $entity->extract($primaryKey));

        $results = [];
        foreach ($query->toArray() as $entity) {
            if ($entity instanceof EntityInterface) {
                $results[$indexBy($entity)] = $entity;
            }
        }

        foreach ($entities as $k => $object) {
            $key = implode(';', $object->extract($primaryKey));
            if (!isset($results[$key])) {
                $injected[$k] = $object;
                continue;
            }

            $loaded = $results[$key];
            foreach ($associations as $assoc) {
                $property = $properties[$assoc];
                $object->set($property, $loaded->get($property), ['useSetters' => false]);
                $object->setDirty($property, false);
            }

            $injected[$k] = $object;
        }

        return $injected;
    }
}

<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM;

use Cake\Datasource\EntityInterface;

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

        $associations = array_keys($contain);
        $associations = array_values(array_filter($associations, is_string(...)));

        $entities = $this->injectResults($entities, $contain, $associations, $source);

        return $returnSingle ? array_shift($entities) : $entities;
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
     * @param array<int|string, mixed> $contain The associations to be loaded
     * @param array<string> $associations The top level associations that were loaded
     * @param \Crustum\Mongo\ODM\BaseCollection $source The collection where the documents came from
     * @return array<\Cake\Datasource\EntityInterface>
     */
    protected function injectResults(
        array $entities,
        array $contain,
        array $associations,
        BaseCollection $source,
    ): array {
        $injected = [];
        $properties = $this->getPropertyMap($source, $associations);
        $primaryKey = (array)$source->getPrimaryKey();
        $indexBy = static fn(EntityInterface $entity): string => implode(';', $entity->extract($primaryKey));

        $primary = current($primaryKey);
        assert(is_string($primary));
        $query = $source
            ->find()
            ->where(fn($exp) => $exp->in($source->aliasField($primary), $this->collectKeys($entities, $source)))
            ->contain($contain);

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

    /**
     * Collects the primary key values from the passed documents.
     *
     * @param array<\Cake\Datasource\EntityInterface> $entities The documents.
     * @param \Crustum\Mongo\ODM\BaseCollection $source The source collection.
     * @return array<int, mixed>
     */
    protected function collectKeys(array $entities, BaseCollection $source): array
    {
        $primaryKey = $source->getPrimaryKey();
        $method = is_string($primaryKey) ? 'get' : 'extract';
        $keys = [];
        foreach ($entities as $entity) {
            $keys[] = $entity->{$method}($primaryKey);
        }

        return $keys;
    }
}

<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Association;

use Closure;
use Crustum\Mongo\ODM\Collection;
use Crustum\Mongo\ODM\Document;
use MongoDB\Model\BSONDocument;

/**
 * Represents a MongoDB DBRef-style embedded value.
 *
 * The current MongoDB extension does not expose a native DBRef class, so refs
 * are represented as BSON documents with `$ref` and `$id` fields.
 */
class DBRef extends Embedded
{
    /**
     * Referenced collection name.
     */
    protected string $collection;

    /**
     * Constructor.
     *
     * @param string $alias Association alias.
     * @param \Crustum\Mongo\ODM\Collection $source Source collection.
     * @param array<string, mixed> $options Association configuration.
     */
    public function __construct(string $alias, Collection $source, array $options = [])
    {
        parent::__construct($alias, $source, $options);
        $this->collection = (string)($options['collection'] ?? $alias);
    }

    /**
     * Sets the referenced collection name.
     *
     * @param string $collection Collection name.
     * @return $this
     */
    public function setCollection(string $collection): static
    {
        $this->collection = $collection;

        return $this;
    }

    /**
     * Gets the referenced collection name.
     *
     * @return string
     */
    public function getCollection(): string
    {
        return $this->collection;
    }

    /**
     * Gets the relationship type.
     *
     * @return string
     */
    public function type(): string
    {
        return self::ONE_TO_ONE;
    }

    /**
     * Builds the DBRef hydration loader.
     *
     * @param array<string, mixed> $options Hydration options.
     * @return \Closure
     */
    public function eagerLoader(array $options): Closure
    {
        return $this->hydrateLoader($options);
    }

    /**
     * Hydrates a DBRef value, optionally resolving it through a connection.
     *
     * @param \MongoDB\Model\BSONDocument|array<string, mixed> $data DBRef fields.
     * @param array<string, mixed> $options Hydration options.
     * @return \Crustum\Mongo\ODM\Document
     */
    public function hydrate(array|BSONDocument $data, array $options = []): Document
    {
        if ($data instanceof BSONDocument) {
            $data = $data->getArrayCopy();
        }

        if (isset($data['$ref'], $data['$id']) && isset($options['connection'])) {
            $raw = $options['connection']->getCollection((string)$data['$ref'])->findOne(['_id' => $data['$id']]);
            $data = $raw === null ? [] : (array)$raw;
        }

        return $this->document($data, $options);
    }

    /**
     * Creates a BSON DBRef-style value.
     *
     * @param \Crustum\Mongo\ODM\Document $document Referenced document.
     * @return \MongoDB\Model\BSONDocument
     */
    public function createRef(Document $document): BSONDocument
    {
        return new BSONDocument([
            '$ref' => $this->collection,
            '$id' => $document->get('_id'),
        ]);
    }

    /**
     * Builds the callable that resolves DBRef values.
     *
     * @param array<string, mixed> $options Hydration options.
     * @return \Closure
     */
    private function hydrateLoader(array $options): callable
    {
        return function (iterable $entities) use ($options): iterable {
            $property = $this->getProperty();
            foreach ($entities as $entity) {
                $value = $entity->get($property);
                if ($value instanceof BSONDocument) {
                    $value = $value->getArrayCopy();
                }

                $entity->set($property, is_array($value) ? $this->hydrate($value, $options) : $value);
                $entity->setDirty($property, false);
            }

            return $entities;
        };
    }

    /**
     * DBRef hydration requires no aggregation stages.
     *
     * @param array<string, mixed> $options Pipeline options.
     * @return array<int, array<string, mixed>>
     */
    public function buildPipeline(array $options = []): array
    {
        return [];
    }
}

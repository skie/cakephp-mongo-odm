<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Association;

use Cake\Datasource\EntityInterface;
use Closure;

/**
 * Embeds one document in the source document.
 *
 * @see cake60/src/ORM/Association/HasOne.php
 */
class EmbedOne extends Embedded
{
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
     * Hydrates the embedded property for each source document.
     *
     * @param array<string, mixed> $options Hydration options.
     * @return \Closure
     */

    /**
     * @inheritDoc
     */
    public function hydrateEmbedded(mixed $raw, EntityInterface $parent, array $options = []): mixed
    {
        $value = $this->normalize($raw);

        return $value === null ? null : $this->embeddedDocument((array)$value, $parent, $options);
    }

    /**
     * @inheritDoc
     */
    public function eagerLoader(array $options): Closure
    {
        return function (iterable $entities) use ($options): iterable {
            $property = $this->getProperty();
            foreach ($entities as $entity) {
                $entity->set($property, $this->hydrateEmbedded($entity->get($property), $entity, $options));
                $entity->setDirty($property, false);
            }

            return $entities;
        };
    }

    /**
     * Embedded values require no aggregation stages.
     *
     * @param array<string, mixed> $options Pipeline options.
     * @return array<int, array<string, mixed>>
     */
    public function buildPipeline(array $options = []): array
    {
        return [];
    }
}

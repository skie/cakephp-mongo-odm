<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Association;

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
    public function eagerLoader(array $options): Closure
    {
        return function (iterable $entities) use ($options): iterable {
            $property = $this->getProperty();
            foreach ($entities as $entity) {
                $value = $this->normalize($entity->get($property));
                $entity->set($property, $value === null ? null : $this->document((array)$value, $options));
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

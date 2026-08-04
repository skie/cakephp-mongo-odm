<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Association;

use Closure;

/**
 * Embeds many documents in an array property of the source document.
 *
 * @see cake60/src/ORM/Association/HasMany.php
 */
class EmbedMany extends Embedded
{
    /**
     * Gets the relationship type.
     *
     * @return string
     */
    public function type(): string
    {
        return self::ONE_TO_MANY;
    }

    /**
     * Hydrates embedded list values for each source document.
     *
     * @param array<string, mixed> $options Hydration options.
     * @return \Closure
     */
    public function eagerLoader(array $options): Closure
    {
        return function (iterable $entities) use ($options): iterable {
            $property = $this->getProperty();
            foreach ($entities as $entity) {
                $values = $this->normalize($entity->get($property));
                $values = is_array($values) ? $values : [];
                $entity->set($property, array_map(function (mixed $value) use ($options): mixed {
                    $value = $this->normalize($value);

                    return is_array($value) ? $this->document($value, $options) : $value;
                }, $values));
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

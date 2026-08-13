<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Association;

use Cake\Datasource\EntityInterface;
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

    /**
     * @inheritDoc
     */
    public function hydrateEmbedded(mixed $raw, EntityInterface $parent, array $options = []): mixed
    {
        $values = $this->normalize($raw);
        $values = is_array($values) ? $values : [];

        return array_map(function (mixed $value) use ($parent, $options): mixed {
            $value = $this->normalize($value);

            return is_array($value) ? $this->embeddedDocument($value, $parent, $options) : $value;
        }, $values);
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
}

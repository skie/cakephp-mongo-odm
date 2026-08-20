<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Association;

use Cake\Datasource\EntityInterface;
use Closure;
use Override;

/**
 * Embeds one document in the source document.
 *
 * @rewritten-from \Cake\ORM\Association\HasOne
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
    #[Override]
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
            foreach ($entities as $document) {
                $document->set($property, $this->hydrateEmbedded($document->get($property), $document, $options));
                $document->setDirty($property, false);
            }

            return $entities;
        };
    }
}

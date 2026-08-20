<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM;

/**
 * Behaviors implementing this interface can participate in document marshaling.
 *
 * This enables behaviors to define how the properties they provide/manage
 * should be marshaled.
 *
 * @ported-from \Cake\ORM\PropertyMarshalInterface
 */
interface PropertyMarshalInterface
{
    /**
     * Build a set of properties that should be included in the marshaling process.
     *
     * @param \Crustum\Mongo\ODM\Marshaller<\Cake\Datasource\EntityInterface> $marshaller The marshaller of the collection the behavior is attached to.
     * @param array<string, callable> $map The property map being built.
     * @param array<string, mixed> $options The options array used in the marshaling call.
     * @return array<string, callable> A map of `[property => callable]` of additional properties to marshal.
     */
    public function buildMarshalMap(Marshaller $marshaller, array $map, array $options): array;
}

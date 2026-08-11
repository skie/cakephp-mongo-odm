<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM;

use ArrayObject;
use Cake\Validation\Validator;
use Crustum\Mongo\Database\Type\TypeFactory;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Contains the logic for converting request data into MongoDB documents.
 *
 * The marshaller validates scalar fields, applies patchability rules, and
 * delegates association values to the target collection marshaller. MongoDB
 * identifiers remain in the canonical `_id` property.
 *
 * @see cake60/src/ORM/Marshaller.php
 * @see src/ODM/Marshaller.php
 */
class Marshaller
{
    /**
     * BaseCollection associated with this marshaller.
     *
     * @var \Crustum\Mongo\ODM\BaseCollection
     */
    private BaseCollection $collection;

    /**
     * Constructor.
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $collection The collection.
     */
    public function __construct(BaseCollection $collection)
    {
        $this->collection = $collection;
    }

    /**
     * Hydrates one document from input data.
     *
     * @param array<string, mixed> $data Data to marshal.
     * @param array<string, mixed> $options Marshalling options.
     * @return \Crustum\Mongo\ODM\Document
     */
    public function one(array $data, array $options = []): Document
    {
        [$data, $options] = $this->prepare($data, $options);
        $entity = $this->newDocument($options);

        $errors = $this->validate($data, $options, true, $entity);
        $properties = $this->marshalProperties($data, $options, $errors, $entity);
        $this->patch($entity, $properties, $options);
        $entity->setErrors($errors);
        $this->dispatchAfterMarshal($entity, $data, $options);

        return $entity;
    }

    /**
     * Hydrates multiple documents from input data.
     *
     * @param array<int, mixed> $data Documents to marshal.
     * @param array<string, mixed> $options Marshalling options.
     * @return array<int, \Crustum\Mongo\ODM\Document>
     */
    public function many(array $data, array $options = []): array
    {
        $entities = [];
        foreach ($data as $record) {
            if (is_array($record)) {
                $entities[] = $this->one($record, $options);
            }
        }

        return $entities;
    }

    /**
     * Merges input data into an existing document.
     *
     * @param \Crustum\Mongo\ODM\Document $entity Document to update.
     * @param array<string, mixed> $data Data to marshal.
     * @param array<string, mixed> $options Marshalling options.
     * @return \Crustum\Mongo\ODM\Document
     */
    public function merge(Document $entity, array $data, array $options = []): Document
    {
        [$data, $options] = $this->prepare($data, $options + ['isMerge' => true]);
        $errors = $this->validate($data, $options, $entity->isNew(), $entity);
        $properties = $this->marshalProperties($data, $options, $errors, $entity);
        $this->patch($entity, $properties, $options);
        $entity->setErrors($errors);
        $this->dispatchAfterMarshal($entity, $data, $options);

        return $entity;
    }

    /**
     * Merges each input row into its matching document.
     *
     * Unmatched rows with identifiers are resolved through the collection;
     * rows without identifiers are marshalled as new documents.
     *
     * @param iterable<mixed> $entities Existing documents.
     * @param array<int, mixed> $data Data rows to merge.
     * @param array<string, mixed> $options Marshalling options.
     * @return array<int, \Crustum\Mongo\ODM\Document>
     */
    public function mergeMany(iterable $entities, array $data, array $options = []): array
    {
        $indexed = [];
        $new = [];
        foreach ($data as $record) {
            if (!is_array($record)) {
                continue;
            }

            $id = $this->idFrom($record);
            if ($id === null) {
                $new[] = $record;
            } else {
                $indexed[$id] = $record;
            }
        }

        $result = [];
        foreach ($entities as $entity) {
            if (!$entity instanceof Document) {
                continue;
            }

            $id = $entity->getId();
            if ($id === null) {
                continue;
            }

            if (!isset($indexed[$id])) {
                continue;
            }

            $result[] = $this->merge($entity, $indexed[$id], $options);
            unset($indexed[$id]);
        }

        foreach (array_keys($indexed) as $id) {
            try {
                $entity = $this->collection->get($id);
            } catch (Throwable) {
                continue;
            }

            if (!$entity instanceof Document) {
                continue;
            }

            $result[] = $this->merge($entity, $indexed[$id], $options);
            unset($indexed[$id]);
        }

        foreach (array_merge(array_values($indexed), $new) as $record) {
            $result[] = $this->one($record, $options);
        }

        return $result;
    }

    /**
     * Creates an empty document using the collection's configured entity class.
     *
     * @param array<string, mixed> $options Marshaller options.
     * @return \Crustum\Mongo\ODM\Document
     * @throws \InvalidArgumentException If the configured entity class is invalid.
     */
    private function newDocument(array $options): Document
    {
        $class = $this->collection->getDocumentClass();
        $entity = new $class();
        $entity->setSource($this->collection->getRegistryAlias());

        if (array_key_exists('markNew', $options) && $options['markNew'] !== null) {
            $entity->setNew((bool)$options['markNew']);
        }

        return $entity;
    }

    /**
     * Prepares data and options and dispatches the before-marshal event.
     *
     * @param array<string, mixed> $data The input data.
     * @param array<string, mixed> $options The marshalling options.
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function prepare(array $data, array $options): array
    {
        $options += ['validate' => true, 'associated' => [], 'isMerge' => false];
        $dataObject = new ArrayObject($data);
        $optionsObject = new ArrayObject($options);
        $this->dispatch('Collection.beforeMarshal', $dataObject, $optionsObject);

        return [(array)$dataObject, (array)$optionsObject];
    }

    /**
     * Validates the input data and returns validation errors.
     *
     * @param array<string, mixed> $data The input data.
     * @param array<string, mixed> $options The marshalling options.
     * @param bool $isNew Whether the document is new.
     * @param \Crustum\Mongo\ODM\Document $entity The document being marshalled.
     * @return array<string, mixed>
     */
    private function validate(array $data, array $options, bool $isNew, Document $entity): array
    {
        $validator = $options['validate'] ?? true;
        if ($validator === false) {
            return [];
        }

        if ($validator === true) {
            $validator = $this->collection->getValidator('default');
        } elseif (is_string($validator)) {
            $validator = $this->collection->getValidator($validator);
        }

        if (!$validator instanceof Validator) {
            throw new RuntimeException('validate must be a boolean, a string or a validator object.');
        }

        return $validator->validate($data, $isNew, ['entity' => $entity]);
    }

    /**
     * Builds a map of request field => marshalling callback.
     *
     * Schema fields are mapped to their type `marshal()` callbacks; associations
     * listed in `associated` are mapped (by both alias and property name) to a
     * callback that marshals or merges the value through the target marshaller.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     * @return array<string, callable>
     */
    private function buildPropertyMap(array $data, array $options): array
    {
        $map = [];

        $types = $this->collection->getSchema()->typeMap();

        foreach (array_keys($data) as $prop) {
            $prop = (string)$prop;
            if (isset($types[$prop])) {
                $type = $types[$prop];
                $map[$prop] = static fn(mixed $value): mixed => TypeFactory::build($type)->marshal($value);
            }
        }

        $associated = (array)($options['associated'] ?? []);
        foreach ($associated as $key => $nested) {
            if (is_int($key) && is_scalar($nested)) {
                $key = $nested;
                $nested = [];
            }

            $alias = (string)$key;
            if (str_starts_with($alias, '_')) {
                continue;
            }

            $association = $this->resolveAssociation($alias);
            if (!$association instanceof Association) {
                throw new InvalidArgumentException(sprintf(
                    'Cannot marshal data for `%s` association. It is not associated.',
                    $alias,
                ));
            }

            $nestedOptions = is_array($nested) ? $nested : [];
            $property = $association->getProperty();

            if (($options['isMerge'] ?? false)) {
                $map[$alias] = $map[$property] = (fn(mixed $value, Document $entity): mixed => $this->mergeAssociation($entity, $association, $value, $nestedOptions + ['associated' => []]));

                continue;
            }

            $map[$alias] = $map[$property] = fn(mixed $value): mixed => $this->marshalAssociation(
                $association,
                $value,
                $nestedOptions + ['associated' => []],
            );
        }

        return $map;
    }

    /**
     * Marshals the input data into patchable properties.
     *
     * @param array<string, mixed> $data The input data.
     * @param array<string, mixed> $options The marshalling options.
     * @param array<string, mixed> $errors Validation errors.
     * @param \Crustum\Mongo\ODM\Document $entity The document being marshalled.
     * @return array<string, mixed>
     */
    private function marshalProperties(array $data, array $options, array $errors, Document $entity): array
    {
        $map = $this->buildPropertyMap($data, $options);
        $properties = [];
        foreach ($data as $field => $value) {
            if (isset($errors[$field]) && $errors[$field] !== []) {
                continue;
            }

            $callback = $map[$field] ?? null;
            $properties[$field] = $callback === null
                ? $value
                : $callback($value, $entity);
        }

        $fields = $options['fieldList'] ?? $options['fields'] ?? null;
        if ($fields === null) {
            return $this->filterPatchable($properties, $options);
        }

        return $this->filterPatchable(
            array_intersect_key($properties, array_flip((array)$fields)),
            $options,
        );
    }

    /**
     * Filters properties against the configured patchable fields.
     *
     * @param array<string, mixed> $properties The marshalled properties.
     * @param array<string, mixed> $options The marshalling options.
     * @return array<string, mixed>
     */
    private function filterPatchable(array $properties, array $options): array
    {
        if (!isset($options['patchableFields'])) {
            return $properties;
        }

        $patchable = (array)$options['patchableFields'];
        $default = (bool)($patchable['*'] ?? true);

        return array_filter(
            $properties,
            static fn(mixed $value, string|int $field): bool => (bool)($patchable[$field] ?? $default),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * Resolves an association by alias from the collection.
     *
     * @param string $name The association alias.
     * @return \Crustum\Mongo\ODM\Association|null The association or null when not registered.
     */
    private function resolveAssociation(string $name): ?Association
    {
        return $this->collection->getAssociation($name);
    }

    /**
     * Marshals an association value through the target marshaller.
     *
     * @param \Crustum\Mongo\ODM\Association $association The association.
     * @param mixed $value The incoming value.
     * @param array<string, mixed> $options Marshaller options.
     * @return mixed
     */
    private function marshalAssociation(Association $association, mixed $value, array $options): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $type = $association->type();
        $many = $type === 'oneToMany' || $type === 'manyToMany';
        if ($many) {
            $hasIds = array_key_exists('_ids', $value) && is_array($value['_ids']);
            $onlyIds = !empty($options['onlyIds']);

            if ($hasIds) {
                return $this->loadAssociatedByIds($association, $value['_ids']);
            }

            if ($onlyIds) {
                return [];
            }
        }

        $alias = $association->getAlias();
        $nested = is_array($options['associated'][$alias] ?? null)
            ? $options['associated'][$alias]
            : [];
        $target = $association->getTarget();
        $marshaller = $target->marshaller();

        if ($many) {
            return $marshaller->many($value, $nested);
        }

        return $marshaller->one($value, $nested);
    }

    /**
     * Merges associated input into an existing document's association property.
     *
     * Existing associated documents are merged by `_id`; missing ones are
     * marshalled as new documents.
     *
     * @param \Crustum\Mongo\ODM\Document $entity The source document.
     * @param \Crustum\Mongo\ODM\Association $association The association.
     * @param mixed $value The incoming value.
     * @param array<string, mixed> $options Marshaller options.
     * @return mixed
     */
    private function mergeAssociation(Document $entity, Association $association, mixed $value, array $options): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $type = $association->type();
        $property = $association->getProperty();
        $existing = $entity->get($property);
        $many = $type === 'oneToMany' || $type === 'manyToMany';
        $target = $association->getTarget();
        $marshaller = $target->marshaller();

        if ($many) {
            $hasIds = array_key_exists('_ids', $value) && is_array($value['_ids']);
            if ($hasIds) {
                return $this->loadAssociatedByIds($association, $value['_ids']);
            }

            return $marshaller->mergeMany(is_array($existing) ? $existing : [], $value, $options);
        }

        if ($existing instanceof Document) {
            return $marshaller->merge($existing, $value, $options);
        }

        return $marshaller->one($value, $options);
    }

    /**
     * Loads associated documents for the given referenced identifiers.
     *
     * When the target cannot resolve the identifiers, the raw ids are kept.
     *
     * @param \Crustum\Mongo\ODM\Association $association The association.
     * @param array<int, mixed> $ids Referenced identifiers.
     * @return array<int, mixed>
     */
    private function loadAssociatedByIds(Association $association, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        try {
            $query = $association->getTarget()->find();

            return array_values($query->where(['_id IN' => $ids])->all()->toArray());
        } catch (Throwable) {
            return $ids;
        }
    }

    /**
     * Dispatches the `Collection.afterMarshal` event.
     *
     * Payload order matters: `EventManager::_callListener()` passes data
     * positionally (`$listener($event, ...array_values($data))`), so the
     * `afterMarshal(EventInterface, EntityInterface, ArrayObject, ArrayObject)`
     * listener signature requires the entity first (matching cake's
     * `compact('entity', 'data', 'options')`).
     *
     * @param \Crustum\Mongo\ODM\Document $entity The marshalled document.
     * @param array<string, mixed> $data The input data.
     * @param array<string, mixed> $options Marshaller options.
     * @return void
     */
    private function dispatchAfterMarshal(Document $entity, array $data, array $options = []): void
    {
        $this->collection->dispatchEvent('Collection.afterMarshal', [
            'entity' => $entity,
            'data' => new ArrayObject($data),
            'options' => new ArrayObject($options),
        ]);
    }

    /**
     * Patches the marshalled properties onto the document.
     *
     * @param \Crustum\Mongo\ODM\Document $entity The document to patch.
     * @param array<string, mixed> $properties The properties to assign.
     * @param array<string, mixed> $options The marshalling options.
     * @return void
     */
    private function patch(Document $entity, array $properties, array $options): void
    {
        $entity->patch($properties, ['guard' => true, 'asOriginal' => !($options['isMerge'] ?? false)]);
    }

    /**
     * Extracts the identifier from a data row.
     *
     * @param array<string, mixed> $data The data row.
     * @return string|null
     */
    private function idFrom(array $data): ?string
    {
        $id = $data['_id'] ?? $data['id'] ?? null;

        return $id === null ? null : (string)$id;
    }

    /**
     * Dispatches a model event through the collection.
     *
     * @param string $event The event name.
     * @param mixed $data The event data.
     * @param mixed $options The marshalling options.
     * @param \Crustum\Mongo\ODM\Document|null $entity The marshalled document.
     * @return void
     */
    private function dispatch(string $event, mixed $data, mixed $options, ?Document $entity = null): void
    {
        $payload = ['data' => $data, 'options' => $options];
        if ($entity instanceof Document) {
            $payload['entity'] = $entity;
        }

        $this->collection->dispatchEvent($event, $payload);
    }
}

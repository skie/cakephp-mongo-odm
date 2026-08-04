<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM;

use ArrayObject;
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
final class Marshaller
{
    /**
     * Collection associated with this marshaller.
     *
     * @var object
     */
    private object $collection;

    /**
     * Constructor.
     *
     * @param object $collection C1 collection contract implementation.
     */
    public function __construct(object $collection)
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
        $this->dispatch('Model.afterMarshal', $data, $options, $entity);

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
        $this->dispatch('Model.afterMarshal', $data, $options, $entity);

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
            if ($id === null || !isset($indexed[$id])) {
                continue;
            }
            $result[] = $this->merge($entity, $indexed[$id], $options);
            unset($indexed[$id]);
        }

        foreach (array_keys($indexed) as $id) {
            if (!is_callable([$this->collection, 'get'])) {
                break;
            }

            try {
                $entity = $this->collectionCall('get', $id);
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
        $class = $this->collectionCall('getEntityClass');
        $entity = new $class();
        if (!$entity instanceof Document) {
            throw new InvalidArgumentException('Collection entity class must extend Document.');
        }
        if (method_exists($this->collection, 'getRegistryAlias')) {
            $entity->setSource($this->collection->getRegistryAlias());
        }

        return $entity;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function prepare(array $data, array $options): array
    {
        $options += ['validate' => true, 'associated' => []];
        $dataObject = new ArrayObject($data);
        $optionsObject = new ArrayObject($options);
        $this->dispatch('Model.beforeMarshal', $dataObject, $optionsObject);

        return [(array)$dataObject, (array)$optionsObject];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function validate(array $data, array $options, bool $isNew, Document $entity): array
    {
        $validator = $options['validate'] ?? true;
        if ($validator === false) {
            return [];
        }
        if ($validator === true) {
            $validator = $this->collectionCall('getValidator', 'default');
        } elseif (is_string($validator)) {
            $validator = $this->collectionCall('getValidator', $validator);
        }
        if (!is_object($validator) || !method_exists($validator, 'validate')) {
            throw new RuntimeException('validate must be a boolean, a string or a validator object.');
        }

        return $validator->validate($data, $isNew, ['entity' => $entity]);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     * @param array<string, mixed> $errors
     * @return array<string, mixed>
     */
    private function marshalProperties(array $data, array $options, array $errors, Document $entity): array
    {
        $properties = [];
        foreach ($data as $field => $value) {
            if (isset($errors[$field]) && $errors[$field] !== []) {
                continue;
            }
            if ($field === 'id' && !array_key_exists('_id', $data)) {
                $field = '_id';
            }
            $association = $this->association((string)$field, $options);
            $properties[$field] = $association === null
                ? $value
                : $this->marshalAssociation($association, $value, $options, $entity);
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
     * @param array<string, mixed> $properties
     * @param array<string, mixed> $options
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

    /** @param array<string, mixed> $options */
    private function association(string $field, array $options): ?object
    {
        $included = $options['associated'] ?? [];
        $name = array_key_exists($field, (array)$included) ? $field : null;
        if ($name === null && in_array($field, (array)$included, true)) {
            $name = $field;
        }
        if ($name === null) {
            return null;
        }
        if (method_exists($this->collection, 'getAssociation')) {
            $association = $this->collectionCall('getAssociation', $name);

            return is_object($association) ? $association : null;
        }

        return null;
    }

    /** @param array<string, mixed> $options */
    private function marshalAssociation(object $association, mixed $value, array $options, Document $entity): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $alias = $this->associationCall($association, 'getAlias');
        $nested = is_array($options['associated'][$alias] ?? null)
            ? $options['associated'][$alias]
            : [];
        $target = method_exists($association, 'getTarget')
            ? $this->associationCall($association, 'getTarget')
            : null;
        $type = $this->associationCall($association, 'type');
        if (array_key_exists('_ids', $value) && is_array($value['_ids'])) {
            return $value['_ids'];
        }
        if (is_object($target) && is_callable([$target, 'marshaller'])) {
            $marshaller = call_user_func([$target, 'marshaller']);
            $many = array_is_list($value) || $type === 'oneToMany';
            if (is_object($marshaller) && is_callable([$marshaller, $many ? 'many' : 'one'])) {
                return call_user_func([$marshaller, $many ? 'many' : 'one'], $value, $nested);
            }
        }
        $class = method_exists($association, 'getEntityClass')
            ? $this->associationCall($association, 'getEntityClass')
            : null;
        if (is_string($class) && class_exists($class)) {
            if (array_is_list($value)) {
                return array_map(static function (array $item) use ($class): Document {
                    $document = new $class($item);
                    if (!$document instanceof Document) {
                        throw new InvalidArgumentException('Association entity class must extend Document.');
                    }

                    return $document;
                }, $value);
            }
            $document = new $class($value);
            if (!$document instanceof Document) {
                throw new InvalidArgumentException('Association entity class must extend Document.');
            }

            return $document;
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $properties
     * @param array<string, mixed> $options
     */
    private function patch(Document $entity, array $properties, array $options): void
    {
        $entity->patch($properties, ['guard' => true, 'asOriginal' => !($options['isMerge'] ?? false)]);
    }

    /** @param array<string, mixed> $data */
    private function idFrom(array $data): ?string
    {
        $id = $data['_id'] ?? $data['id'] ?? null;

        return $id === null ? null : (string)$id;
    }

    /**
     * Dispatch a model event when the collection provides the C1 dispatcher.
     */
    private function dispatch(string $event, mixed $data, mixed $options, ?Document $entity = null): void
    {
        if (!method_exists($this->collection, 'dispatchEvent')) {
            return;
        }
        $payload = ['data' => $data, 'options' => $options];
        if ($entity !== null) {
            $payload['entity'] = $entity;
        }
        call_user_func([$this->collection, 'dispatchEvent'], $event, $payload);
    }

    /**
     * Invoke a C1 collection method without importing the phase-2 collection.
     */
    private function collectionCall(string $method, mixed ...$arguments): mixed
    {
        if (!is_callable([$this->collection, $method])) {
            throw new RuntimeException(sprintf('Collection does not implement `%s()`.', $method));
        }

        return call_user_func([$this->collection, $method], ...$arguments);
    }

    /**
     * Invoke a C5 association method without importing the phase-1 association.
     */
    private function associationCall(object $association, string $method): mixed
    {
        if (!is_callable([$association, $method])) {
            throw new RuntimeException(sprintf('Association does not implement `%s()`.', $method));
        }

        return call_user_func([$association, $method]);
    }
}

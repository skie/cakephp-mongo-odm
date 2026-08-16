<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM;

use ArrayObject;
use Cake\Datasource\InvalidPropertyInterface;
use Cake\Validation\Validator;
use Crustum\Mongo\Database\Type\TypeFactory;
use Crustum\Mongo\ODM\Association\BelongsToMany;
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
 * @template TDocument of \Cake\Datasource\EntityInterface
 * @see cake60/src/ORM/Marshaller.php
 * @see src/ODM/Marshaller.php
 */
class Marshaller
{
    use AssociationsNormalizerTrait;

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
        $document = $this->newDocument($options);

        $errors = $this->validate($data, $options, true, $document);
        $properties = $this->marshalProperties($data, $options, $errors, $document);
        $this->patch($document, $properties, $options);
        $document->setErrors($errors);
        $this->dispatchAfterMarshal($document, $data, $options);

        return $document;
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
     * @param \Crustum\Mongo\ODM\Document $document Document to update.
     * @param array<string, mixed> $data Data to marshal.
     * @param array<string, mixed> $options Marshalling options.
     * @return \Crustum\Mongo\ODM\Document
     */
    public function merge(Document $document, array $data, array $options = []): Document
    {
        [$data, $options] = $this->prepare($data, $options + ['isMerge' => true]);
        $errors = $this->validate($data, $options, $document->isNew(), $document);
        $properties = $this->marshalProperties($data, $options, $errors, $document);
        $this->patch($document, $properties, $options);
        $document->setErrors($errors);
        $this->dispatchAfterMarshal($document, $data, $options);

        return $document;
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
        foreach ($entities as $document) {
            if (!$document instanceof Document) {
                continue;
            }

            $id = $document->getId();
            if ($id === null) {
                continue;
            }

            if (!isset($indexed[$id])) {
                continue;
            }

            $result[] = $this->merge($document, $indexed[$id], $options);
            unset($indexed[$id]);
        }

        foreach (array_keys($indexed) as $id) {
            try {
                $document = $this->collection->get($id);
            } catch (Throwable) {
                continue;
            }

            if (!$document instanceof Document) {
                continue;
            }

            $result[] = $this->merge($document, $indexed[$id], $options);
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
        $document = new $class();
        assert($document instanceof Document);
        $document->setSource($this->collection->getRegistryAlias());

        if (array_key_exists('markNew', $options) && $options['markNew'] !== null) {
            $document->setNew((bool)$options['markNew']);
        }

        return $document;
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
     * @param \Crustum\Mongo\ODM\Document $document The document being marshalled.
     * @return array<string, mixed>
     */
    private function validate(array $data, array $options, bool $isNew, Document $document): array
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

        return $validator->validate($data, $isNew, ['document' => $document]);
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
        $associated = $this->normalizeAssociations($associated);
        foreach ($associated as $key => $nested) {
            $alias = (string)$key;
            if (str_starts_with($alias, '_')) {
                continue;
            }

            $association = $this->resolveAssociation($alias);
            if (!$association instanceof Association) {
                throw new InvalidArgumentException(sprintf(
                    'Cannot marshal data for `%s` association. It is not associated with `%s`.',
                    $alias,
                    $this->collection->getAlias(),
                ));
            }

            $nestedOptions = is_array($nested) ? $nested : [];
            $property = $association->getProperty();

            if (($options['isMerge'] ?? false)) {
                $map[$alias] = $map[$property] = (fn(mixed $value, Document $document): mixed => $this->mergeAssociation($document, $association, $value, $nestedOptions + ['associated' => []]));

                continue;
            }

            $map[$alias] = $map[$property] = fn(mixed $value): mixed => $this->marshalAssociation(
                $association,
                $value,
                $nestedOptions + ['associated' => []],
            );
        }

        foreach ($this->collection->behaviors()->loaded() as $name) {
            $behavior = $this->collection->behaviors()->get($name);
            if ($behavior instanceof PropertyMarshalInterface) {
                $map += $behavior->buildMarshalMap($this, $map, $options);
            }
        }

        return $map;
    }

    /**
     * Marshals the input data into patchable properties.
     *
     * @param array<string, mixed> $data The input data.
     * @param array<string, mixed> $options The marshalling options.
     * @param array<string, mixed> $errors Validation errors.
     * @param \Crustum\Mongo\ODM\Document $document The document being marshalled.
     * @return array<string, mixed>
     */
    private function marshalProperties(array $data, array $options, array $errors, Document $document): array
    {
        $map = $this->buildPropertyMap($data, $options);
        $properties = [];
        foreach ($data as $field => $value) {
            if (isset($errors[$field]) && $errors[$field] !== []) {
                if ($document instanceof InvalidPropertyInterface) {
                    $document->setInvalidField($field, $value);
                }

                continue;
            }

            $callback = $map[$field] ?? null;
            $properties[$field] = $callback === null
                ? $value
                : $callback($value, $document);
        }

        $fields = $options['fieldList'] ?? $options['fields'] ?? null;
        if ($fields === null) {
            return $properties;
        }

        return array_intersect_key($properties, array_flip((array)$fields));
    }

    /**
     * Resolves an association by alias from the collection.
     *
     * @param string $name The association alias.
     * @return \Crustum\Mongo\ODM\Association|null The association or null when not registered.
     */
    private function resolveAssociation(string $name): ?Association
    {
        if (!$this->collection->hasAssociation($name)) {
            return null;
        }

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

        $target = $association->getTarget();
        $marshaller = $target->marshaller();

        if ($many) {
            if ($type === 'manyToMany') {
                return $this->belongsToMany($association, $value, $options);
            }

            return $marshaller->many($value, $options);
        }

        return $marshaller->one($value, $options);
    }

    /**
     * Marshals BelongsToMany input, resolving existing documents by primary key
     * and attaching per-link junction data under the association's junction
     * property (`_joinData`).
     *
     * Mirrors cake60 `Marshaller::belongsToMany()` for the ODM in-document
     * junction shape.
     *
     * @param \Crustum\Mongo\ODM\Association $association The BelongsToMany association.
     * @param array<int|string, mixed> $data The incoming rows.
     * @param array<string, mixed> $options Marshaller options.
     * @return array<int, \Crustum\Mongo\ODM\Document>
     */
    private function belongsToMany(Association $association, array $data, array $options): array
    {
        $associated = (array)($options['associated'] ?? []);
        $forceNew = (bool)($options['forceNew'] ?? false);

        $data = array_values($data);
        $target = $association->getTarget();
        $primaryKey = '_id';
        $records = [];
        $conditions = [];

        foreach ($data as $i => $row) {
            if (!is_array($row)) {
                continue;
            }

            if (isset($row[$primaryKey]) && $row[$primaryKey] !== '') {
                $conditions[][$primaryKey] = $row[$primaryKey];
                if ($forceNew) {
                    $records[$i] = $this->one($row, $options);
                }
            } else {
                $records[$i] = $this->one($row, $options);
            }
        }

        if ($conditions !== []) {
            $existing = [];
            foreach ($target->find()->where(['OR' => $conditions])->all() as $document) {
                $existing[(string)$document->get($primaryKey)] = $document;
            }

            foreach ($data as $i => $row) {
                if (!isset($row[$primaryKey]) || $row[$primaryKey] === '') {
                    continue;
                }

                $key = (string)$row[$primaryKey];
                if (isset($existing[$key])) {
                    $records[$i] = $this->merge($existing[$key], $row, $options);
                }
            }
        }

        if (!$association instanceof BelongsToMany) {
            return array_values($records);
        }

        $junctionProperty = $association->getJunctionProperty();
        $jointMarshaller = $association->junction()->marshaller();
        $nested = isset($associated[$junctionProperty]) && is_array($associated[$junctionProperty])
            ? $associated[$junctionProperty]
            : [];

        foreach ($records as $i => $record) {
            if (isset($data[$i][$junctionProperty]) && is_array($data[$i][$junctionProperty])) {
                $joinData = $jointMarshaller->one((array)$data[$i][$junctionProperty], $nested);
                $record->set($junctionProperty, $joinData);
            }
        }

        return array_values($records);
    }

    /**
     * Merges associated input into an existing document's association property.
     *
     * Existing associated documents are merged by `_id`; missing ones are
     * marshalled as new documents.
     *
     * @param \Crustum\Mongo\ODM\Document $document The source document.
     * @param \Crustum\Mongo\ODM\Association $association The association.
     * @param mixed $value The incoming value.
     * @param array<string, mixed> $options Marshaller options.
     * @return mixed
     */
    private function mergeAssociation(Document $document, Association $association, mixed $value, array $options): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $type = $association->type();
        $property = $association->getProperty();
        $existing = $document->get($property);
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
     * @param \Crustum\Mongo\ODM\Document $document The marshalled document.
     * @param array<string, mixed> $data The input data.
     * @param array<string, mixed> $options Marshaller options.
     * @return void
     */
    private function dispatchAfterMarshal(Document $document, array $data, array $options = []): void
    {
        $this->collection->dispatchEvent('Collection.afterMarshal', [
            'document' => $document,
            'data' => new ArrayObject($data),
            'options' => new ArrayObject($options),
        ]);
    }

    /**
     * Patches the marshalled properties onto the document.
     *
     * @param \Crustum\Mongo\ODM\Document $document The document to patch.
     * @param array<string, mixed> $properties The properties to assign.
     * @param array<string, mixed> $options The marshalling options.
     * @return void
     */
    private function patch(Document $document, array $properties, array $options): void
    {
        foreach ((array)($options['patchableFields'] ?? []) as $field => $patchable) {
            $document->setPatchable((string)$field, (bool)$patchable);
        }

        $document->patch($properties, ['guard' => true, 'asOriginal' => !($options['isMerge'] ?? false)]);
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
     * @param \Crustum\Mongo\ODM\Document|null $document The marshalled document.
     * @return void
     */
    private function dispatch(string $event, mixed $data, mixed $options, ?Document $document = null): void
    {
        $payload = ['data' => $data, 'options' => $options];
        if ($document instanceof Document) {
            $payload['entity'] = $document;
        }

        $this->collection->dispatchEvent($event, $payload);
    }
}

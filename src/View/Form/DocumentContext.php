<?php
declare(strict_types=1);

namespace Crustum\Mongo\View\Form;

use Cake\Collection\Collection;
use Cake\Datasource\FactoryLocator;
use Cake\Http\ServerRequest;
use Cake\Utility\Hash;
use Cake\Utility\Inflector;
use Cake\Validation\Validator;
use Cake\View\Form\ContextInterface;
use Crustum\Mongo\ODM\BaseCollection as MongoCollection;
use Crustum\Mongo\ODM\Document;
use RuntimeException;
use Traversable;
use function Cake\Core\namespaceSplit;

/**
 * Provides a context provider for MongoDB documents.
 *
 * Ported from `Cake\ElasticSearch\View\Form\DocumentContext`, mapping the
 * ODM `Collection` where the reference used `Index`.
 *
 * @see cake60/src/View/Form/EntityContext.php
 * @see elastic-search/src/View/Form/DocumentContext.php
 */
class DocumentContext implements ContextInterface
{
    /**
     * The request object.
     *
     * @var \Cake\Http\ServerRequest
     */
    protected ServerRequest $request;

    /**
     * The context data.
     *
     * @var array<string, mixed>
     */
    protected array $context;

    /**
     * The name of the top level collection object.
     *
     * @var string
     */
    protected string $rootName;

    /**
     * Whether the entity is a collection.
     *
     * @var bool
     */
    protected bool $isCollection = false;

    /**
     * Constructor.
     *
     * @param \Cake\Http\ServerRequest $request The request object.
     * @param array<string, mixed> $context Context info.
     */
    public function __construct(ServerRequest $request, array $context)
    {
        $this->request = $request;
        $context += [
            'entity' => null,
            'collection' => null,
            'validator' => 'default',
        ];
        $this->context = $context;
        $this->prepare();
    }

    /**
     * Resolves the collection from the context.
     *
     * When no collection is given, it is derived from the entity's source or
     * class name so naming-convention inference keeps working for Documents.
     *
     * @return void
     * @throws \RuntimeException When a collection cannot be located/inferred.
     */
    protected function prepare(): void
    {
        $collection = $this->context['collection'];
        $entity = $this->context['entity'];
        if (empty($collection)) {
            if (is_array($entity) || $entity instanceof Traversable) {
                $entity = (new Collection($entity))->first();
            }

            $isDocument = $entity instanceof Document;

            if ($isDocument) {
                $collection = $entity->getSource();
            }

            if (!$collection && $isDocument) {
                [, $entityClass] = namespaceSplit($entity::class);
                $collection = Inflector::pluralize($entityClass);
            }
        }

        if (is_string($collection)) {
            $collection = FactoryLocator::get('Collection')->get($collection);
        }

        if (!($collection instanceof MongoCollection)) {
            throw new RuntimeException('Unable to find collection class for current document.');
        }

        $this->isCollection = is_array($entity) || $entity instanceof Traversable;
        $this->rootName = $collection->getAlias();
        $this->context['collection'] = $collection;
    }

    /**
     * @inheritDoc
     */
    public function getPrimaryKey(): array
    {
        return (array)$this->context['collection']->getPrimaryKey();
    }

    /**
     * @inheritDoc
     */
    public function isPrimaryKey(string $field): bool
    {
        $parts = explode('.', $field);

        return in_array(array_pop($parts), (array)$this->context['collection']->getPrimaryKey(), true);
    }

    /**
     * @inheritDoc
     */
    public function isCreate(): bool
    {
        $entity = $this->context['entity'];
        if (is_array($entity) || $entity instanceof Traversable) {
            $entity = (new Collection($entity))->first();
        }

        if ($entity instanceof Document) {
            return $entity->isNew();
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function val(string $field, array $options = []): mixed
    {
        $options += [
            'default' => null,
            'schemaDefault' => true,
        ];

        $val = $this->request->getData($field);
        if ($val !== null) {
            return $val;
        }

        if (empty($this->context['entity'])) {
            return $options['default'];
        }

        $parts = explode('.', $field);
        $entity = $this->entity($parts);

        if ($entity instanceof Document) {
            $part = end($parts);
            $val = $entity->get((string)$part);
            if ($val !== null) {
                return $val;
            }
            if ($options['schemaDefault'] && $entity->isNew()) {
                return $this->schemaDefault($parts);
            }

            return $options['default'];
        }

        if (is_array($entity) || $entity instanceof Traversable) {
            $part = (string)array_pop($parts);
            foreach ($entity as $key => $value) {
                if ((string)$key === $part) {
                    return $value;
                }
            }

            return $options['default'];
        }

        if ($this->context['entity'] instanceof Document) {
            return Hash::get($this->context['entity'], $field) ?? $options['default'];
        }

        return $options['default'];
    }

    /**
     * Gets a schema default for a field path.
     *
     * @param array<string> $parts The dotted path parts.
     * @return mixed
     */
    protected function schemaDefault(array $parts): mixed
    {
        $field = (string)end($parts);
        $schema = $this->context['collection']->getSchema();
        if ($schema === null) {
            return null;
        }
        $defaults = $schema->defaultValues();

        return $defaults[$field] ?? null;
    }

    /**
     * Gets the entity closest to a path.
     *
     * @param array<string> $path The dotted path parts.
     * @return object|array<string, mixed>|false The entity, array, or false when not found.
     * @throws \RuntimeException When a property cannot be fetched.
     */
    protected function entity(array $path): object|array|false
    {
        $oneElement = count($path) === 1;
        if ($oneElement && $this->isCollection) {
            return false;
        }

        $entity = $this->context['entity'];
        if ($oneElement) {
            return $entity;
        }

        if ($path[0] === $this->rootName) {
            $path = array_slice($path, 1);
        }

        $len = count($path);
        $last = $len - 1;
        for ($i = 0; $i < $len; $i++) {
            $prop = $path[$i];
            $next = $this->getProp($entity, $prop);
            $isLast = ($i === $last);

            if (!$isLast && $next === null && $prop !== '_ids') {
                return false;
            }

            $isTraversable = (
                is_array($next) ||
                $next instanceof Traversable ||
                $next instanceof Document
            );

            if ($isLast || !$isTraversable) {
                return $entity;
            }

            $entity = $next;
        }

        throw new RuntimeException(sprintf('Unable to fetch property "%s"', implode('.', $path)));
    }

    /**
     * Reads a property value or traverses arrays/iterators.
     *
     * @param mixed $target The entity/array/collection to fetch $field from.
     * @param string $field The next field to fetch.
     * @return mixed
     */
    protected function getProp(mixed $target, string $field): mixed
    {
        if (is_array($target) && isset($target[$field])) {
            return $target[$field];
        }

        if ($target instanceof Document) {
            return $target->get($field);
        }

        if ($target instanceof Traversable) {
            foreach ($target as $i => $val) {
                if ((string)$i === $field) {
                    return $val;
                }
            }

            return false;
        }

        return null;
    }

    /**
     * @inheritDoc
     */
    public function isRequired(string $field): ?bool
    {
        $parts = explode('.', $field);
        $entity = $this->entity($parts);

        if (!$entity) {
            return null;
        }

        $isNew = true;
        if ($entity instanceof Document) {
            $isNew = $entity->isNew();
        }

        $validator = $this->getValidator();
        $field = (string)array_pop($parts);
        if (!$validator->hasField($field)) {
            return null;
        }
        if (is_callable($validator->field($field)->isEmptyAllowed())) {
            return null;
        }
        if ($this->type($field) !== 'boolean') {
            return !$validator->isEmptyAllowed($field, $isNew);
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function getRequiredMessage(string $field): ?string
    {
        $parts = explode('.', $field);

        $validator = $this->getValidator();
        $fieldName = (string)array_pop($parts);
        if (!$validator->hasField($fieldName)) {
            return null;
        }

        $ruleset = $validator->field($fieldName);
        if ($ruleset->isEmptyAllowed()) {
            return null;
        }

        return $validator->getNotEmptyMessage($fieldName);
    }

    /**
     * @inheritDoc
     */
    public function getMaxLength(string $field): ?int
    {
        $parts = explode('.', $field);
        $validator = $this->getValidator();
        $fieldName = (string)array_pop($parts);

        if ($validator->hasField($fieldName)) {
            foreach ($validator->field($fieldName)->rules() as $rule) {
                if ($rule->get('rule') === 'maxLength' && isset($rule->get('pass')[0])) {
                    return $rule->get('pass')[0];
                }
            }
        }

        $attributes = $this->attributes($field);
        if (empty($attributes['length'])) {
            return null;
        }

        return (int)$attributes['length'];
    }

    /**
     * @inheritDoc
     */
    public function fieldNames(): array
    {
        $schema = $this->context['collection']->getSchema();
        if ($schema === null) {
            return [];
        }

        return $schema->columns();
    }

    /**
     * @inheritDoc
     */
    public function type(string $field): ?string
    {
        $schema = $this->context['collection']->getSchema();
        if ($schema === null) {
            return null;
        }
        $parts = explode('.', $field);

        return $schema->baseColumnType((string)array_pop($parts));
    }

    /**
     * Gets the additional attributes for a field.
     *
     * @param string $field A dot separated path to get additional data on.
     * @return array<string, mixed> An array of data describing the additional attributes on a field.
     */
    public function attributes(string $field): array
    {
        $schema = $this->context['collection']->getSchema();
        if ($schema === null) {
            return [];
        }
        $parts = explode('.', $field);
        $column = $schema->getColumn((string)array_pop($parts));
        if ($column === null) {
            return [];
        }

        return array_intersect_key($column, array_flip(static::VALID_ATTRIBUTES));
    }

    /**
     * Gets the validator for the current collection.
     *
     * @return \Cake\Validation\Validator
     */
    protected function getValidator(): Validator
    {
        /** @var \Crustum\Mongo\ODM\BaseCollection $collection */
        $collection = $this->context['collection'];

        return $collection->getValidator($this->context['validator']);
    }

    /**
     * @inheritDoc
     */
    public function hasError(string $field): bool
    {
        return $this->error($field) !== [];
    }

    /**
     * Gets the errors for a given field.
     *
     * @param string $field A dot separated path to check errors on.
     * @return array<string, mixed> An array of errors, empty when the context has no errors.
     */
    public function error(string $field): array
    {
        $parts = explode('.', $field);
        $entity = $this->entity($parts);
        $entityErrors = [];
        $errors = [];

        if ($this->context['entity'] instanceof Document) {
            $entityErrors = $this->context['entity']->getErrors();
        }

        $tailField = (string)array_pop($parts);
        if ($entity instanceof Document) {
            $errors = $entity->getError($tailField);
        }

        if (
            !$errors &&
            $entityErrors &&
            (!is_array($entity) || !($entity[$tailField] instanceof Document))
        ) {
            $errors = Hash::extract($entityErrors, $field) ?: [];
        }

        return (array)$errors;
    }
}

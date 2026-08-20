<?php
declare(strict_types=1);

namespace Crustum\Mongo\View\Form;

use ArrayAccess;
use Cake\Collection\Collection;
use Cake\Core\Exception\CakeException;
use Cake\Datasource\EntityInterface;
use Cake\Datasource\FactoryLocator;
use Cake\Datasource\InvalidPropertyInterface;
use Cake\Http\ServerRequest;
use Cake\Utility\Inflector;
use Cake\Validation\Validator;
use Cake\View\Form\ContextInterface;
use Crustum\Mongo\ODM\Association\BelongsToMany;
use Crustum\Mongo\ODM\BaseCollection as MongoCollection;
use Crustum\Mongo\ODM\Document;
use InvalidArgumentException;
use RuntimeException;
use Traversable;
use function Cake\Core\namespaceSplit;

/**
 * Provides a form context around a single document and its relations.
 *
 * Full port of `Cake\View\Form\EntityContext` for the ODM. A `Collection`
 * (repository) stands in for the ORM `Table`, and entities are handled through
 * the `EntityInterface` contract so both `Document` and plain entities work.
 *
 * Important keys:
 *
 * - `entity` The entity this context is operating on.
 * - `collection` The ODM collection to fetch schema/validators from, an array
 *   of collections for a form spanning multiple entities, or the name(s) of
 *   the collection. If null the collection name is inferred by convention.
 * - `validator` The validator to use, or the name of the validation method to
 *   call on the collection. Defaults to `default`.
 *
 * @see cake60/src/View/Form/EntityContext.php
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
     * Context data for this object.
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
     * Boolean to track whether the entity is a collection.
     *
     * @var bool
     */
    protected bool $isCollection = false;

    /**
     * A dictionary of collections.
     *
     * @var array<string, \Crustum\Mongo\ODM\BaseCollection>
     */
    protected array $collections = [];

    /**
     * Dictionary of validators.
     *
     * @var array<string, \Cake\Validation\Validator>
     */
    protected array $validator = [];

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
            'validator' => [],
        ];
        $this->context = $context;
        $this->prepare();
    }

    /**
     * Prepare some additional data from the context.
     *
     * If the collection option was provided and it was a string, the collection
     * locator is used to get the correct collection instance. If an object is
     * provided it is used as-is. If none is provided, the name is derived from
     * the entity's source or class name.
     *
     * @return void
     * @throws \RuntimeException When a collection object cannot be located/inferred.
     */
    protected function prepare(): void
    {
        $collection = $this->context['collection'];

        /** @var \Cake\Datasource\EntityInterface|iterable<\Cake\Datasource\EntityInterface|array<string, mixed>> $entity */
        $entity = $this->context['entity'];
        $this->isCollection = is_iterable($entity);

        if (!$collection) {
            if ($this->isCollection) {
                /** @var iterable<\Cake\Datasource\EntityInterface|array<string, mixed>> $entity */
                foreach ($entity as $e) {
                    $entity = $e;
                    break;
                }
            }

            if ($entity instanceof EntityInterface) {
                $collection = $entity->getSource();
            }

            if (!$collection && $entity instanceof EntityInterface && $entity::class !== Document::class) {
                [, $entityClass] = namespaceSplit($entity::class);
                $collection = Inflector::pluralize($entityClass);
            }
        }

        if (is_string($collection) && $collection !== '') {
            $collection = FactoryLocator::get('Collection')->get($collection);
        }

        if (!($collection instanceof MongoCollection)) {
            throw new RuntimeException('Unable to find collection class for current entity.');
        }

        $alias = $collection->getAlias();
        $this->rootName = $alias;
        $this->collections[$alias] = $collection;
    }

    /**
     * Get the primary key data for the context.
     *
     * @return array<string>
     */
    public function getPrimaryKey(): array
    {
        return (array)$this->collections[$this->rootName]->getPrimaryKey();
    }

    /**
     * @inheritDoc
     */
    public function isPrimaryKey(string $field): bool
    {
        $parts = explode('.', $field);
        $collection = $this->getCollection($parts);
        if (!$collection instanceof MongoCollection) {
            return false;
        }

        $primaryKey = (array)$collection->getPrimaryKey();

        return in_array(array_pop($parts), $primaryKey, true);
    }

    /**
     * Check whether this form is a create or update.
     *
     * @return bool
     */
    public function isCreate(): bool
    {
        $entity = $this->context['entity'];
        if (is_iterable($entity)) {
            foreach ($entity as $e) {
                $entity = $e;
                break;
            }
        }

        if ($entity instanceof EntityInterface) {
            return $entity->isNew();
        }

        return true;
    }

    /**
     * Get the value for a given path.
     *
     * @param string $field The dot separated path to the value.
     * @param array<string, mixed> $options Options:
     *
     *   - `default`: Default value to return if no value found in data or entity.
     *   - `schemaDefault`: Boolean indicating whether the schema default should
     *     be used when no explicit value exists.
     * @return mixed The value of the field or null on a miss.
     */
    public function val(string $field, array $options = []): mixed
    {
        $options += [
            'default' => null,
            'schemaDefault' => true,
        ];

        if (!$this->context['entity']) {
            return $options['default'];
        }

        $parts = explode('.', $field);
        $entity = $this->entity($parts);

        if ($entity && end($parts) === '_ids') {
            return $this->extractMultiple($entity, $parts);
        }

        if ($entity instanceof EntityInterface) {
            $part = end($parts);

            if ($entity instanceof InvalidPropertyInterface) {
                $val = $entity->getInvalidField($part);
                if ($val !== null) {
                    return $val;
                }
            }

            $val = $entity->has($part) ? $entity->get($part) : null;
            if ($val !== null) {
                return $val;
            }

            if (
                $options['default'] !== null
                || !$options['schemaDefault']
                || !$entity->isNew()
            ) {
                return $options['default'];
            }

            return $this->schemaDefault($parts);
        }

        if (is_array($entity) || $entity instanceof ArrayAccess) {
            $key = array_pop($parts);

            return $entity[$key] ?? $options['default'];
        }

        return null;
    }

    /**
     * Get default value from collection schema for the given entity field.
     *
     * @param array<string> $parts Each one of the parts in a path for a field name.
     * @return mixed
     */
    protected function schemaDefault(array $parts): mixed
    {
        $collection = $this->getCollection($parts);
        if (!$collection instanceof MongoCollection) {
            return null;
        }

        $field = end($parts);
        $defaults = $collection->getSchema()->defaultValues();
        if ($field === false || !array_key_exists($field, $defaults)) {
            return null;
        }

        return $defaults[$field];
    }

    /**
     * Helper used to extract all the primary key values out of an array/collection.
     *
     * @param mixed $values The list from which to extract primary keys from.
     * @param array<string> $path Each one of the parts in a path for a field name.
     * @return array<int|string, mixed>|null
     */
    protected function extractMultiple(mixed $values, array $path): ?array
    {
        if (!is_iterable($values)) {
            return null;
        }

        $collection = $this->getCollection($path, false);
        $primary = $collection instanceof MongoCollection ? (array)$collection->getPrimaryKey() : ['_id'];

        return (new Collection($values))->extract($primary[0])->toArray();
    }

    /**
     * Fetch the entity or data value for a given path.
     *
     * @param array<string>|null $path Each one of the parts in a path for a field name
     *  or null to get the entity passed in constructor context.
     * @return \Cake\Datasource\EntityInterface|iterable<array-key, mixed>|null
     * @throws \Cake\Core\Exception\CakeException When properties cannot be read.
     */
    public function entity(?array $path = null): EntityInterface|iterable|null
    {
        if ($path === null) {
            return $this->context['entity'];
        }

        $oneElement = count($path) === 1;
        if ($oneElement && $this->isCollection) {
            return null;
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
                $collection = $this->getCollection($path);
                if ($collection instanceof MongoCollection) {
                    return $collection->newEmptyEntity();
                }
            }

            $isTraversable = (
                is_iterable($next) ||
                $next instanceof EntityInterface
            );
            if ($isLast || !$isTraversable) {
                return $entity;
            }

            $entity = $next;
        }

        throw new CakeException(sprintf(
            'Unable to fetch property `%s`.',
            implode('.', $path),
        ));
    }

    /**
     * Fetch the terminal or leaf entity for the given path.
     *
     * @param array<string>|null $path Each one of the parts in a path for a field name
     *  or null to get the entity passed in constructor context.
     * @return array{0: \Cake\Datasource\EntityInterface|iterable<array-key, mixed>|null, 1: array<string>|null} Containing the found entity and remaining un-matched path.
     * @throws \Cake\Core\Exception\CakeException When properties cannot be read.
     */
    protected function leafEntity(?array $path = null): array
    {
        if ($path === null) {
            return [$this->context['entity'], null];
        }

        $oneElement = count($path) === 1;
        if ($oneElement && $this->isCollection) {
            throw new CakeException(sprintf(
                'Unable to fetch property `%s`.',
                implode('.', $path),
            ));
        }

        $entity = $this->context['entity'];
        if ($oneElement) {
            return [$entity, $path];
        }

        if ($path[0] === $this->rootName) {
            $path = array_slice($path, 1);
        }

        $len = count($path);
        $leafEntity = $entity;
        for ($i = 0; $i < $len; $i++) {
            $prop = $path[$i];
            $next = $this->getProp($entity, $prop);

            if (is_array($entity) && (!$next instanceof EntityInterface && !$next instanceof Traversable)) {
                return [$leafEntity, array_slice($path, $i - 1)];
            }

            if ($next instanceof EntityInterface) {
                $leafEntity = $next;
            }

            $isTraversable = (
                is_iterable($next) ||
                $next instanceof EntityInterface
            );
            if (!$isTraversable) {
                return [$leafEntity, array_slice($path, $i)];
            }

            $entity = $next;
        }

        throw new CakeException(sprintf(
            'Unable to fetch property `%s`.',
            implode('.', $path),
        ));
    }

    /**
     * Read property values or traverse arrays/iterators.
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

        if ($target instanceof EntityInterface) {
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
     * Check if a field should be marked as required.
     *
     * @param string $field The dot separated path to the field you want to check.
     * @return bool|null
     */
    public function isRequired(string $field): ?bool
    {
        $parts = explode('.', $field);
        $entity = $this->entity($parts);

        $isNew = true;
        if ($entity instanceof EntityInterface) {
            $isNew = $entity->isNew();
        }

        $validator = $this->getValidator($parts);
        $fieldName = array_pop($parts);

        if (!$validator->hasField($fieldName)) {
            return null;
        }

        if (is_callable($validator->field($fieldName)->isEmptyAllowed())) {
            return null;
        }

        if ($this->type($field) !== 'boolean') {
            return !$validator->isEmptyAllowed($fieldName, $isNew);
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function getRequiredMessage(string $field): ?string
    {
        $parts = explode('.', $field);

        $validator = $this->getValidator($parts);
        $fieldName = array_pop($parts);
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
     * Get field length from validation.
     *
     * @param string $field The dot separated path to the field you want to check.
     * @return int|null
     */
    public function getMaxLength(string $field): ?int
    {
        $parts = explode('.', $field);
        $validator = $this->getValidator($parts);
        $fieldName = array_pop($parts);

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
     * Get the field names from the top level entity.
     *
     * @return array<string> Array of field names in the collection/entity.
     */
    public function fieldNames(): array
    {
        $collection = $this->getCollection('0');
        if (!$collection instanceof MongoCollection) {
            return [];
        }

        return $collection->getSchema()->columns();
    }

    /**
     * Get the validator associated to an entity based on naming conventions.
     *
     * @param array<string> $parts Each one of the parts in a path for a field name.
     * @return \Cake\Validation\Validator
     * @throws \InvalidArgumentException If the validator cannot be retrieved.
     */
    protected function getValidator(array $parts): Validator
    {
        $keyParts = array_filter(array_slice($parts, 0, -1), fn(string $part): bool => !is_numeric($part));
        $key = implode('.', $keyParts);
        $entity = $this->entity($parts);

        if (isset($this->validator[$key])) {
            if (is_object($entity)) {
                $this->validator[$key]->setProvider('entity', $entity);
            }

            return $this->validator[$key];
        }

        $collection = $this->getCollection($parts);
        if (!$collection instanceof MongoCollection) {
            throw new InvalidArgumentException(sprintf('Validator not found: `%s`.', $key));
        }

        $alias = $collection->getAlias();

        $method = 'default';
        if (is_string($this->context['validator'])) {
            $method = $this->context['validator'];
        } elseif (isset($this->context['validator'][$alias])) {
            $method = $this->context['validator'][$alias];
        }

        $validator = $collection->getValidator($method);

        if (is_object($entity)) {
            $validator->setProvider('entity', $entity);
        }

        return $this->validator[$key] = $validator;
    }

    /**
     * Get the collection instance from a property path.
     *
     * @param \Cake\Datasource\EntityInterface|array<string>|string $parts Each one of the parts in a path for a field name.
     * @param bool $fallback Whether to fallback to the last found collection.
     * @return \Crustum\Mongo\ODM\BaseCollection|null Collection instance or null.
     */
    protected function getCollection(EntityInterface|array|string $parts, bool $fallback = true): ?MongoCollection
    {
        if (!is_array($parts) || count($parts) === 1) {
            return $this->collections[$this->rootName];
        }

        $normalized = array_slice(array_filter($parts, fn(string $part): bool => !is_numeric($part)), 0, -1);

        $path = implode('.', $normalized);
        if (isset($this->collections[$path])) {
            return $this->collections[$path];
        }

        if (current($normalized) === $this->rootName) {
            $normalized = array_slice($normalized, 1);
        }

        $collection = $this->collections[$this->rootName];
        $assoc = null;
        foreach ($normalized as $part) {
            if ($assoc instanceof BelongsToMany && $part === $assoc->getProperty()) {
                $collection = $assoc->junction();
                $assoc = null;
                continue;
            }

            $assoc = $collection->associations()->getByProperty($part);

            if ($assoc === null) {
                if ($fallback) {
                    break;
                }

                return null;
            }

            $collection = $assoc->getTarget();
        }

        return $this->collections[$path] = $collection;
    }

    /**
     * Get the abstract field type for a given field name.
     *
     * @param string $field A dot separated path to get a schema type for.
     * @return string|null An abstract data type or null.
     */
    public function type(string $field): ?string
    {
        $parts = explode('.', $field);

        return $this->getCollection($parts)?->getSchema()->baseColumnType(array_pop($parts));
    }

    /**
     * Get an associative array of other attributes for a field name.
     *
     * @param string $field A dot separated path to get additional data on.
     * @return array<string, mixed> An array of data describing the additional attributes on a field.
     */
    public function attributes(string $field): array
    {
        $parts = explode('.', $field);
        $collection = $this->getCollection($parts);
        if (!$collection instanceof MongoCollection) {
            return [];
        }

        return array_intersect_key(
            (array)$collection->getSchema()->getColumn(array_pop($parts)),
            array_flip(static::VALID_ATTRIBUTES),
        );
    }

    /**
     * Check whether a field has an error attached to it.
     *
     * @param string $field A dot separated path to check errors on.
     * @return bool Returns true if the errors for the field are not empty.
     */
    public function hasError(string $field): bool
    {
        return $this->error($field) !== [];
    }

    /**
     * Get the errors for a given field.
     *
     * @param string $field A dot separated path to check errors on.
     * @return array<string, mixed> An array of errors.
     */
    public function error(string $field): array
    {
        $parts = explode('.', $field);
        try {
            /**
             * @var \Cake\Datasource\EntityInterface|null $entity
             * @var array<string> $remainingParts
             */
            [$entity, $remainingParts] = $this->leafEntity($parts);
        } catch (CakeException) {
            return [];
        }

        if ($entity instanceof EntityInterface && count($remainingParts) === 0) {
            return $entity->getErrors();
        }

        if ($entity instanceof EntityInterface) {
            $error = $entity->getError(implode('.', $remainingParts));
            if ($error !== []) {
                return $error;
            }

            return $entity->getError(array_pop($parts));
        }

        return [];
    }
}

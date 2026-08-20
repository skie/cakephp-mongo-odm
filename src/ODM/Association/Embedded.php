<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Association;

use Cake\Datasource\EntityInterface;
use Cake\Validation\Validator;
use Closure;
use Crustum\Mongo\Database\Aggregation\AggregationBuilder;
use Crustum\Mongo\Database\QueryBuilder;
use Crustum\Mongo\ODM\Association;
use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\ODM\Document;
use InvalidArgumentException;
use MongoDB\BSON\ObjectId;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;
use Override;

/**
 * Base class for associations stored inside the source document.
 *
 * Embedded associations never issue a secondary query and always use the
 * `embed` loading strategy.
 *
 * @see cake60/src/ORM/Association/HasOne.php
 * @see cake60/src/ORM/Association/HasMany.php
 */
abstract class Embedded extends Association
{
    /** The only supported strategy for embedded associations. */
    /**
     * @var array<string>
     */
    protected array $validStrategies = [self::STRATEGY_EMBED];

    /**
     * Parent field holding the embedded data (defaults to the property).
     *
     * @var string|null
     */
    protected ?string $localKey = null;

    /**
     * Validator (or validator builder) for the embedded children.
     *
     * @var \Cake\Validation\Validator|\Closure|null
     */
    protected mixed $embeddedValidator = null;

    /**
     * @inheritDoc
     */
    protected function options(array $options): void
    {
        if (isset($options['validator'])) {
            $this->setEmbeddedValidator($options['validator']);
        }

        parent::options($options);
    }

    /**
     * Sets the local key (parent field holding the embedded data).
     *
     * @param string $localKey Field name on the parent document.
     * @return $this
     */
    public function setLocalKey(string $localKey): static
    {
        $this->localKey = $localKey;

        return $this;
    }

    /**
     * Sets the validator for embedded children.
     *
     * @param \Cake\Validation\Validator|\Closure $validator Validator or `fn(Validator $v) => $v`.
     * @return $this
     */
    public function setEmbeddedValidator(Validator|Closure $validator): static
    {
        $this->embeddedValidator = $validator;

        return $this;
    }

    /**
     * Gets the validator for embedded children, building it lazily.
     *
     * @return \Cake\Validation\Validator|null
     */
    public function getEmbeddedValidator(): ?Validator
    {
        if ($this->embeddedValidator instanceof Validator) {
            return $this->embeddedValidator;
        }

        if ($this->embeddedValidator instanceof Closure) {
            $validator = new Validator();
            $this->embeddedValidator = ($this->embeddedValidator)($validator);
            if (!$this->embeddedValidator instanceof Validator) {
                $this->embeddedValidator = null;
            }

            return $this->embeddedValidator;
        }

        return null;
    }

    /**
     * Embedded data lives inside the source document, so the "target" is the
     * source collection itself.
     *
     * @return \Crustum\Mongo\ODM\BaseCollection
     */
    #[Override]
    public function getTarget(): BaseCollection
    {
        return $this->getSource();
    }

    /**
     * Embedded associations always hydrate in the root document.
     *
     * @return string
     */
    #[Override]
    public function getStrategy(): string
    {
        return self::STRATEGY_EMBED;
    }

    /**
     * Rejects non-embedded strategies.
     *
     * @param string $strategy Strategy name.
     * @return $this
     * @throws \InvalidArgumentException If the strategy is not `embed`.
     */
    #[Override]
    public function setStrategy(string $strategy): static
    {
        if ($strategy !== self::STRATEGY_EMBED) {
            throw new InvalidArgumentException('Embedded associations only support the embed strategy.');
        }

        return $this;
    }

    /**
     * Converts BSON containers into PHP arrays for hydration.
     *
     * @param mixed $value Value to normalize.
     * @return mixed
     */
    protected function normalize(mixed $value): mixed
    {
        if ($value instanceof BSONDocument || $value instanceof BSONArray) {
            return $value->getArrayCopy();
        }

        return $value;
    }

    /**
     * Hydrates one embedded document.
     *
     * @param array<string, mixed> $data Embedded data.
     * @param array<string, mixed> $options Hydration options.
     * @return \Crustum\Mongo\ODM\Document
     */
    protected function document(array $data, array $options): Document
    {
        $class = $this->getDocumentClass();
        $document = new $class($data, $options + ['markClean' => true, 'markNew' => false]);
        if (!$document instanceof Document) {
            throw new InvalidArgumentException('Embedded entity class must extend Document.');
        }

        return $document;
    }

    /**
     * Builds `$match` stages for `matching()` / `notMatching()` on the
     * embedded field.
     *
     * Embedded data lives in the parent document, so matching is a plain
     * `$match` on the field:
     * - `matching()` → the field is non-empty (`$exists` + `$ne` empty; with
     *   conditions an `$elemMatch`).
     * - `notMatching()` (negateMatch) → the field is absent/null/empty.
     *
     * @param array<string, mixed> $options Pipeline options (`matching`,
     *   `negateMatch`, `conditions`).
     * @return list<array<string, mixed>>
     */
    public function buildPipeline(array $options = []): array
    {
        $matching = (bool)($options['matching'] ?? false);
        if (!$matching) {
            return [];
        }

        $field = $this->getLocalKey();
        $negate = (bool)($options['negateMatch'] ?? false);
        $conditions = $options['conditions'] ?? [];
        $query = new QueryBuilder();
        $builder = new AggregationBuilder();

        if ($negate) {
            $builder->match($query->in($field, [null, []]));
        } elseif ($conditions !== []) {
            $builder->match($query->elemMatch($field, $conditions));
        } else {
            $builder->match(array_replace_recursive(
                $query->exists($field)->getConditions(),
                $query->notIn($field, [null, []])->getConditions(),
            ));
        }

        return $builder->getPipeline();
    }

    /**
     * Gets the local key (parent field holding the embedded data).
     *
     * Defaults to the association property; overridable via `localKey`.
     *
     * @return string
     */
    public function getLocalKey(): string
    {
        return $this->localKey ?? $this->getProperty();
    }

    /**
     * Returns the source primary key as a single field name.
     *
     * @return string
     */
    protected function parentKey(): string
    {
        $key = $this->getSource()->getPrimaryKey();

        return is_array($key) ? ($key[0] ?? '_id') : $key;
    }

    /**
     * Saves a single embedded child to the parent document.
     *
     * Child-scoped persistence: updates only the embedded element, not the
     * whole parent. For EmbedMany this uses the positional `$` operator
     * (matched by `_id`); for EmbedOne it sets the whole field. If the child
     * has no `_id` yet, it is inserted into the array (EmbedMany) or replaces
     * the single value (EmbedOne).
     *
     * @param \Cake\Datasource\EntityInterface $parent The parent document.
     * @param \Cake\Datasource\EntityInterface $child The embedded child to save.
     * @return bool
     */
    public function saveChild(EntityInterface $parent, EntityInterface $child): bool
    {
        $source = $this->getSource();
        $parentKey = $this->parentKey();
        $parentId = $parent->get($parentKey);
        $collection = $source->getCollection();
        $localKey = $this->getLocalKey();
        $data = $this->normalize($child instanceof Document ? $child->toArray() : (array)$child);

        $validator = $this->getEmbeddedValidator();
        if ($validator instanceof Validator) {
            $errors = $validator->validate($data, $child->isNew());
            if ($errors !== []) {
                $child->setErrors($errors);

                return false;
            }
        }

        $query = $source->updateQuery()
            ->update($collection)
            ->where([$parentKey => $parentId]);

        if ($this instanceof EmbedOne) {
            $query->set([$localKey => $data]);

            return $query->execute() !== false;
        }

        $childId = $this->getEmbeddedId($data);
        if ($childId === null) {
            $childId = (string)new ObjectId();
            $data['_id'] = $childId;
            $query->push([$localKey => $data]);

            return $query->execute() !== false;
        }

        $query->where([$localKey . '._id' => $childId]);
        $query->set([$localKey . '.$' => $data]);

        return $query->execute() !== false;
    }

    /**
     * Deletes a single embedded child from the parent document.
     *
     * For EmbedMany this pulls the element by `_id`; for EmbedOne it unsets
     * the field.
     *
     * @param \Cake\Datasource\EntityInterface $parent The parent document.
     * @param \Cake\Datasource\EntityInterface $child The embedded child to delete.
     * @return bool
     */
    public function deleteChild(EntityInterface $parent, EntityInterface $child): bool
    {
        $source = $this->getSource();
        $parentKey = $this->parentKey();
        $parentId = $parent->get($parentKey);
        $collection = $source->getCollection();
        $localKey = $this->getLocalKey();
        $data = $this->normalize($child instanceof Document ? $child->toArray() : (array)$child);
        $childId = $this->getEmbeddedId($data);

        $query = $source->updateQuery()
            ->update($collection)
            ->where([$parentKey => $parentId]);

        if ($this instanceof EmbedOne) {
            $query->unset([$localKey]);

            return $query->execute() !== false;
        }

        if ($childId !== null) {
            $query->pull([$localKey => ['_id' => $childId]]);
        } else {
            $query->set([$localKey => array_values(array_filter(
                (array)$parent->get($localKey),
                fn(mixed $item): bool => $this->normalize((array)$item) !== $data,
            ))]);
        }

        return $query->execute() !== false;
    }

    /**
     * Clears the embedded field on the parent document.
     *
     * @param \Cake\Datasource\EntityInterface $parent The parent document.
     * @return bool
     */
    public function clearEmbedded(EntityInterface $parent): bool
    {
        $source = $this->getSource();
        $parentKey = $this->parentKey();
        $parentId = $parent->get($parentKey);

        $query = $source->updateQuery()
            ->update($source->getCollection())
            ->where([$parentKey => $parentId]);

        if ($this instanceof EmbedOne) {
            $query->unset([$this->getLocalKey()]);
        } else {
            $query->set([$this->getLocalKey() => []]);
        }

        return $query->execute() !== false;
    }

    /**
     * Extracts the embedded child's `_id` from its data, if present.
     *
     * @param array<string, mixed> $data The child data.
     * @return string|null
     */
    protected function getEmbeddedId(array $data): ?string
    {
        $id = $data['_id'] ?? null;
        if ($id instanceof ObjectId) {
            return (string)$id;
        }

        return $id !== null ? (string)$id : null;
    }

    /**
     * Embedded cascade is structural: the data lives inside the parent, so
     * deleting the parent removes it. `dependent` is a no-op and must not
     * issue any FK-based delete.
     *
     * @param \Cake\Datasource\EntityInterface $document The parent document.
     * @param array<string, mixed> $options Delete options.
     * @return bool
     */
    #[Override]
    public function cascadeDelete(EntityInterface $document, array $options = []): bool
    {
        return true;
    }

    /**
     * Hydrates the raw embedded value into a Document (or list of Documents).
     *
     * The embedded parent back-pointer is set so a child `$doc->save()` can
     * route to the root document's write.
     *
     * Named `hydrateEmbedded` (not `hydrate`) because `DBRef` (also an
     * Embedded) owns the `hydrate()` name with a different signature.
     *
     * @param mixed $raw The raw embedded value from the parent document.
     * @param \Cake\Datasource\EntityInterface $parent The parent document.
     * @param array<string, mixed> $options Hydration options.
     * @return mixed
     */
    public function hydrateEmbedded(mixed $raw, EntityInterface $parent, array $options = []): mixed
    {
        $value = $this->normalize($raw);

        return $value === null ? null : $this->embeddedDocument((array)$value, $parent, $options);
    }

    /**
     * Hydrates one embedded document with the parent back-pointer set.
     *
     * @param array<string, mixed> $data Embedded data.
     * @param \Cake\Datasource\EntityInterface $parent The parent document.
     * @param array<string, mixed> $options Hydration options.
     * @return \Crustum\Mongo\ODM\Document
     */
    protected function embeddedDocument(array $data, EntityInterface $parent, array $options): Document
    {
        $document = $this->document($data, $options);
        $document->setEmbeddedParent($parent, $this);

        return $document;
    }
}

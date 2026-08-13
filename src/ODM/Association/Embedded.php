<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Association;

use Cake\Datasource\EntityInterface;
use Crustum\Mongo\ODM\Association;
use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\ODM\Document;
use InvalidArgumentException;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;

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
     * Embedded data lives inside the source document, so the "target" is the
     * source collection itself.
     *
     * @return \Crustum\Mongo\ODM\BaseCollection
     */
    public function getTarget(): BaseCollection
    {
        return $this->getSource();
    }

    /**
     * Embedded associations always hydrate in the root document.
     *
     * @return string
     */
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
     * @return array<int, array<string, mixed>>
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

        if ($negate) {
            return [[
                '$match' => [
                    $field => ['$in' => [null, []]],
                ],
            ]];
        }

        if ($conditions !== []) {
            return [[
                '$match' => [
                    $field => ['$elemMatch' => $conditions],
                ],
            ]];
        }

        return [[
            '$match' => [
                $field => ['$exists' => true, '$nin' => [null, []]],
            ],
        ]];
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

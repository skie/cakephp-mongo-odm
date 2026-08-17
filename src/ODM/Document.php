<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM;

use ArrayAccess;
use Cake\Datasource\EntityInterface;
use Cake\Datasource\EntityTrait;
use Cake\Datasource\InvalidPropertyInterface;
use Crustum\Mongo\ODM\Association\Embedded;
use DateTimeZone;
use MongoDB\BSON\Decimal128;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;

/**
 * Metadata-free MongoDB document entity.
 *
 * This entity uses the CakePHP entity trait for accessors, dirty tracking,
 * original values, errors, visibility, and guarded properties. MongoDB's
 * `_id` field is the canonical primary key; `getId()`/`setId()` are thin
 * sugar over it. There is no `id` alias field.
 *
 * @see cake60/src/Datasource/EntityTrait.php
 * @implements \ArrayAccess<string, mixed>
 */
class Document implements EntityInterface, InvalidPropertyInterface, ArrayAccess
{
    use EntityTrait {
        toArray as protected entityToArray;
        get as protected entityGet;
        has as protected entityHas;
        __isset as protected entityIsset;
        __get as protected entityGetMagic;
    }

    /**
     * Constructor.
     *
     * @param \MongoDB\Model\BSONDocument|array<string, mixed> $data The initial document data.
     * @param array{markClean?: bool, markNew?: bool|null, source?: string|null, guard?: bool, useSetters?: bool} $options Entity construction options.
     */
    public function __construct(array|BSONDocument $data = [], array $options = [])
    {
        if ($data instanceof BSONDocument) {
            $data = $data->getArrayCopy();
        }

        $options += [
            'markClean' => false,
            'markNew' => null,
            'source' => null,
            'guard' => false,
            'useSetters' => true,
        ];

        if ($options['source'] !== null) {
            $this->setSource($options['source']);
        }

        if ($options['markNew'] !== null) {
            $this->setNew($options['markNew']);
        }

        if ($data !== []) {
            $this->setOriginalField(array_keys($data));

            $this->patch($data, [
                'asOriginal' => true,
                'guard' => $options['guard'],
                'setter' => $options['useSetters'],
            ]);
        }

        if ($options['markClean']) {
            $this->clean();
        }
    }

    /**
     * Returns the string representation of the MongoDB identifier.
     *
     * @return string|null The identifier, or null when the document is new.
     */
    public function getId(): ?string
    {
        $id = $this->get('_id');

        return $id instanceof ObjectId ? (string)$id : $id;
    }

    /**
     * Sets the MongoDB identifier.
     *
     * @param \MongoDB\BSON\ObjectId|string|null $id The identifier to set.
     * @return $this
     */
    public function setId(ObjectId|string|null $id): static
    {
        $this->set('_id', is_string($id) ? new ObjectId($id) : $id);
        $this->setNew(false);

        return $this;
    }

    /**
     * Returns whether this document is considered new.
     *
     * Follows the cake5 `EntityTrait` contract: the `_new` flag is the source
     * of truth, so `markNew` / `setNew()` control it. A document constructed
     * with a present `_id` is automatically marked not-new (see constructor).
     *
     * @return bool
     */
    public function isNew(): bool
    {
        return $this->_new;
    }

    /**
     * Returns the source collection alias assigned during hydration.
     *
     * @return string|null
     */
    public function collection(): ?string
    {
        return $this->getSource() ?: null;
    }

    /**
     * Converts the entity into a BSON-friendly array representation.
     *
     * The canonical `_id` key is preserved (values converted to strings for
     * ObjectId / Decimal128 and formatted dates), so output matches the Mongo
     * document shape. There is no `id` alias key.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->exportValue($this->entityToArray());
    }

    /**
     * Returns the JSON representation of this document.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * The embedded parent document and association (in-memory back-pointer).
     *
     * Set during embedded hydration so a child `$doc->save()` can route to
     * the root document's write.
     *
     * @var array{parent: \Cake\Datasource\EntityInterface, association: \Crustum\Mongo\ODM\Association\Embedded}|null
     */
    protected ?array $embeddedParent = null;

    /**
     * Sets the embedded parent back-pointer on this document.
     *
     * @param \Cake\Datasource\EntityInterface $parent The parent document.
     * @param \Crustum\Mongo\ODM\Association\Embedded $association The embedded association.
     * @return $this
     */
    public function setEmbeddedParent(EntityInterface $parent, Embedded $association): static
    {
        $this->embeddedParent = ['parent' => $parent, 'association' => $association];

        return $this;
    }

    /**
     * Gets the embedded parent back-pointer, or null for root documents.
     *
     * @return array{parent: \Cake\Datasource\EntityInterface, association: \Crustum\Mongo\ODM\Association\Embedded}|null
     */
    public function getEmbeddedParent(): ?array
    {
        return $this->embeddedParent;
    }

    /**
     * Magic getter with `id` → `_id` fallback.
     *
     * Mongo stores identity in `_id`; reading `$doc->id` / `$doc['id']` maps
     * to it so cake-style code keeps working. A plain `id` field wins when
     * present (cake entities may set `id` as an ordinary field).
     *
     * @param string $field The field to read.
     * @return mixed
     */
    public function &__get(string $field): mixed
    {
        if ($field === 'id' && !$this->entityHas('id')) {
            return $this->getRequiredOrFail('_id', false);
        }

        return $this->entityGetMagic($field);
    }

    /**
     * Checks `id` → `_id` for magic isset.
     *
     * @param string $field The field to check.
     * @return bool
     */
    public function __isset(string $field): bool
    {
        if ($field === 'id' && !$this->entityHas('id')) {
            return $this->entityHas('_id');
        }

        return $this->entityIsset($field);
    }

    /**
     * Gets a field with `id` → `_id` fallback.
     *
     * @param string $field The field to read.
     * @return mixed
     */
    public function &get(string $field): mixed
    {
        if ($field === 'id' && !$this->entityHas('id')) {
            return $this->getRequiredOrFail('_id', false);
        }

        return $this->entityGet($field);
    }

    /**
     * Checks field presence with `id` → `_id` fallback.
     *
     * @param array<string>|string $field The field(s) to check.
     * @return bool
     */
    public function has(array|string $field): bool
    {
        if ($field === 'id' && !$this->entityHas('id')) {
            return $this->entityHas('_id');
        }

        return $this->entityHas($field);
    }

    /**
     * Converts a value recursively for document output.
     *
     * @param mixed $value The value to convert.
     * @return mixed The converted value.
     */
    private function exportValue(mixed $value): mixed
    {
        if ($value instanceof Document) {
            return $value;
        }

        if ($value instanceof ObjectId || $value instanceof Decimal128) {
            return (string)$value;
        }

        if ($value instanceof UTCDateTime) {
            return $value->toDateTime()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }

        if ($value instanceof BSONDocument || $value instanceof BSONArray) {
            $value = $value->getArrayCopy();
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->exportValue($item);
            }
        }

        return $value;
    }
}

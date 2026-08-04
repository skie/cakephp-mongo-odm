<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM;

use ArrayAccess;
use Cake\Datasource\EntityInterface;
use Cake\Datasource\EntityTrait;
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
 * `_id` field remains canonical; `id` is only an accessor convenience.
 *
 * @see cake60/src/Datasource/EntityTrait.php
 * @implements \ArrayAccess<string, mixed>
 */
final class Document implements EntityInterface, ArrayAccess
{
    use EntityTrait {
        __get as protected entityGet;
        toArray as protected entityToArray;
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

        if ($data !== []) {
            $this->patch($data, [
                'guard' => $options['guard'],
                'setter' => $options['useSetters'],
            ]);
        }

        if ($options['markClean']) {
            $this->clean();
        }

        if ($options['markNew'] !== null) {
            $this->setNew($options['markNew']);
        }
    }

    /**
     * Gets a document property.
     *
     * The `id` property is mapped to MongoDB's canonical `_id` field.
     *
     * @param string $field The property name.
     * @return mixed The property value.
     */
    public function &__get(string $field): mixed
    {
        if ($field === 'id') {
            $id = $this->getId();

            return $id;
        }

        return $this->entityGet($field);
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

        return $this;
    }

    /**
     * Returns whether this document has no MongoDB identifier.
     *
     * @return bool
     */
    public function isNew(): bool
    {
        return $this->get('_id') === null;
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

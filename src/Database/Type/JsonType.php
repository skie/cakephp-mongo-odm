<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Type;

use Crustum\Mongo\Database\Driver\MongoDriver;
use InvalidArgumentException;
use JsonException;
use Override;

/**
 * JSON type converter
 *
 * Use to convert JSON-encoded string data between PHP and MongoDB. Mirrors
 * `Cake\Database\Type\JsonType`. Prefer `HashType`/`ArrayType` when storing
 * documents natively.
 *
 * @ported-from \Cake\Database\Type\JsonType
 */
class JsonType extends BaseType
{
    /**
     * Flags for json_encode()
     *
     * @var int
     */
    protected int $encodingOptions = 0;

    /**
     * Flags for json_decode()
     *
     * @var int
     */
    protected int $decodingOptions = JSON_OBJECT_AS_ARRAY;

    /**
     * Convert a value into a JSON string
     *
     * @param mixed $value The value to convert
     * @param \Crustum\Mongo\Database\Driver\MongoDriver $driver The driver instance to convert with
     * @return string|null
     * @throws \InvalidArgumentException When the value cannot be JSON encoded
     */
    public function toDatabase(mixed $value, MongoDriver $driver): ?string
    {
        if (is_resource($value)) {
            throw new InvalidArgumentException('Cannot convert a resource value to JSON');
        }

        if ($value === null) {
            return null;
        }

        try {
            return json_encode($value, JSON_THROW_ON_ERROR | $this->encodingOptions);
        } catch (JsonException $jsonException) {
            throw new InvalidArgumentException(
                sprintf('Cannot encode value of type `%s` as JSON', get_debug_type($value)),
                0,
                $jsonException,
            );
        }
    }

    /**
     * Convert JSON string values to PHP values
     *
     * @param mixed $value The value to convert
     * @param \Crustum\Mongo\Database\Driver\MongoDriver $driver The driver instance to convert with
     * @return mixed Converted value
     */
    public function toPHP(mixed $value, MongoDriver $driver): mixed
    {
        if (!is_string($value)) {
            return null;
        }

        return json_decode($value, flags: $this->decodingOptions);
    }

    /**
     * Marshals request data into a JSON compatible structure
     *
     * @param mixed $value The value to convert
     * @return mixed Converted value
     */
    #[Override]
    public function marshal(mixed $value): mixed
    {
        return $value;
    }

    /**
     * Sets json_encode options
     *
     * @param int $options Encoding flags. Use `JSON_*` flags. Set `0` to reset.
     * @return $this
     */
    public function setEncodingOptions(int $options): static
    {
        $this->encodingOptions = $options;

        return $this;
    }

    /**
     * Sets json_decode options
     *
     * By default, the value is `JSON_OBJECT_AS_ARRAY`.
     *
     * @param int $options Decoding flags. Use `JSON_*` flags. Set `0` to reset.
     * @return $this
     */
    public function setDecodingOptions(int $options): static
    {
        $this->decodingOptions = $options;

        return $this;
    }
}

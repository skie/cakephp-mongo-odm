<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Type;

use Crustum\Mongo\Database\Driver\MongoDriver;
use MongoDB\BSON\Binary;
use MongoDB\BSON\PackedArray;

/**
 * Base vector type converter.
 *
 * Provides the common conversion between PHP arrays of numbers and their
 * packed BSON representation used by MongoDB 7.0+ / Atlas Vector Search.
 *
 * Subclasses only need to declare the packing/format they use. Values are
 * stored either as a `PackedArray` (float32/int8 encoding) or, for packed-bit
 * vectors, as a `Binary` of packed bytes.
 *
 * @see mongodb-odm Types/AbstractVectorType.php
 */
abstract class AbstractVectorType extends BaseType
{
    /**
     * Normalizes an input value to a plain PHP list of scalars.
     *
     * Accepts arrays, packed arrays and binary payloads so values read back
     * from MongoDB round-trip through `toPHP()` without loss.
     *
     * @param mixed $value The value to normalize
     * @return array<int, float|int>|null
     */
    protected function toVector(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof PackedArray) {
            $values = $value->toPHP();

            return $this->coerce(is_array($values) ? $values : []);
        }

        if ($value instanceof Binary) {
            return $this->coerce($this->unpackBits($value->getData()));
        }

        if (is_array($value)) {
            return $this->coerce($value);
        }

        return null;
    }

    /**
     * Coerces a list of scalars to the target scalar type.
     *
     * @param array<int, mixed> $values The raw values
     * @return array<int, float|int>
     */
    abstract protected function coerce(array $values): array;

    /**
     * Packs a bit list into bytes.
     *
     * The last byte is zero-padded when the bit count is not a multiple of
     * eight, so round-tripping yields a byte-aligned (possibly padded) list.
     *
     * @param array<int, float|int> $bits The bit values (0/1)
     * @return string The packed bytes
     */
    protected function packBits(array $bits): string
    {
        $bytes = '';
        for ($i = 0, $len = count($bits); $i < $len; $i += 8) {
            $byte = 0;
            for ($j = 0; $j < 8; $j++) {
                $bit = isset($bits[$i + $j]) ? (int)$bits[$i + $j] : 0;
                if ($bit !== 0) {
                    $byte |= (1 << $j);
                }
            }

            $bytes .= chr($byte);
        }

        return $bytes;
    }

    /**
     * Unpacks bytes back into a bit list.
     *
     * @param string $bytes The packed bytes
     * @return array<int, int>
     */
    protected function unpackBits(string $bytes): array
    {
        $bits = [];
        for ($i = 0, $len = strlen($bytes); $i < $len; $i++) {
            $byte = ord($bytes[$i]);
            for ($j = 0; $j < 8; $j++) {
                $bits[] = ($byte >> $j) & 1;
            }
        }

        return $bits;
    }

    /**
     * @inheritDoc
     */
    public function toDatabase(mixed $value, MongoDriver $driver): PackedArray|Binary|null
    {
        $vector = $this->toVector($value);
        if ($vector === null) {
            return null;
        }

        return $this->toPacked($vector);
    }

    /**
     * Packs a normalized vector into the storage representation.
     *
     * @param array<int, float|int> $vector The normalized vector
     * @return \MongoDB\BSON\PackedArray|\MongoDB\BSON\Binary
     */
    abstract protected function toPacked(array $vector): PackedArray|Binary;

    /**
     * Converts a PHP vector to its packed BSON representation.
     *
     * @return array<int, float|int>|null The normalized vector
     */
    public function toPHP(mixed $value, MongoDriver $driver): ?array
    {
        return $this->toVector($value);
    }

    /**
     * @inheritDoc
     */
    public function marshal(mixed $value): mixed
    {
        return $this->toVector($value);
    }
}

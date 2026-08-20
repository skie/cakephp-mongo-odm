<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Type;

use MongoDB\BSON\Binary;

/**
 * Packed-bit vector type converter.
 *
 * Converts between a PHP array of 0/1 bits and a packed-byte `Binary` payload
 * for bit-level vector search encodings.
 *
 * @inspired-by \Doctrine\ODM\MongoDB\Types\VectorPackedBitType
 */
class VectorPackedBitType extends AbstractVectorType
{
    /**
     * @inheritDoc
     */
    protected function coerce(array $values): array
    {
        return array_map(
            static fn(mixed $value): int => (int)$value !== 0 ? 1 : 0,
            $values,
        );
    }

    /**
     * @inheritDoc
     */
    protected function toPacked(array $vector): Binary
    {
        return new Binary($this->packBits($vector), Binary::TYPE_USER_DEFINED);
    }
}

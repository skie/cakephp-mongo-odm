<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Type;

use MongoDB\BSON\PackedArray;

/**
 * Int8 vector type converter.
 *
 * Converts between a PHP array of ints and its packed int8 BSON
 * representation for vector search.
 *
 * @inspired-by \Doctrine\ODM\MongoDB\Types\VectorInt8Type
 */
class VectorInt8Type extends AbstractVectorType
{
    /**
     * @inheritDoc
     */
    protected function coerce(array $values): array
    {
        return array_map(intval(...), $values);
    }

    /**
     * @inheritDoc
     */
    protected function toPacked(array $vector): PackedArray
    {
        return PackedArray::fromPHP($vector);
    }
}

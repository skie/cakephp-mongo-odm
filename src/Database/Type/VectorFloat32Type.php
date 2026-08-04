<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Type;

use MongoDB\BSON\PackedArray;

/**
 * Float32 vector type converter.
 *
 * Converts between a PHP array of floats and its packed float32 BSON
 * representation for vector search.
 *
 * @see mongodb-odm Types/VectorFloat32Type.php
 */
class VectorFloat32Type extends AbstractVectorType
{
    /**
     * @inheritDoc
     */
    protected function coerce(array $values): array
    {
        return array_map(floatval(...), $values);
    }

    /**
     * @inheritDoc
     */
    protected function toPacked(array $vector): PackedArray
    {
        return PackedArray::fromPHP($vector);
    }
}

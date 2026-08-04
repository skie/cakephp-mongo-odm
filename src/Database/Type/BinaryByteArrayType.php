<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Type;

use MongoDB\BSON\Binary;

/**
 * Binary byte array type converter
 *
 * Use to convert byte array binary data between PHP and MongoDB.
 */
class BinaryByteArrayType extends BinaryType
{
    /**
     * Binary subtype for byte array
     *
     * @var int
     */
    protected int $subtype = Binary::TYPE_OLD_BINARY;
}

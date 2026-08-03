<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Type;

use MongoDB\BSON\Binary;

/**
 * Binary UUID type converter
 *
 * Use to convert UUID binary data between PHP and MongoDB
 */
class BinaryUuidType extends BinaryType
{
    /**
     * Binary subtype for UUID
     *
     * @var int
     */
    protected int $subtype = Binary::TYPE_OLD_UUID;
}

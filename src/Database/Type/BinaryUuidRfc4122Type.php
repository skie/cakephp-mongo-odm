<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Type;

use MongoDB\BSON\Binary;

/**
 * Binary RFC 4122 UUID type converter
 *
 * Use to convert RFC 4122 UUID binary data between PHP and MongoDB.
 *
 * @ported-from \Doctrine\ODM\MongoDB\Types\BinDataUUIDRFC4122Type
 */
class BinaryUuidRfc4122Type extends BinaryType
{
    /**
     * Binary subtype for RFC 4122 UUID
     *
     * @var int
     */
    protected int $subtype = Binary::TYPE_UUID;
}

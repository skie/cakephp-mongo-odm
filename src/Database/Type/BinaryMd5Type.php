<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Type;

use MongoDB\BSON\Binary;

/**
 * Binary MD5 type converter
 *
 * Use to convert MD5 binary data between PHP and MongoDB
 *
 * @ported-from \Doctrine\ODM\MongoDB\Types\BinDataMD5Type
 */
class BinaryMd5Type extends BinaryType
{
    /**
     * Binary subtype for MD5
     *
     * @var int
     */
    protected int $subtype = Binary::TYPE_MD5;
}

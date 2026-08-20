<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Type;

use MongoDB\BSON\Binary;

/**
 * Binary function type converter
 *
 * Use to convert function binary data between PHP and MongoDB.
 *
 * @ported-from \Doctrine\ODM\MongoDB\Types\BinDataFuncType
 */
class BinaryFuncType extends BinaryType
{
    /**
     * Binary subtype for function
     *
     * @var int
     */
    protected int $subtype = Binary::TYPE_FUNCTION;
}

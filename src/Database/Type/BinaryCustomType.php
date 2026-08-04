<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Type;

use MongoDB\BSON\Binary;

/**
 * Binary custom (user-defined) type converter
 *
 * Use to convert user-defined custom binary data between PHP and MongoDB.
 */
class BinaryCustomType extends BinaryType
{
    /**
     * Binary subtype for custom user-defined data
     *
     * @var int
     */
    protected int $subtype = Binary::TYPE_USER_DEFINED;
}

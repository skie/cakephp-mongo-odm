<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Enum;

/**
 * Persistence outcome for a MongoDB document.
 */
enum SaveStatus: string
{
    case Created = 'created';
    case Deleted = 'deleted';
    case Updated = 'updated';
}

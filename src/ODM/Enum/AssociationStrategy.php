<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Enum;

/**
 * Strategy used to load an ODM association.
 *
 * Embedded associations are hydrated from the root document. Referenced
 * associations use either a batched select query or an aggregation lookup.
 *
 * @see cake60/src/ORM/Association.php
 */
enum AssociationStrategy: string
{
    case Embed = 'embed';
    case Lookup = 'lookup';
    case Select = 'select';
}

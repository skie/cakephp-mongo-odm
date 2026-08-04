<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Type;

/**
 * BSON-document-as-key map type converter.
 *
 * Stores a PHP associative array as a BSON document, preserving the map
 * semantics (each entry keyed by an arbitrary scalar). Mirrors doctrine's
 * `KeyType`; conversion is identical to `HashType` (array ⇄ `stdClass`) since
 * BSON documents are the native map representation.
 *
 * @see mongodb-odm Types/KeyType.php
 */
class KeyType extends HashType
{
}

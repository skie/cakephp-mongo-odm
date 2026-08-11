<?php
declare(strict_types=1);

/**
 * Ported and adapted from cakephp/bake (MIT License).
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc.
 * @license https://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace Crustum\Mongo\View\Helper;

use Bake\View\Helper\BakeHelper;
use Crustum\Mongo\Database\Schema\CollectionSchema;

/**
 * Mongo-aware bake helper.
 *
 * Extends the cake `BakeHelper` so templates can call `MongoBake.columnData()`
 * and `MongoBake.enumSupportsLabel()` with a `CollectionSchema` instead of a
 * SQL `TableSchema`. Field types are Mongo canonical names.
 */
class MongoBakeHelper extends BakeHelper
{
    /**
     * Get column data from schema.
     *
     * @param string $field Field name.
     * @param \Crustum\Mongo\Database\Schema\CollectionSchema $schema Schema.
     * @return array<string, mixed>|null
     */
    public function columnData(string $field, CollectionSchema $schema): ?array
    {
        return $schema->getColumn($field);
    }

    /**
     * Whether a field's type is an enum with a `label()` method.
     *
     * Mongo enums are detected by the canonical `enum` type name; the baked
     * enum class must expose `label()` to qualify.
     *
     * @param string $field the field to check
     * @param \Crustum\Mongo\Database\Schema\CollectionSchema $schema The collection schema.
     * @return bool
     */
    public function enumSupportsLabel(string $field, CollectionSchema $schema): bool
    {
        $type = $schema->getColumnType($field);

        return $type === 'enum';
    }
}

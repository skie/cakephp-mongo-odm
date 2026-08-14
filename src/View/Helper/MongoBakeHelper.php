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
use Cake\Datasource\SchemaInterface;
use Crustum\Mongo\Database\Schema\CollectionSchema;
use Crustum\Mongo\ODM\Association;
use Crustum\Mongo\ODM\BaseCollection;
use function Cake\Collection\collection;

/**
 * Mongo-aware bake helper.
 *
 * Extends the cake `BakeHelper` (inheriting `filterFields`, `exportVar`,
 * `exportArray`, `getValidationMethods`, …) and adds Mongo-specific methods
 * prefixed `mongo*` so they do not clash with the SQL-typed signatures of the
 * parent (`columnData`, `enumSupportsLabel`, `aliasExtractor`).
 *
 * Templates use `MongoBake.mongoColumnData()` and friends with a
 * `CollectionSchema` / `BaseCollection`.
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
    public function mongoColumnData(string $field, CollectionSchema $schema): ?array
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
    public function mongoEnumSupportsLabel(string $field, CollectionSchema $schema): bool
    {
        return $schema->getColumnType($field) === 'enum';
    }

    /**
     * Returns the aliases of a collection's associations of the given type.
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $collection The collection.
     * @param string $type Association type (BelongsTo, HasMany, …).
     * @return array<int, string>
     */
    public function mongoAliasExtractor(BaseCollection $collection, string $type): array
    {
        $class = 'Crustum\Mongo\ODM\Association\\' . $type;
        if (!class_exists($class) || !is_subclass_of($class, Association::class)) {
            return [];
        }

        return array_map(
            fn($association): string => $association->getTarget()->getAlias(),
            $collection->associations()->getByType($class),
        );
    }

    /**
     * Filters field list, removing fields of the given column types.
     *
     * Mongo-aware counterpart of `BakeHelper::filterFields()` that accepts a
     * `BaseCollection` instead of a `Cake\ORM\Table`.
     *
     * @param array<int, string> $fields Fields list.
     * @param \Cake\Datasource\SchemaInterface $schema Schema instance.
     * @param \Crustum\Mongo\ODM\BaseCollection|null $modelObject Model object.
     * @param string|int $takeFields Take fields.
     * @param array<string> $filterTypes Filter field types.
     * @return array<int, string>
     */
    public function mongoFilterFields(
        array $fields,
        SchemaInterface $schema,
        ?BaseCollection $modelObject = null,
        string|int $takeFields = 0,
        array $filterTypes = ['binary'],
    ): array {
        $fields = collection($fields)
            ->filter(fn(string $field): bool => !in_array($schema->getColumnType($field), $filterTypes, true));

        if (isset($modelObject) && $modelObject->hasBehavior('Tree')) {
            $fields = $fields->reject(fn($field): bool => $field === 'lft' || $field === 'rght');
        }

        if (!empty($takeFields)) {
            $fields = $fields->take((int)$takeFields);
        }

        return $fields->toArray();
    }

    /**
     * Get alias of associated collection.
     *
     * @param \Crustum\Mongo\ODM\BaseCollection $collection The collection.
     * @param string $assoc Association name.
     * @return string
     */
    public function mongoGetAssociatedTableAlias(BaseCollection $collection, string $assoc): string
    {
        $association = $collection->getAssociation($assoc);

        return $association->getTarget()->getAlias();
    }
}

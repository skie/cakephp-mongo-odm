<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Mapping;

use Cake\Utility\Inflector;
use Crustum\Mongo\Database\Schema\CollectionSchema;
use Crustum\Mongo\ODM\Attribute\Document;
use Crustum\Mongo\ODM\Attribute\Field;
use ReflectionClass;

/**
 * Derives a CollectionSchema + TypeMap from a concrete Document class.
 *
 * This is optional sugar. The canonical CakePHP carrier is the Collection +
 * database introspection; `#[Document]` / `#[Field]` attributes on the document
 * class let the collection derive its schema when MongoDB has no validator.
 *
 * `Document` stores values through the `EntityInterface` field map, so field
 * attributes are declared at class level and are repeatable:
 *
 * ```php
 * #[Document(collection: 'users')]
 * #[Field(name: '_id', type: 'objectId', primaryKey: true)]
 * #[Field(name: 'username', type: 'string', nullable: false)]
 * class User extends Document
 * {
 * }
 * ```
 *
 * @see docs/reference/11-entity-type-sugar.md
 */
class DocumentSchemaReader
{
    /**
     * @var array<string, array<string, array<string, mixed>|string>>
     */
    protected static array $cache = [];

    /**
     * Read a Document class into a CollectionSchema.
     *
     * @param class-string $documentClass The Document class to read
     * @param string|null  $name          Collection name; defaults to `#[Document]` collection or tableized basename
     * @return \Crustum\Mongo\Database\Schema\CollectionSchema
     */
    public static function read(string $documentClass, ?string $name = null): CollectionSchema
    {
        $name ??= self::collectionName($documentClass);

        return CollectionSchema::fromFields($name, self::fields($documentClass));
    }

    /**
     * Returns the field definition map for a Document class.
     *
     * @param class-string $documentClass The Document class to read
     * @return array<string, array<string, mixed>|string>
     */
    public static function fields(string $documentClass): array
    {
        if (isset(self::$cache[$documentClass])) {
            return self::$cache[$documentClass];
        }

        $fields = [];
        $reflection = new ReflectionClass($documentClass);

        foreach ($reflection->getAttributes(Field::class) as $attr) {
            /** @var \Crustum\Mongo\ODM\Attribute\Field $fieldAttr */
            $fieldAttr = $attr->newInstance();
            $name = $fieldAttr->name() ?? '';

            if ($name === '') {
                continue;
            }

            $definition = [
                'type' => $fieldAttr->type() ?? 'raw',
                'null' => $fieldAttr->nullable(),
            ];

            if ($fieldAttr->enumType()) {
                $definition['enumType'] = $fieldAttr->enumType();
            }

            $fields[$name] = $definition;
        }

        return self::$cache[$documentClass] = $fields;
    }

    /**
     * Derive a collection name from a Document class and its `#[Document]` attribute.
     *
     * @param class-string $documentClass The Document class
     * @return string
     */
    protected static function collectionName(string $documentClass): string
    {
        $reflection = new ReflectionClass($documentClass);
        $documentAttr = $reflection->getAttributes(Document::class);
        if ($documentAttr !== []) {
            /** @var \Crustum\Mongo\ODM\Attribute\Document $attr */
            $attr = $documentAttr[0]->newInstance();
            if ($attr->collection() !== null) {
                return $attr->collection();
            }
        }

        $basename = substr($documentClass, (int)strrpos($documentClass, '\\') + 1);

        return Inflector::tableize($basename);
    }

    /**
     * Clear the reflection cache.
     *
     * @return void
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }
}

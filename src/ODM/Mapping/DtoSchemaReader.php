<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Mapping;

use BackedEnum;
use Cake\Utility\Inflector;
use Crustum\Mongo\Database\Schema\CollectionSchema;
use Crustum\Mongo\ODM\Attribute\Field;
use DateTimeInterface;
use MongoDB\BSON\Decimal128;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Derives a CollectionSchema + TypeMap from a DTO class.
 *
 * The DTO is the primary schema-definition mechanism (Cake 5.4 style). Every
 * promoted constructor parameter is a field; its type is inferred from the
 * PHP type hint unless a `#[Field]` attribute overrides it.
 *
 * ```php
 * readonly class UserDto
 * {
 *     public function __construct(
 *         #[Field(name: '_id', type: 'objectId')]
 *         public string $id,
 *         public string $username,
 *         public ?\Cake\Chronos\ChronosDate $created = null,
 *     ) {
 *     }
 * }
 * ```
 *
 * @see docs/reference/05-mongo-schema-loading-plan.md
 */
class DtoSchemaReader
{
    /**
     * @var array<string, array<string, array<string, mixed>|string>>
     */
    protected static array $cache = [];

    /**
     * Read a DTO class into a CollectionSchema.
     *
     * @param class-string $dtoClass The DTO class to read
     * @param string|null  $name     Collection name; defaults to tableized class basename
     * @return \Crustum\Mongo\Database\Schema\CollectionSchema
     */
    public static function read(string $dtoClass, ?string $name = null): CollectionSchema
    {
        $name ??= self::collectionName($dtoClass);

        return CollectionSchema::fromFields($name, self::fields($dtoClass));
    }

    /**
     * Returns the field definition map for a DTO class.
     *
     * @param class-string $dtoClass The DTO class to read
     * @return array<string, array<string, mixed>|string>
     */
    public static function fields(string $dtoClass): array
    {
        if (isset(self::$cache[$dtoClass])) {
            return self::$cache[$dtoClass];
        }

        $fields = [];
        $reflection = new ReflectionClass($dtoClass);
        $constructor = $reflection->getConstructor();

        if ($constructor !== null) {
            foreach ($constructor->getParameters() as $param) {
                $fields += self::paramToField($param);
            }
        }

        return self::$cache[$dtoClass] = $fields;
    }

    /**
     * Build a single field definition from a constructor parameter.
     *
     * @param \ReflectionParameter $param The parameter to analyze
     * @return array<string, array<string, mixed>|string>
     */
    protected static function paramToField(ReflectionParameter $param): array
    {
        $fieldAttr = null;
        foreach ($param->getAttributes(Field::class) as $attr) {
            /** @var \Crustum\Mongo\ODM\Attribute\Field $fieldAttr */
            $fieldAttr = $attr->newInstance();
        }

        $type = $fieldAttr?->type() ?? self::inferType($param);
        $name = $fieldAttr?->name() ?? $param->getName();

        $definition = [
            'type' => $type,
            'null' => $param->allowsNull(),
        ];

        if ($type === 'enum' && $fieldAttr?->enumType()) {
            $definition['enumType'] = $fieldAttr->enumType();
        }

        return [$name => $definition];
    }

    /**
     * Infer a TypeFactory name from the parameter type hint.
     *
     * @param \ReflectionParameter $param The parameter to analyze
     * @return string
     */
    protected static function inferType(ReflectionParameter $param): string
    {
        $type = $param->getType();

        if ($type instanceof ReflectionNamedType) {
            if ($type->isBuiltin()) {
                return match ($type->getName()) {
                    'string' => 'string',
                    'int' => 'integer',
                    'float' => 'float',
                    'bool' => 'boolean',
                    'array' => 'collection',
                    'mixed' => 'raw',
                    default => 'raw',
                };
            }

            $className = $type->getName();
            if (is_a($className, BackedEnum::class, true)) {
                return 'enum';
            }

            if (is_a($className, ObjectId::class, true)) {
                return 'objectid';
            }

            if (is_a($className, Decimal128::class, true)) {
                return 'decimal128';
            }

            if (is_a($className, UTCDateTime::class, true) || is_a($className, DateTimeInterface::class, true)) {
                return 'date';
            }

            return 'object';
        }

        return 'raw';
    }

    /**
     * Derive a collection name from a DTO class name.
     *
     * @param class-string $dtoClass The DTO class
     * @return string
     */
    protected static function collectionName(string $dtoClass): string
    {
        $basename = substr($dtoClass, (int)strrpos($dtoClass, '\\') + 1);

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

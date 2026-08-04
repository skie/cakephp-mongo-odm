<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Type;

/**
 * Factory for building MongoDB type classes
 */
class TypeFactory
{
    /**
     * Default type map - restored after clear()
     *
     * @var array<string, string>
     * @phpstan-var array<string, class-string<\Crustum\Mongo\Database\Type\TypeInterface>>
     */
    protected static array $defaultTypes = [
        'objectid' => ObjectIdType::class,
        'object_id' => ObjectIdType::class,
        'id' => IdType::class,
        'date' => DateType::class,
        'datetime' => DateType::class,
        'timestamp' => TimestampType::class,
        'decimal128' => Decimal128Type::class,
        'decimal' => Decimal128Type::class,
        'binary' => BinaryType::class,
        'bin_uuid' => BinaryUuidType::class,
        'bin_md5' => BinaryMd5Type::class,
        'string' => StringType::class,
        'integer' => IntegerType::class,
        'int' => IntegerType::class,
        'float' => FloatType::class,
        'boolean' => BooleanType::class,
        'bool' => BooleanType::class,
        'array' => ArrayType::class,
        'hash' => HashType::class,
        'collection' => CollectionType::class,
        'raw' => RawType::class,
        'key' => KeyType::class,
        'keytype' => KeyType::class,
        'vector_float32' => VectorFloat32Type::class,
        'vector_int8' => VectorInt8Type::class,
        'vector_packed_bit' => VectorPackedBitType::class,
    ];

    /**
     * List of supported database types
     *
     * A human-readable identifier is used as key and a complete namespaced class name
     * as value representing the class that will do actual type conversions
     *
     * @var array<string, string>
     * @phpstan-var array<string, class-string<\Crustum\Mongo\Database\Type\TypeInterface>>
     */
    protected static array $types = [];

    /**
     * Contains a map of type object instances to be reused if needed
     *
     * @var array<\Crustum\Mongo\Database\Type\TypeInterface>
     */
    protected static array $builtTypes = [];

    /**
     * Returns a Type object capable of converting a type identified by name
     *
     * @param string $name Type identifier
     * @return \Crustum\Mongo\Database\Type\TypeInterface
     */
    public static function build(string $name): TypeInterface
    {
        static::initializeTypes();

        if (isset(static::$builtTypes[$name])) {
            return static::$builtTypes[$name];
        }

        if (!isset(static::$types[$name])) {
            return static::$builtTypes[$name] = new StringType($name);
        }

        $className = static::$types[$name];

        return static::$builtTypes[$name] = new $className($name);
    }

    /**
     * Returns an array with all the mapped type objects, indexed by name
     *
     * @return array<\Crustum\Mongo\Database\Type\TypeInterface>
     */
    public static function buildAll(): array
    {
        static::initializeTypes();

        foreach (array_keys(static::$types) as $name) {
            static::$builtTypes[$name] ??= static::build($name);
        }

        return static::$builtTypes;
    }

    /**
     * Set TypeInterface instance capable of converting a type identified by $name
     *
     * @param string $name The type identifier you want to set
     * @param \Crustum\Mongo\Database\Type\TypeInterface $instance The type instance you want to set
     * @return void
     */
    public static function set(string $name, TypeInterface $instance): void
    {
        static::$builtTypes[$name] = $instance;
    }

    /**
     * Registers a new type identifier and maps it to a fully namespaced classname
     *
     * @param string $type Name of type to map
     * @param string $className The classname to register
     * @return void
     * @phpstan-param class-string<\Crustum\Mongo\Database\Type\TypeInterface> $className
     */
    public static function map(string $type, string $className): void
    {
        static::$types[$type] = $className;
        unset(static::$builtTypes[$type]);
    }

    /**
     * Set type to classname mapping
     *
     * @param array<string, string> $map List of types to be mapped
     * @return void
     * @phpstan-param array<string, class-string<\Crustum\Mongo\Database\Type\TypeInterface>> $map
     */
    public static function setMap(array $map): void
    {
        static::$types = $map;
        static::$builtTypes = [];
    }

    /**
     * Get the type mapping array
     *
     * @return array<string, class-string<\Crustum\Mongo\Database\Type\TypeInterface>>
     */
    public static function getMap(): array
    {
        static::initializeTypes();

        return static::$types;
    }

    /**
     * Get mapped class name for a specific type
     *
     * @param string $type Type name to get mapped class for
     * @return class-string<\Crustum\Mongo\Database\Type\TypeInterface>|null Configured class name for given $type or null if not found
     */
    public static function getMapped(string $type): ?string
    {
        static::initializeTypes();

        return static::$types[$type] ?? null;
    }

    /**
     * Initialize types from default types
     *
     * @return void
     */
    protected static function initializeTypes(): void
    {
        if (static::$types === []) {
            static::$types = static::$defaultTypes;
        }
    }

    /**
     * Clears out all created instances and mapped types classes, useful for testing
     *
     * @return void
     */
    public static function clear(): void
    {
        static::$types = [];
        static::$builtTypes = [];
    }
}

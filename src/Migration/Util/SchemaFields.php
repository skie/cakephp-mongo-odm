<?php
declare(strict_types=1);

namespace Crustum\Mongo\Migration\Util;

use Crustum\Mongo\Database\Connection;
use Crustum\Mongo\Migration\SchemaDumper;

/**
 * Extracts field definitions from a schema (live or lock file) so they can be
 * baked as `#[Field]` attributes on Document classes.
 */
class SchemaFields
{
    /**
     * Reads field definitions from the schema dump lock file.
     *
     * @param string $file Path to `schema-dump-mongo.lock`
     * @param string $collection Collection name
     * @return array<string, array{bsonType: string, nullable: bool, enum: list<mixed>|null}> Field definitions keyed by field name
     */
    public static function fromLockFile(string $file, string $collection): array
    {
        if (!file_exists($file)) {
            return [];
        }

        $contents = file_get_contents($file);
        $schema = $contents !== false ? unserialize($contents) : false;
        if (!is_array($schema)) {
            return [];
        }

        return self::fromSchema($schema, $collection);
    }

    /**
     * Reads field definitions from a live connection.
     *
     * @param \Crustum\Mongo\Database\Connection $connection The connection
     * @param string $collection Collection name
     * @return array<string, array{bsonType: string, nullable: bool, enum: list<mixed>|null}> Field definitions keyed by field name
     */
    public static function fromConnection(Connection $connection, string $collection): array
    {
        $dumper = new SchemaDumper($connection);
        $schema = $dumper->dumpAll();

        return self::fromSchema($schema, $collection);
    }

    /**
     * Extracts fields for a collection from a dumped schema map.
     *
     * @param array<string, array<string, mixed>> $schema The dumped schema map
     * @param string $collection Collection name
     * @return array<string, array{bsonType: string, nullable: bool, enum: list<mixed>|null}> Field definitions keyed by field name
     */
    public static function fromSchema(array $schema, string $collection): array
    {
        $jsonSchema = $schema[$collection]['validator']['$jsonSchema'] ?? null;
        if (!is_array($jsonSchema) || !isset($jsonSchema['properties'])) {
            return [];
        }

        $required = array_map(strval(...), (array)($jsonSchema['required'] ?? []));

        $fields = [];
        $properties = $jsonSchema['properties'];
        foreach ($properties as $name => $definition) {
            if (!is_array($definition)) {
                continue;
            }

            $bsonTypes = $definition['bsonType'] ?? ['string'];
            if (!is_array($bsonTypes)) {
                $bsonTypes = [$bsonTypes];
            }

            $nullable = in_array('null', $bsonTypes, true) || !in_array($name, $required, true);
            $nonNullTypes = array_values(array_filter(
                $bsonTypes,
                static fn(mixed $type): bool => is_string($type) && $type !== 'null',
            ));
            $primary = $nonNullTypes[0] ?? 'string';

            $fields[$name] = [
                'bsonType' => $primary,
                'nullable' => $nullable,
                'enum' => isset($definition['enum']) && is_array($definition['enum'])
                    ? array_values($definition['enum'])
                    : null,
            ];
        }

        return $fields;
    }

    /**
     * Maps a BSON type spelling to a canonical TypeFactory type name.
     *
     * @param string $bsonType BSON type spelling
     * @return string Canonical type name
     */
    public static function typeName(string $bsonType): string
    {
        return match ($bsonType) {
            'objectId' => 'objectid',
            'bool' => 'boolean',
            'int' => 'integer',
            'long' => 'int64',
            'double' => 'float',
            'decimal' => 'decimal128',
            'date' => 'date',
            'timestamp' => 'timestamp',
            'array' => 'collection',
            'object' => 'hash',
            'binData' => 'binary',
            default => 'string',
        };
    }

    /**
     * Returns the `CollectionSchemaInterface::TYPE_*` constant name for a
     * canonical type, or null when no constant exists.
     *
     * @param string $type Canonical TypeFactory type name
     * @return string|null The constant name (e.g. `TYPE_STRING`), or null
     */
    public static function typeConstant(string $type): ?string
    {
        $map = [
            'objectid' => 'TYPE_OBJECTID',
            'id' => 'TYPE_ID',
            'date' => 'TYPE_DATE',
            'datetime' => 'TYPE_DATETIME',
            'timestamp' => 'TYPE_TIMESTAMP',
            'date_immutable' => 'TYPE_DATE_IMMUTABLE',
            'decimal128' => 'TYPE_DECIMAL',
            'binary' => 'TYPE_BINARY',
            'bin_uuid' => 'TYPE_BINARY_UUID',
            'bin_uuid_rfc4122' => 'TYPE_BINARY_UUID_RFC4122',
            'bin_md5' => 'TYPE_BINARY_MD5',
            'bin_func' => 'TYPE_BINARY_FUNC',
            'bin_bytearray' => 'TYPE_BINARY_BYTEARRAY',
            'bin_custom' => 'TYPE_BINARY_CUSTOM',
            'string' => 'TYPE_STRING',
            'uuid' => 'TYPE_UUID',
            'time' => 'TYPE_TIME',
            'json' => 'TYPE_JSON',
            'integer' => 'TYPE_INTEGER',
            'int64' => 'TYPE_INT64',
            'float' => 'TYPE_FLOAT',
            'boolean' => 'TYPE_BOOLEAN',
            'array' => 'TYPE_ARRAY',
            'hash' => 'TYPE_HASH',
            'collection' => 'TYPE_COLLECTION',
            'raw' => 'TYPE_RAW',
            'key' => 'TYPE_KEY',
            'vector_float32' => 'TYPE_VECTOR_FLOAT32',
            'vector_int8' => 'TYPE_VECTOR_INT8',
            'vector_packed_bit' => 'TYPE_VECTOR_PACKED_BIT',
        ];

        return $map[$type] ?? null;
    }
}

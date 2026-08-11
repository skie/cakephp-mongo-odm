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
     * @return array<string, array{bsonType: string}> Field definitions keyed by field name
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
     * @return array<string, array{bsonType: string}> Field definitions keyed by field name
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
     * @return array<string, array{bsonType: string}> Field definitions keyed by field name
     */
    public static function fromSchema(array $schema, string $collection): array
    {
        if (!isset($schema[$collection]['validator']['$jsonSchema']['properties'])) {
            return [];
        }

        $fields = [];
        $properties = $schema[$collection]['validator']['$jsonSchema']['properties'];
        foreach ($properties as $name => $definition) {
            if (!is_array($definition)) {
                continue;
            }

            $bsonType = $definition['bsonType'] ?? 'string';
            $fields[$name] = ['bsonType' => $bsonType];
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
            'objectId' => 'objectId',
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
}

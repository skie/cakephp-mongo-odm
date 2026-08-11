<?php
declare(strict_types=1);

/**
 * Ported and adapted from cakephp/migrations (MIT License).
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc.
 * @license https://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace Crustum\Mongo\Migration\Util;

use Cake\Utility\Inflector;

/**
 * Parses `bake mongo_migration` column arguments into field definitions.
 *
 * Supports the cake migration column grammar adapted for Mongo types:
 * `name:string`, `age:int?`, `slug:string[100]`, `active:boolean:default[true]`,
 * `email:string:unique`.
 */
class ColumnParser
{
    /**
     * Regex used to parse the column definition passed through the shell.
     */
    protected string $regexpParseColumn = '/
        ^
        (\w+)
        (?::(\w+\??
            (?:\[
                (?:[0-9]|[1-9][0-9]+)
                (?:,(?:[0-9]|[1-9][0-9]+))?
            \])?
        ))?
        (?::default\[([^\]]+)\])?
        (?::(\w+))?
        (?::(\w+))?
        $
        /x';

    /**
     * Regex used to parse the field type and length.
     */
    protected string $regexpParseField = '/(\w+\??)\[([0-9,]+)\]/';

    /**
     * Maps cake abstract types to Mongo canonical type names.
     *
     * @var array<string, string>
     */
    protected array $typeMap = [
        'string' => 'string',
        'char' => 'string',
        'text' => 'string',
        'uuid' => 'string',
        'objectid' => 'objectid',
        'id' => 'objectid',
        'integer' => 'integer',
        'int' => 'integer',
        'tinyinteger' => 'int',
        'smallinteger' => 'int',
        'biginteger' => 'int64',
        'float' => 'float',
        'double' => 'float',
        'decimal' => 'decimal128',
        'decimal128' => 'decimal128',
        'boolean' => 'boolean',
        'bool' => 'boolean',
        'datetime' => 'date',
        'timestamp' => 'timestamp',
        'date' => 'date',
        'time' => 'date',
        'binary' => 'binary',
        'bin_uuid' => 'bin_uuid',
        'json' => 'hash',
        'hash' => 'hash',
        'array' => 'array',
        'collection' => 'collection',
        'raw' => 'raw',
    ];

    /**
     * Default field type when none is given.
     *
     * @var string
     */
    protected string $defaultType = 'string';

    /**
     * Parses a list of arguments into an array of fields.
     *
     * @param array<int, string> $arguments A list of arguments being parsed
     * @return array<string, array{type: string, null: bool, default?: mixed, length?: int, unique?: bool}>
     */
    public function parseFields(array $arguments): array
    {
        $fields = [];
        foreach ($this->validArguments($arguments) as $field) {
            preg_match($this->regexpParseColumn, $field, $matches);
            $name = $matches[1];
            $type = $matches[2] ?? null;
            $defaultValue = $matches[3] ?? null;
            $indexType = $matches[4] ?? null;

            $nullable = $type !== null && str_contains($type, '?');
            $type = $nullable ? str_replace('?', '', $type) : $type;
            $type = $type === '' || $type === null ? null : $type;

            [$type, $length] = $this->getTypeAndLength($name, $type);

            $definition = [
                'type' => $this->mapType($type),
                'null' => $nullable,
            ];

            if ($length !== null) {
                if (is_array($length)) {
                    $definition['precision'] = $length[0];
                } else {
                    $definition['length'] = $length;
                }
            }

            if ($defaultValue !== null && $defaultValue !== '') {
                $definition['default'] = $this->parseDefaultValue($defaultValue, $type);
            }

            if ($indexType !== null) {
                $definition['unique'] = $indexType === 'unique';
            }

            $fields[$name] = $definition;
        }

        return $fields;
    }

    /**
     * Parses a list of arguments into an array of index names.
     *
     * @param array<int, string> $arguments A list of arguments being parsed
     * @return array<string, array{key: array<string, int>, unique: bool}>
     */
    public function parseIndexes(array $arguments): array
    {
        $indexes = [];
        foreach ($this->validArguments($arguments) as $field) {
            preg_match($this->regexpParseColumn, $field, $matches);
            $name = $matches[1];
            $indexType = $matches[4] ?? null;

            if ($indexType !== 'unique') {
                continue;
            }

            $indexes['unique_' . $name] = [
                'key' => [$name => 1],
                'unique' => true,
            ];
        }

        return $indexes;
    }

    /**
     * Returns a list of only valid arguments.
     *
     * @param array<int, string> $arguments A list of arguments
     * @return array<int, string>
     */
    public function validArguments(array $arguments): array
    {
        return array_values(array_filter(
            $arguments,
            fn(string $value): bool => (bool)preg_match($this->regexpParseColumn, $value),
        ));
    }

    /**
     * Get the type and length of a field.
     *
     * @param string $field Name of field
     * @param string|null $type User-specified type
     * @return array{0: string|null, 1: int|array<int>|null}
     */
    public function getTypeAndLength(string $field, ?string $type): array
    {
        if ($type !== null && preg_match($this->regexpParseField, $type, $matches)) {
            $length = $matches[2];
            $length = str_contains($length, ',')
                ? array_map(intval(...), explode(',', $length))
                : (int)$length;

            return [$matches[1], $length];
        }

        return [$this->getType($field, $type), $this->getLength($type)];
    }

    /**
     * Retrieves the type that should be used for a specific field.
     *
     * @param string $field Name of field
     * @param string|null $type User-specified type
     * @return string|null
     */
    public function getType(string $field, ?string $type): ?string
    {
        if ($type !== null && isset($this->typeMap[$type])) {
            return $type;
        }
        if ($type !== null) {
            return $type;
        }

        if ($field === 'id' || str_ends_with($field, '_id')) {
            return 'objectid';
        }
        if (in_array($field, ['created', 'modified', 'updated'], true)) {
            return 'datetime';
        }
        if (in_array($field, ['latitude', 'longitude', 'lat', 'lng'], true)) {
            return 'decimal';
        }

        return $this->defaultType;
    }

    /**
     * Returns the default length to be used for a given type.
     *
     * @param string|null $type User-specified type
     * @return array<int>|int|null
     */
    public function getLength(?string $type): int|array|null
    {
        return match ($type) {
            'string' => 255,
            'tinyinteger' => 4,
            'smallinteger' => 6,
            'integer' => 11,
            'biginteger' => 20,
            'decimal' => [10, 6],
            default => null,
        };
    }

    /**
     * Maps a cake/BSON type spelling to the canonical Mongo type name.
     *
     * @param string|null $type Type name
     * @return string Canonical Mongo type name
     */
    public function mapType(?string $type): string
    {
        if ($type === null) {
            return $this->defaultType;
        }

        return $this->typeMap[strtolower($type)] ?? $this->defaultType;
    }

    /**
     * Parses a default value string into the appropriate PHP type.
     *
     * @param string $value The raw default value from the command line
     * @param string|null $type The field type
     * @return mixed The parsed default value
     */
    public function parseDefaultValue(string $value, ?string $type): mixed
    {
        $lower = strtolower($value);

        if ($lower === 'null') {
            return null;
        }
        if ($lower === 'true') {
            return true;
        }
        if ($lower === 'false') {
            return false;
        }
        if (
            (str_starts_with($value, "'") && str_ends_with($value, "'")) ||
            (str_starts_with($value, '"') && str_ends_with($value, '"'))
        ) {
            return substr($value, 1, -1);
        }
        if (preg_match('/^-?\d+$/', $value)) {
            return (int)$value;
        }
        if (preg_match('/^-?\d+\.\d+$/', $value)) {
            return (float)$value;
        }

        return $value;
    }

    /**
     * Pluralizes a field name (used to infer a collection from `*_id`).
     *
     * @param string $field Field name
     * @return string Collection name
     */
    public function collectionFor(string $field): string
    {
        $base = (string)preg_replace('/_id$/', '', $field);

        return Inflector::pluralize($base);
    }
}

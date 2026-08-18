<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Schema;

use Cake\Datasource\SchemaInterface;

/**
 * Schema interface for MongoDB collections.
 *
 * Mirrors `Cake\Database\Schema\TableSchemaInterface`: the `TYPE_*` constants
 * are the canonical `TypeFactory` names used in `#[Field(type: ...)]`
 * attributes, `CollectionSchema::addField()`, and schema definitions.
 */
interface CollectionSchemaInterface extends SchemaInterface
{
    public const string TYPE_OBJECTID = 'objectid';

    public const string TYPE_ID = 'id';

    public const string TYPE_DATE = 'date';

    public const string TYPE_DATETIME = 'datetime';

    public const string TYPE_TIMESTAMP = 'timestamp';

    public const string TYPE_DATE_IMMUTABLE = 'date_immutable';

    public const string TYPE_DECIMAL = 'decimal128';

    public const string TYPE_BINARY = 'binary';

    public const string TYPE_BINARY_UUID = 'bin_uuid';

    public const string TYPE_BINARY_UUID_RFC4122 = 'bin_uuid_rfc4122';

    public const string TYPE_BINARY_MD5 = 'bin_md5';

    public const string TYPE_BINARY_FUNC = 'bin_func';

    public const string TYPE_BINARY_BYTEARRAY = 'bin_bytearray';

    public const string TYPE_BINARY_CUSTOM = 'bin_custom';

    public const string TYPE_STRING = 'string';

    public const string TYPE_TEXT = 'text';

    public const string TYPE_UUID = 'uuid';

    public const string TYPE_TIME = 'time';

    public const string TYPE_JSON = 'json';

    public const string TYPE_INTEGER = 'integer';

    public const string TYPE_INT64 = 'int64';

    public const string TYPE_FLOAT = 'float';

    public const string TYPE_BOOLEAN = 'boolean';

    public const string TYPE_ARRAY = 'array';

    public const string TYPE_HASH = 'hash';

    public const string TYPE_COLLECTION = 'collection';

    public const string TYPE_RAW = 'raw';

    public const string TYPE_KEY = 'key';

    public const string TYPE_VECTOR_FLOAT32 = 'vector_float32';

    public const string TYPE_VECTOR_INT8 = 'vector_int8';

    public const string TYPE_VECTOR_PACKED_BIT = 'vector_packed_bit';

    /**
     * Returns the collection primary key.
     *
     * MongoDB creates `_id` automatically.
     *
     * @return string
     */
    public function primaryKey(): string;

    /**
     * Returns all indexes keyed by index name.
     *
     * @return array<string, \Crustum\Mongo\Database\Schema\Index>
     */
    public function indexes(): array;
}

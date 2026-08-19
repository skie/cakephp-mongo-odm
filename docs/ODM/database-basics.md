# Database Basics

The `Crustum/Mongo` plugin's database access layer (`Crustum\Mongo\Database`) abstracts and
provides help with most aspects of dealing with MongoDB: keeping connections to
the server, building queries, preventing injection through typed value casting,
inspecting and altering schemas, and debugging and profiling queries sent to the
database.

It mirrors the structure of `Cake\Database` but **executes MongoDB commands**
(find/aggregate/insert/update/delete) instead of SQL. There is no SQL, no PDO
and no `StatementInterface`; the SQL-driver-specific parts of the Cake
documentation are therefore not applicable and are skipped here.

## Quick Tour

The functions described in this chapter illustrate what is possible to do with
the lower-level database access API. If instead you want to learn more about the
complete ODM, you can read the [Query Builder](query-builder.md) and
[Collection Objects](collections.md) sections.

The easiest way to create a database connection is using a connection string:

```php
use Cake\Datasource\ConnectionManager;
use Crustum\Mongo\Database\Connection;

ConnectionManager::setConfig('default', [
    'className' => Connection::class,
    'url' => 'mongodb://root:password@localhost/my_database',
]);
```

Once created, you can access the connection object to start using it:

```php
$connection = ConnectionManager::get('default');
```

### Running Select Statements

Select queries are built with the query builder. Nothing is sent to MongoDB
until the query is executed or iterated:

```php
$results = $connection
    ->selectQuery()
    ->from('articles')
    ->where(['created >' => new DateTime('1 day ago')], ['created' => 'datetime'])
    ->orderBy(['title' => 'DESC'])
    ->execute();

foreach ($results as $row) {
    // $row is an associative array.
}
```

You can use complex data types as arguments. Values in `where()` are cast
through the type map before reaching Mongo, so a `DateTimeInterface` becomes a
`MongoDB\BSON\UTCDateTime`:

```php
use DateTime;

$results = $connection
    ->selectQuery()
    ->from('articles')
    ->where(['created >' => new DateTime('1 day ago')], ['created' => 'datetime'])
    ->all();
```

To read the results as a result set instead of iterating the cursor, use
`all()`. The returned `Crustum\Mongo\Database\ResultSet` is a
`Cake\Collection\Collection`, so you can use collection methods on it:

```php
$rows = $query->all();                    // ResultSetInterface
$first = $query->all()->first();
$total = $query->all()->count();
```

> [!NOTE]
> Instead of iterating the `$query` you can also call its `all()` method to get
> the results.

### Running Insert Statements

Inserting documents in the database is usually a matter of a couple of lines:

```php
use Cake\Datasource\ConnectionManager;
use DateTime;

$connection = ConnectionManager::get('default');
$connection->insertQuery('articles', [
    'title' => 'A New Article',
    'created' => new DateTime('now'),
], ['created' => 'datetime']);
```

`insertQuery()` returns an `InsertQuery`. Calling `execute()` performs the
insert and returns the inserted `_id` values as strings. If you do not supply
`_id`, MongoDB generates one for you.

### Running Update Statements

Updating documents is equally intuitive. The following example updates the
article with **`_id`** `00000000000000000000000a`:

```php
use Cake\Datasource\ConnectionManager;

$connection = ConnectionManager::get('default');
$connection->updateQuery('articles', [
    'title' => 'New title',
], ['_id' => '00000000000000000000000a']);
```

Updates compile to a Mongo `updateMany` with update operators (`$set` by
default). `execute()` returns the number of modified documents.

### Running Delete Statements

Similarly, the `deleteQuery()` method is used to delete documents from the
database. The following example deletes the article with **`_id`**
`00000000000000000000000a`:

```php
use Cake\Datasource\ConnectionManager;

$connection = ConnectionManager::get('default');
$connection->deleteQuery('articles', ['_id' => '00000000000000000000000a']);
```

`execute()` returns the number of deleted documents.

## Configuration

By convention database connections are configured in **config/app.php**. The
connection information defined in this file is fed into
`Cake\Datasource\ConnectionManager`, creating the connection configuration your
application will be using. A sample connection configuration would look like:

```php
'Datasources' => [
    'default' => [
        'className' => 'Crustum\Mongo\Database\Connection',
        'driver' => 'Crustum\Mongo\Database\Driver\MongoDriver',
        'host' => 'localhost',
        'port' => 27017,
        'username' => 'my_app',
        'password' => 'secret',
        'database' => 'my_app',
        'cacheMetadata' => true,
        'log' => false,
    ],
],
```

The above will create a 'default' connection with the provided parameters. You
can define as many connections as you want in your configuration file. You can
also define additional connections at runtime using
`Cake\Datasource\ConnectionManager::setConfig()`. An example of that would be:

```php
use Cake\Datasource\ConnectionManager;

ConnectionManager::setConfig('default', [
    'className' => 'Crustum\Mongo\Database\Connection',
    'driver' => 'Crustum\Mongo\Database\Driver\MongoDriver',
    'host' => 'localhost',
    'port' => 27017,
    'username' => 'my_app',
    'password' => 'secret',
    'database' => 'my_app',
    'cacheMetadata' => true,
]);
```

Configuration options can also be provided as a connection string. This is
useful when working with environment variables or `PaaS` providers:

```php
ConnectionManager::setConfig('default', [
    'url' => 'mongodb://my_app:sekret@localhost/my_app',
]);
```

When using a connection string you can define any additional driver parameters
and options as URI query arguments (they are forwarded to the MongoDB client
options).

By default, all Collection objects will use the `default` connection. To use a
non-default connection, see [Configuring Collection Connections](collections.md#configuring-connections).

There are a number of keys supported in database configuration. A full list is
as follows:

- `className`: The fully namespaced class name of the class that represents the
  connection to a MongoDB server. This class is responsible for loading the
  database driver, providing transaction mechanisms and dispatching compiled
  queries.

- `driver`: The class name of the driver used to implement all specifics of
  MongoDB, or a constructed driver instance. The default is
  `Crustum\Mongo\Database\Driver\MongoDriver`.

- `host`: The database server's hostname (or IP address). Defaults to
  `localhost`.

- `port`: The TCP port used to connect to the server. Defaults to `27017`.

- `username` / `password`: The credentials for the account. When either is set,
  they are URL-encoded into the `mongodb://` connection string.

- `database`: **Required.** The name of the database for this connection to
  use. The driver throws if this key is missing.

- `url`: A full `mongodb://` connection string. When set, it is used verbatim
  instead of assembling one from `host`/`port`/`username`/`password`.

- `options`: An associative array of options passed to the MongoDB client
  constructor — TLS settings, `readPreference`, `retryWrites`, `replicaSet`,
  and any other `MongoDB\Client` option. When the read role is active, a
  `readPreference` of `secondaryPreferred` is injected unless one is pinned
  here.

- `log`: Set to `true`, a logger class name, or a `Psr\Log\LoggerInterface`
  instance to enable query logging. When enabled, commands are logged at a
  `debug` level with the `mongoQueriesLog` / `mongo.database.queries` scopes.

- `logJsonFlags`: A bitmask of `json_encode()` flags used when formatting
  commands for the query log (e.g. `JSON_PRETTY_PRINT` for multi-line output).

- `logSchemaCommands`: Set to `false` to skip catalog/reflection commands
  (`listCollections`, `listIndexes`, `collStats`, ...) in the query log.

- `cacheMetadata`: Either boolean `true`, or a string containing the cache
  configuration to store metadata in. See the
  [Metadata Cache](#metadata-caching) section for more information.

- `read` / `write`: Role sub-configurations (see
  [Read and Write Connections](#read-and-write-connections)).

There is no `persistent`, `encoding`, `timezone`, `quoteIdentifiers`, `flags`,
`init`, `schema`, or `unix_socket` — those are SQL-driver concepts and do not
apply to MongoDB.

At this point, you might want to take a look at the
[CakePHP Conventions](../intro/conventions). The correct naming for your
collections (and the addition of some fields) can score you free functionality
and help you avoid configuration. For example, if you name your collection
`big_boxes`, your collection class `BigBoxesCollection`, and your controller
`BigBoxesController`, everything will work together automatically. By
convention, use underscores, lower case, and plural forms for your collection
names — for example: `bakers`, `pastry_stores`, and `savory_cakes`.

## Read and Write Connections

Connections can have separate read and write roles. Read roles are expected to
represent read-only replicas and write roles are expected to be the default
connection and support write operations.

Read roles are configured by providing a `read` key in the connection config.
Write roles are configured by providing a `write` key.

Role configurations override the values in the shared connection config. If the
read and write role configurations are the same, a single connection to the
database is used for both:

```php
'default' => [
    'driver' => 'Crustum\Mongo\Database\Driver\MongoDriver',
    'username' => '...',
    'password' => '...',
    'database' => '...',
    'read' => [
        'host' => 'read-db.example.com',
    ],
    'write' => [
        'host' => 'write-db.example.com',
    ],
];
```

You can specify the same value for both `read` and `write` keys without creating
multiple connections to the database.

The read driver emits `readPreference: secondaryPreferred` (replica-set
secondary reads) unless the config already pins one. Queries run through the
write driver by default; route them to the read role explicitly:

```php
$query = $connection->selectQuery()->from('articles')->useReadRole();
$query->useWriteRole(); // back to the primary
```

## Managing Connections

`class` Crustum\\Mongo\\Database\\**Connection**

The `ConnectionManager` class acts as a registry to access database connections
your application has. It provides a place that other objects can get references
to existing connections.

### Accessing Connections

`static` Cake\\Datasource\\ConnectionManager::**get**(string $name): ConnectionInterface

Once configured connections can be fetched using
`Cake\Datasource\ConnectionManager::get()`. This method will construct and load
a connection if it has not been built before, or return the existing known
connection:

```php
use Cake\Datasource\ConnectionManager;

$connection = ConnectionManager::get('default');
```

Attempting to load connections that do not exist will throw an exception.

### Creating Connections at Runtime

Using `setConfig()` and `get()` you can create new connections that are not
defined in your configuration files at runtime:

```php
ConnectionManager::setConfig('my_connection', $config);
$connection = ConnectionManager::get('my_connection');
```

See the [Configuration](#configuration) section for more information on the
configuration data used when creating connections.

## Data Types

`class` Crustum\\Mongo\\Database\\Type\\**TypeFactory**

MongoDB has its own type system, so the abstracted types differ from Cake's SQL
types. Crustum provides a set of abstracted data types for use with the
database layer. The types supported are:

objectid
: Maps to `MongoDB\BSON\ObjectId`. Used for `_id` and references to other
  collection documents.

id
: A generic identifier converter. It behaves as a pass-through for scalar
  values.

date, datetime
: Maps to `MongoDB\BSON\UTCDateTime`. See [Date & Time Types](#date--time-types).

timestamp
: Maps to `MongoDB\BSON\Timestamp` (the Mongo server timestamp type, not a
  date). See [Date & Time Types](#date--time-types).

decimal128, decimal
: Maps to `MongoDB\BSON\Decimal128`, which provides exact decimal arithmetic.
  Values are represented as `Decimal128` objects (not PHP floats) to avoid
  precision loss.

binary
: Maps to `MongoDB\BSON\Binary` with the generic subtype.

bin_uuid, bin_uuid_rfc4122, bin_md5, bin_func, bin_bytearray, bin_custom
: Maps to `MongoDB\BSON\Binary` with the corresponding BSON binary subtype
  (`UUID`, `UUID` RFC 4122, `MD5`, `FUNCTION`, byte-array and custom
  subtypes). Used for the same storage-efficient binary encoding Cake uses for
  binary UUIDs.

string
: Maps to a BSON string.

uuid, nativeuuid
: Maps to the UUID type. Stored as a BSON string (or a binary subtype when
  `bin_uuid` is used).

time
: Maps to a BSON time/date value.

json
: Maps to a BSON document.

integer, int
: Maps to a BSON 32-bit integer.

int64
: Maps to a BSON 64-bit integer (`long`).

autoincrement, auto_increment
: A counter type used by the ODM for monotonically increasing integer `_id`
  values (Mongo has no auto-increment; this emulates it).

float
: Maps to a BSON double.

boolean, bool
: Maps to a BSON bool.

array
: A list value.

hash
: An object/associative-document value.

collection
: An array of documents.

raw
: A passthrough type — the value is sent to MongoDB as-is, without casting.

key
: A key type (used for `_id` generation keys).

vector_float32, vector_int8, vector_packed_bit
: Vector types used by Atlas Vector Search. See [Geospatial Types](#geospatial-types).

enum
: See [Enum Type](#enum-type).

These types are used in both the schema reflection features that the plugin
provides and the schema generation features it uses when defining test fixtures.

Each type also provides translation functions between PHP and BSON
representations. These methods are invoked based on the type hints provided
when doing queries. For example, a field that is marked as `datetime` will
automatically convert input parameters from `DateTimeInterface` instances into
`UTCDateTime` values. Likewise, `binary` fields will accept `Binary` objects
and generate them when reading data.

### Date & Time Types

`class` Crustum\\Mongo\\Database\\Type\\**DateType**

Maps to the BSON `UTCDateTime` type. The default return value of this column
type is `Cake\I18n\DateTime`, which extends
[Chronos](https://github.com/cakephp/chronos) and the native `DateTimeImmutable`.

`toDatabase()` accepts a `DateTimeInterface` or the string formats
`'Y-m-d H:i:s'` and `'Y-m-d'` and produces a `UTCDateTime`; `toPHP()` returns a
`Cake\I18n\DateTime`. `marshal()` parses request-style values, including the
locale-aware formats when `useLocaleParser(true)` and `setLocaleFormat()` are
configured.

```php
use Crustum\Mongo\Database\Type\DateType;

$type = new DateType('date');
$type->useLocaleParser(true);          // accept localized input in marshal()
$type->setLocaleFormat('d/m/Y');       // the localized format
```

Because BSON stores instants in UTC, there are no fractional or timezone
variants — the SQL `DateTimeFractionalType` / `DateTimeTimezoneType` have no
MongoDB analog.

`class` Crustum\\Mongo\\Database\\Type\\**TimestampType**

Maps to the BSON `Timestamp` type (the server-side oplog timestamp, *not* a
date). `toDatabase()` accepts a `Timestamp` instance, a two-element array
`[increment, timestamp]`, or a numeric value.

### Enum Type

`class` Crustum\\Mongo\\Database\\Type\\**EnumType**

Maps a [BackedEnum](https://www.php.net/manual/en/language.enumerations.backed.php)
to its scalar (string or integer) value, so backed enum instances are stored as
plain values in MongoDB and converted back to enum instances on read. To use
this type you register the enum class with the type factory. A simple
`ArticleStatus` could look like:

```php
namespace App\Model\Enum;

enum ArticleStatus: string
{
    case Published = 'Y';
    case Unpublished = 'N';
}
```

Register the enum-backed type with `EnumType::from()`:

```php
use App\Model\Enum\ArticleStatus;
use Crustum\Mongo\Database\Type\EnumType;

$typeName = EnumType::from(ArticleStatus::class);
// returns 'enum-app-model-enum-articlestatus' and registers it in TypeFactory
```

The returned type name can then be pointed at a field of the collection schema:

```php
use Crustum\Mongo\Database\Schema\CollectionSchema;

// In the collection's initialize() or when building a schema:
$schema->setColumnType('status', EnumType::from(ArticleStatus::class));
// or via the #[Field] attribute on a Document:
// #[Field(name: 'status', type: 'enum-app-model-enum-articlestatus', enumType: ArticleStatus::class)]
```

`EnumType::from()` performs a `ReflectionEnum` check and throws
`InvalidArgumentException` for non-backed enums. `EnumType::getEnumClassName()`
returns the associated enum class. An `int`-backed enum is stored as a BSON
integer; a `string`-backed enum is stored as a BSON string.

#### EnumLabelInterface and EnumLabelTrait

`interface` Crustum\\Mongo\\ODM\\Enum\\**EnumLabelInterface**

`trait` Crustum\\Mongo\\ODM\\Enum\\**EnumLabelTrait**

CakePHP also provides the `EnumLabelInterface`, which can be implemented by
enums that want to provide a map of human-readable labels. The companion
`EnumLabelTrait` lives in the ODM layer (`Crustum\Mongo\ODM\Enum`) and gives a
default `label()` implementation that derives the label from the case name:

```php
namespace App\Model\Enum;

use Crustum\Mongo\ODM\Enum\EnumLabelInterface;
use Crustum\Mongo\ODM\Enum\EnumLabelTrait;

enum ArticleStatus: string implements EnumLabelInterface
{
    use EnumLabelTrait;

    case Published = 'Y';
    case Unpublished = 'N';
}
```

`EnumLabelTrait::label()` humanizes the case name (`InReview` → `In review`).
This is useful when you want to use your enums in `FormHelper` select inputs.

You can use [bake](../bake) to generate an enum class:

```bash
# generate an enum class with two cases stored as an integer
bin/cake bake mongo_enum UserStatus inactive:0,active:1 --int

# generate an enum class with two cases as strings
bin/cake bake mongo_enum UserStatus published:Y,unpublished:N
```

`Crustum/Mongo` recommends a few conventions for enums:

- Enum classnames should follow `{Document}{ColumnName}` style to enable
  detection while running bake and to aid with project consistency.
- Enum cases should use CamelCase style.
- Enums should implement `EnumLabelInterface` to improve compatibility with
  bake and `FormHelper`.

### Geospatial Types

MongoDB supports geospatial queries natively. Crustum exposes them through two
channels:

- The `QueryBuilder` helpers `near()` and `geoWithin()`, which build
  `$near` / `$geoWithin` filter operators and the `GeospatialExpression`.
- The vector types `vector_float32`, `vector_int8` and `vector_packed_bit` for
  Atlas Vector Search (used with `$vectorSearch` — see the
  [Query Builder](query-builder.md) docs).

The SQL `geometry` / `point` / `linestring` / `polygon` column types do not
apply to MongoDB; geospatial data is stored as GeoJSON documents.

### Adding Custom Types

`class` Crustum\\Mongo\\Database\\Type\\**TypeFactory**

`static` TypeFactory::**map**(string $name, string $class): void

`static` TypeFactory::**getMapped**(string $type): ?string

`static` TypeFactory::**set**(string $name, TypeInterface $instance): void

`static` TypeFactory::**setMap**(array $map): void

`static` TypeFactory::**getMap**(): array

`static` TypeFactory::**build**(string $name): TypeInterface

`static` TypeFactory::**clear**(): void

You can retrieve the mapped class name for a specific type using `getMapped()`:

```php
use Crustum\Mongo\Database\Type\TypeFactory;

// Returns the class name mapped to the 'datetime' type
$className = TypeFactory::getMapped('datetime');
```

If you need to use types that are not built in you can add them to the type
system. Type classes are expected to implement `TypeInterface`:

- `toPHP`: Casts a given value from a BSON type to a PHP equivalent.
- `toDatabase`: Casts a given value from a PHP type to one acceptable by
  MongoDB.
- `marshal`: Marshals flat data into PHP objects.
- `newId`: Generates a new value of the type's native format (used for
  identifier generation; `ObjectIdType::newId()` returns a fresh `ObjectId`).

To fulfill the basic interface, extend `Crustum\Mongo\Database\Type\BaseType`.
For example, if we wanted to add a PointMutation type, we could make the
following type class:

```php
// in src/Database/Type/PointMutationType.php

namespace App\Database\Type;

use Crustum\Mongo\Database\Driver\MongoDriver;
use Crustum\Mongo\Database\Type\BaseType;

class PointMutationType extends BaseType
{
    public function toPHP(mixed $value, MongoDriver $driver): mixed
    {
        if ($value === null) {
            return null;
        }

        return $this->pmDecode($value);
    }

    public function marshal(mixed $value): mixed
    {
        if (is_array($value) || $value === null) {
            return $value;
        }

        return $this->pmDecode($value);
    }

    public function toDatabase(mixed $value, MongoDriver $driver): mixed
    {
        return sprintf('%d%s>%s', $value['position'], $value['from'], $value['to']);
    }

    protected function pmDecode(mixed $value): mixed
    {
        if (preg_match('/^(\d+)([a-zA-Z])>([a-zA-Z])$/', $value, $matches)) {
            return [
                'position' => (int) $matches[1],
                'from' => $matches[2],
                'to' => $matches[3],
            ];
        }

        return null;
    }
}
```

### Connecting Custom Datatypes to Schema Reflection and Generation

Once we've created our new type, we need to add it to the type mapping. During
our application bootstrap we should do the following:

```php
use Crustum\Mongo\Database\Type\TypeFactory;

TypeFactory::map('point_mutation', \App\Database\Type\PointMutationType::class);
```

We then have two ways to use our datatype in our models.

1. The first path is to point a schema field at the new type so the database
   layer automatically converts the data when creating queries. Because MongoDB
   has no SQL DDL (no `CREATE TABLE`), there is no `ColumnSchemaAwareInterface`
   generating SQL column definitions; instead the field type is declared on the
   collection schema (see the [schema system](schema-system.md)):
   ```php
   $schema->addField('mutation', ['type' => 'point_mutation']);
   // or, on the reflection side:
   $schema->setFieldType('mutation', 'point_mutation');
   ```
2. The second path is to register a mapping between the custom type and the
   BSON type reported by the `$jsonSchema` validator, so reflected schemas
   normalize to the canonical plugin type name. Unknown BSON types stay
   nullable rather than being guessed.

> [!NOTE]
> There is no `toStatement()` — the SQL/PDO statement binding concept does not
> exist in MongoDB. Values are cast to BSON via `toDatabase()`.

## Connection Classes

`class` Crustum\\Mongo\\Database\\**Connection**

Connection classes provide a simple interface to interact with database
connections in a consistent way. They are intended as a more abstract interface
to the driver layer and provide features for executing queries, logging queries,
and doing transactional operations.

The connection exposes the underlying MongoDB client, database and collections
for raw access:

```php
$client = $connection->getClient();                        // MongoDB\Client
$database = $connection->getDatabase();                    // MongoDB\Database
$collection = $connection->getCollection('articles');      // MongoDB\Collection
```

### Executing Queries

`method` Connection::**run**(Query $query): mixed

Once you've gotten a connection object, you'll probably want to issue some
queries with it. The most flexible way of creating queries is to use the
[Query Builder](query-builder.md). This approach allows you to build complex
and expressive queries without having to use platform-specific command syntax.
When using the query builder, nothing is sent to the database server until the
`execute()` method is called, or the query is iterated. Iterating a query will
first execute it and then start iterating over the result set:

```php
$query = $connection->selectQuery();
$query->from('articles')
    ->where(['published' => true]);

foreach ($query as $row) {
    // Do something with the row.
}
```

`method` Connection::**selectQuery**(): SelectQuery

The select query builder supports the full find/aggregation surface documented
in [Writing Queries](Queries.md): `where()`, `select()`, `orderBy()`, `limit()`,
`skip()` / `offset()` / `page()`, `groupBy()`, `having()`, `window()`,
`distinct()`, `join()` / `leftJoin()` (`$lookup` facade), `pipeline()`, and the
sugar methods (`lookup`, `unwind`, `addFields`, `sample`, `facet`,
`graphLookup`, `unionWith`, `search`, `vectorSearch`, ...).

`method` Connection::**insertQuery**(): InsertQuery

This method provides you a builder for `INSERT` queries:

```php
$query = $connection->insertQuery('articles', [
    'title' => '1st article',
]);
$ids = $query->execute(); // list<string> of inserted _id values
```

For multiple documents use `valuesMany()`:

```php
$query = $connection->insertQuery('articles');
$query->valuesMany([
    ['title' => '1st article'],
    ['title' => '2nd article'],
]);
$ids = $query->execute();
```

`method` Connection::**updateQuery**(): UpdateQuery

This method provides you a builder for `UPDATE` queries:

```php
$query = $connection->updateQuery('articles')
    ->set(['published' => true])
    ->where(['_id' => '00000000000000000000000a']);
$affected = $query->execute(); // int
```

Beyond `set()`, `UpdateQuery` provides Mongo update-operator helpers:
`unset`, `increment` (`$inc`), `decrement`, `push` (`$push`), `pull` (`$pull`),
`addToSet` (`$addToSet`), `pop` (`$pop`), `multiply` (`$mul`), `rename`
(`$rename`), `min` (`$min`), `max` (`$max`) and `currentDate` (`$currentDate`):

```php
$query = $connection->updateQuery('articles')
    ->increment('views')
    ->push('tags', 'mongo')
    ->where(['_id' => '00000000000000000000000a']);
$query->execute();
```

`method` Connection::**deleteQuery**(): DeleteQuery

This method provides you a builder for `DELETE` queries:

```php
$query = $connection->deleteQuery();
$query->delete('articles')
    ->where(['_id' => '00000000000000000000000a']);
$deleted = $query->execute(); // int
```

### Compiling Queries

You can inspect what will be sent to MongoDB without executing it:

```php
$compiled = $query->compile();
// ['type' => 'find'|'aggregate'|'insert'|'update'|'delete', ...]
//   'find'      => ['filter' => ..., 'options' => ...]
//   'aggregate' => ['pipeline' => [...], 'options' => ...]

$json = $query->sql();       // JSON dump of the compiled query, for logging
$string = (string) $query;   // same as sql()
```

### Using Transactions

The connection objects provide you a few simple ways to run database
transactions. The most basic way is through the `begin()`, `commit()` and
`rollback()` methods, which map to MongoDB multi-document transactions (a
session):

```php
$connection->begin();
$connection->updateQuery('articles', ['published' => true], ['_id' => 1])->execute();
$connection->updateQuery('articles', ['published' => false], ['_id' => 2])->execute();
$connection->commit();
```

`method` Connection::**transactional**(callable $callback): mixed

In addition to this interface connection instances also provide the
`transactional()` method, which makes handling the begin/commit/rollback calls
much simpler:

```php
$connection->transactional(function (Connection $connection) {
    $connection->updateQuery('articles', ['published' => true], ['_id' => 1])->execute();
    $connection->updateQuery('articles', ['published' => false], ['_id' => 2])->execute();
});
```

The transactional method will do the following:

- Call `begin`.
- Call the provided closure.
- If the closure raises an exception, a rollback will be issued. The original
  exception will be re-thrown.
- If the closure returns `false`, a rollback will be issued.
- If the closure executes successfully, the transaction will be committed.

> [!NOTE]
> MongoDB multi-document transactions require a replica set (or a sharded
> cluster via mongos). On servers without such support (e.g. a standalone) the
> transaction is **emulated**: state is tracked so `begin()`/`commit()`/
> `rollback()`/`afterCommit()` keep their semantics, but writes are not atomic.
> Check support with `$connection->supportsTransactions()`.
>
> MongoDB has no savepoints. Nested `begin()` calls are emulated through a
> transaction level: only the outermost `begin()`/`commit()` pair maps to a
> real Mongo session transaction.

### afterCommit

`method` Connection::**afterCommit**(callable $callback): void

You can register callbacks to run after the outermost transaction commits using
`afterCommit()`. This is useful for deferring side effects like sending emails,
dispatching jobs, or invalidating caches until you know the data has been
persisted:

```php
$connection->begin();
$connection->updateQuery('articles', ['published' => true], ['_id' => 1])->execute();
$connection->afterCommit(function () {
    // Send notification email — only runs if the transaction commits.
    $this->mailer->send('article-published');
});
$connection->commit(); // Callback fires here.
```

Callbacks are discarded if the transaction is rolled back. When nested
transactions are in use, callbacks registered at any depth are deferred until
the outermost transaction commits:

```php
$connection->begin();
$connection->afterCommit(function () {
    // This fires after the outermost commit.
});

$connection->begin(); // Nested (emulated)
$connection->afterCommit(function () {
    // Also deferred to outermost commit.
});
$connection->commit(); // Releases the nested level — callbacks don't fire yet.

$connection->commit(); // Outermost commit — both callbacks fire now.
```

If `afterCommit()` is called when no transaction is active, the callback
executes immediately. This matches the semantics of the ODM's
`Collection.afterSaveCommit` event, which also fires immediately for non-atomic
saves.

## Interacting with Results

There are no PDO `StatementInterface` objects and no `fetch()` / `fetchAll()`
/ `rowCount()` / `errorCode()` / `errorInfo()` methods. Instead, executing a
query returns a value whose shape depends on the query type:

- **Select** — `execute()` returns a MongoDB cursor (`Traversable`) of
  associative arrays. `all()` wraps it in a `Crustum\Mongo\Database\ResultSet`
  (a `Cake\Collection\Collection`), giving you `first()`, `count()`, `toArray()`
  and the full collection API:
  ```php
  $rows = $query->all();
  $first = $rows->first();
  $total = $rows->count();
  $all = $rows->toArray();
  ```
- **Insert** — `execute()` returns a `list<string>` of inserted `_id` values.
- **Update / Delete** — `execute()` returns the number of affected documents.

### Fetching Rows

After executing a select query, results are fetched by iterating the cursor or
using the result set:

```php
$cursor = $query->execute();

// Read all rows as an array of associative arrays.
$rows = iterator_to_array($cursor, false);

// Or use the ResultSet API.
$rows = $query->all()->toArray();
```

### Getting Affected Counts

For update and delete queries, `execute()` returns the number of affected
documents:

```php
$affected = $updateQuery->execute(); // int — modified documents
$deleted = $deleteQuery->execute();  // int — deleted documents
```

### Result Casting & Decorators

Select rows can be cast through the select type map and decorated:

```php
$query
    ->setSelectTypeMap(['_id' => 'objectid', 'created' => 'datetime'])
    ->enableResultsCasting()                       // default
    ->disableResultsCasting()                      // keep rows raw
    ->decorateResults(fn (array $row) => $row + ['slug' => '...']);
```

## Query Logging

Query logging can be enabled when configuring your connection by setting the
`log` option to `true` (or to a logger class name / instance).

When query logging is enabled, MongoDB commands are logged through
`Cake\Log\Log` using the 'debug' level and the `mongoQueriesLog` /
`mongo.database.queries` scopes (`Crustum\Mongo\Database\Log\QueryLogger`).
You will need to have a logger configured to capture this level & scope.
Logging to `stderr` can be useful when working on unit tests, and logging to
files can be useful when working with web requests:

```php
use Cake\Log\Log;

// Console logging
Log::setConfig('queries', [
    'className' => 'Console',
    'stream' => 'php://stderr',
    'scopes' => ['mongoQueriesLog'],
]);

// File logging
Log::setConfig('queries', [
    'className' => 'File',
    'path' => LOGS,
    'file' => 'queries.log',
    'scopes' => ['mongoQueriesLog'],
]);
```

Commands are formatted as JSON documents by `Crustum\Mongo\Database\Log\MongoLogger`
(e.g. `{"operation":"find","database":"...","collection":"articles","filter":{...}}`).
Catalog/reflection commands can be excluded from the log with the
`logSchemaCommands` config (default `false` in `MongoLogger`).

> [!NOTE]
> Query logging is only intended for debugging/development uses. You should
> never leave query logging on in production as it will negatively impact the
> performance of your application.

### Redacting Sensitive Values from Query Logs

Query log lines render the executed commands with the values spliced back in
(the JSON filter/projection/update documents), so any secret stored in a
document (encryption keys, passwords, OAuth tokens) ends up in every surface
that consumes a `LoggedQuery` — file logs via `__toString()`, structured
loggers via `getContext()`, and anything that re-serialises the `LoggedQuery`
as JSON via `jsonSerialize()`.

The log path runs through `Cake\Database\Log\LoggedQuery` (the same class
Cake's SQL layer uses), so the redaction hook is the same:
`LoggedQuery::setRedactor()` registers a global `Closure` invoked before any of
those exit points are exposed. The closure receives the raw query string and
the bound-params context and must return a 2-element array
`[string $query, array $params]` with sensitive values replaced:

```php
use Cake\Database\Log\LoggedQuery;

// In Application::bootstrap() or equivalent.
LoggedQuery::setRedactor(function (string $query, array $params): array {
    foreach ($params as $key => $value) {
        if (in_array($key, ['password', 'token', 'apiKey'], true)) {
            $params[$key] = '«REDACTED»';
        }
    }
    return [$query, $params];
});
```

The hook fires in `interpolate()`, `getContext()`, and `jsonSerialize()`, so
every public exit path is covered. Pass `null` to clear a previously
registered redactor.

A redactor that throws or returns a malformed value is silently ignored for
that call — the raw query and params are used as a safe fallback so a faulty
redactor cannot break logging.

## Identifier Quoting

Not applicable. MongoDB field names are not quoted in generated commands — there
is no SQL, no `quoteIdentifiers` config and no `enableAutoQuoting()`. MongoDB
field names are used verbatim.

## Metadata Caching

Crustum uses database reflection to determine the schema and indexes your
application contains. Because this metadata changes infrequently and can be
expensive to access, it is typically cached. By default, metadata is stored in
the `_cake_model_` cache configuration. You can define a custom cache
configuration using the `cacheMetadata` option in your datasource
configuration:

```php
'Datasources' => [
    'default' => [
        // Other keys go here.

        // Use the 'orm_metadata' cache config for metadata.
        'cacheMetadata' => 'orm_metadata',
    ],
],
```

You can also configure the metadata caching at runtime with the
`cacheMetadata()` method:

```php
// Disable the cache
$connection->cacheMetadata(false);

// Enable the cache
$connection->cacheMetadata(true);

// Use a custom cache config
$connection->cacheMetadata('orm_metadata');
```

When enabled, `getSchemaCollection()` returns a `CachedSchemaCollection`
decorator that stores `CollectionSchema` instances in the configured cache:

```php
$schema = $connection->getSchemaCollection()->describe('posts', ['forceRefresh' => true]);
```

A `SchemaCache` tool is available for building/clearing the metadata cache from
deployment scripts (the analog of Cake's schema-cache CLI):

```php
use Crustum\Mongo\Database\SchemaCache;

$schemaCache = new SchemaCache($connection);
$schemaCache->build();
$schemaCache->clear();
```

## Creating Databases

MongoDB has no `CREATE DATABASE` SQL statement. A database is selected by the
`database` config key and is created implicitly on the first write. Collections
are created implicitly on the first insert, or explicitly through the schema
manager:

```php
use Crustum\Mongo\Database\Schema\SchemaManager;

$manager = new SchemaManager($connection);
$manager->createCollection('my_collection', [
    'validator' => [
        '$jsonSchema' => [
            'bsonType' => 'object',
            'properties' => [
                'title' => ['bsonType' => 'string'],
            ],
        ],
    ],
]);
```

See the [Schema System](schema-system.md) documentation for collection and
index management, `$jsonSchema` validators, and the `SchemaManager` API.

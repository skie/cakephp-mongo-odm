# Schema System

The `Crustum/Mongo` plugin's schema system reflects and generates schema information for
**collections** in MongoDB. It replaces Cake's SQL-oriented
`Cake\Database\Schema\Collection` / `TableSchema` with Mongo-native equivalents:

- **`CollectionSchema`** (the analog of `Cake\Database\Schema\TableSchema`)
  describes a single collection: its fields, indexes, and `$jsonSchema`
  validator.
- **`SchemaCollection`** (the analog of `Cake\Database\Schema\Collection`)
  introspects the collections available on a connection.

Because MongoDB is schemaless at the storage level, "schema" here means the
metadata the ODM works with: field type maps, `_id`, indexes, and the
`$jsonSchema` validation rules enforced by the server.

The primary consumers are [Test Fixtures] and the migrations/bake tooling, but
the classes are usable directly in your application.

## CollectionSchema Objects

`class` Crustum\\Mongo\\Database\\Schema\\**CollectionSchema**

A `CollectionSchema` holds the schema of one collection. It is returned by the
schema reflection features and can also be built programmatically:

```php
use Crustum\Mongo\Database\Schema\CollectionSchema;

// Build a collection schema field by field.
$schema = new CollectionSchema('posts');
$schema->addField('_id', [
    'type' => 'objectid',
])->addField('title', [
    'type' => 'string',
])->addField('author_id', [
    'type' => 'objectid',
]);

// addField() also accepts a bare type string:
$schema->addField('views', 'integer');
```

The first form (array attributes) allows more detail: `type`, `null`, `default`,
`length`, `precision`, `identity`, `comment`, `baseType`, `enumType`,
`notSaved`.

### Accessing Field Data

Fields are added via `addField()` (constructor-argument form is also supported
by the readers). Once defined, field information can be fetched with `field()`
/ `fields()`:

```php
// Get the array of data about a field (incl. validation + index info).
$data = $schema->field('title');

// Get the list of all field names.
$fields = $schema->fields();

// Get the type map (field => TypeFactory type name).
$typeMap = $schema->typeMap();
```

`field()` also supports **dotted paths** for nested documents
(`address.city`) and arrays of objects (`comments.author_id`), resolving
against the `$jsonSchema` properties tree.

### Primary Key

MongoDB creates `_id` automatically, so it is always the primary key. There is
no auto-increment / serial concept:

```php
$primary = $schema->primaryKey(); // '_id'
```

### Indexes

`class` Crustum\\Mongo\\Database\\Schema\\**Index**

Indexes are added with `addIndex()`. Unlike Cake's plain column list, the Mongo
index key is a **map of `field => direction`**, where the value may be a sort
direction (`1`/`-1`) or a special index type (`'text'`, `'2dsphere'`,
`'hashed'`):

```php
$schema = new CollectionSchema('posts');
$schema->addField('author_id', 'objectid')
    ->addField('title', 'string')
    ->addField('slug', 'string');

// Unique index.
$schema->addIndex('slug_unique', [
    'key' => ['slug' => 1],
    'unique' => true,
]);

// Compound index (Cake 'columns' shape is accepted and normalized).
$schema->addIndex('slug_title', [
    'columns' => ['slug', 'title'],
]);

// Text index for full-text search.
$schema->addIndex('title_text', [
    'key' => ['title' => 'text'],
]);

// TTL index.
$schema->addIndex('expire', [
    'key' => ['created' => 1],
    'expireAfterSeconds' => 3600,
]);

// Partial index.
$schema->addIndex('partial', [
    'key' => ['status' => 1],
    'partialFilterExpression' => ['status' => ['$exists' => true]],
]);
```

Index types are exposed as constants on `Index`: `Index::INDEX`, `Index::UNIQUE`,
`Index::TEXT`, `Index::GEO`, `Index::GEO_2D`, `Index::GEO_2DSPHERE`,
`Index::HASHED`, `Index::VECTOR`. There are no SQL foreign-key constraints —
references between collections are handled by the ODM association layer, not by
the schema.

### Reading Indexes

Indexes are read with the accessor methods:

```php
// Get all indexes (Index value objects keyed by name).
$indexes = $schema->indexes();

// Get a single index; raises DatabaseException if missing.
$index = $schema->index('slug_unique');

// Get the createIndex() option array for MongoDB\Collection.
$options = $index->createIndexOptions();
```

### Validation ($jsonSchema)

`class` Crustum\\Mongo\\Database\\Schema\\**Validator**

MongoDB has no `CREATE TABLE` with column types and constraints. Instead,
collections can enforce document structure through a
[JSON Schema validator](https://www.mongodb.com/docs/manual/core/schema-validation/)
— the `$jsonSchema` document attached to a collection. MongoDB then rejects
inserts/updates that do not match it (validation level `strict` by default,
`moderate` allows legacy documents that predate the validator).

A `CollectionSchema` carries a `Validator` value object that builds this
`$jsonSchema` document from a fluent API. `additionalProperties` defaults to
`true` so legacy documents with extra fields never invalidate under `moderate`
validation:

```php
use Crustum\Mongo\Database\Schema\CollectionSchema;
use Crustum\Mongo\Database\Schema\Validator;

$validator = new Validator();
$validator
    ->field('title', ['bsonType' => 'string'])
    ->field('author_id', ['bsonType' => 'objectId'])
    ->addRequired('title');

$schema = new CollectionSchema('posts');
$schema->setValidator($validator);

// Export as the raw $jsonSchema document.
$rules = $schema->validationRules(); // ['$jsonSchema' => [...], ...]
```

The `$jsonSchema` is attached to a collection when it is created, or applied to
an existing collection with `collMod` — both via `SchemaManager` (see below):

```php
// On creation:
$manager->createCollection('posts', ['validator' => $validator->toArray()]);

// On an existing collection (collMod):
$manager->setValidator('posts', $validator->toArray(), 'moderate', 'error');
```

When a `CollectionSchema` is built by reflecting a live collection, its
`Validator` is populated from the collection's actual `$jsonSchema` (via
`listCollections` options). BSON type spellings reported by validators
(`objectId`, `long`, `binData`, ...) are normalized to canonical plugin type
names (`objectid`, `int64`, `binary`, ...) via `fieldType()` / `typeMap()`, so
the schema feeds directly into `TypeFactory::build()`.

> [!NOTE]
> The schema metadata read from `#[Field]` / `#[Document]` attributes is an
> **ODM-layer** feature, not part of this Database schema system. `Document` /
> `Dto` attribute readers (`Crustum\Mongo\ODM\Mapping\DocumentSchemaReader`,
> `DtoSchemaReader`) derive a `CollectionSchema` from document class attributes
> when the collection has no `$jsonSchema` validator. This page documents the
> schema objects themselves; attribute-driven schema derivation is covered by
> the ODM (Document) documentation.

## Converting Schemas into MongoDB (SchemaManager)

`class` Crustum\\Mongo\\Database\\Schema\\**SchemaManager**

There is no SQL `CREATE TABLE`/`DROP TABLE`. DDL is performed by the
`SchemaManager`, which wraps the MongoDB driver's collection/index/validator
operations in a Cake-friendly surface:

```php
use Crustum\Mongo\Database\Schema\SchemaManager;

$manager = new SchemaManager($connection);

// Create a collection with a $jsonSchema validator.
$manager->createCollection('posts', [
    'validator' => $validator->toArray(),
]);

// Manage indexes.
$manager->createIndex('posts', ['slug' => 1], ['unique' => true]);
$manager->listIndexes('posts');
$manager->dropIndex('posts', 'slug_1');

// Manage validators after creation (collMod).
$manager->setValidator('posts', $validator->toArray(), 'moderate', 'error');
$manager->getValidator('posts');

// Collection lifecycle.
$manager->listCollections();
$manager->renameCollection('posts', 'articles');
$manager->dropCollection('posts');

// Atlas search indexes.
$manager->createSearchIndex('posts', ['mappings' => ['dynamic' => true]]);
$manager->listSearchIndexes('posts');
```

## Schema Collections

`class` Crustum\\Mongo\\Database\\Schema\\**SchemaCollection**

`SchemaCollection` provides access to the collections available on a
connection. Use it to list collections or reflect them into
`CollectionSchema` objects:

```php
$db = ConnectionManager::get('default');

// Create a schema collection.
$collection = $db->getSchemaCollection();

// Get the collection names.
$collections = $collection->listCollections();

// Get a single collection (instance of CollectionSchema).
$postsSchema = $collection->describe('posts');
```

`listTables()` is kept as a Cake-compatible alias of `listCollections()`.

### Metadata Caching

When metadata caching is enabled on the connection (`cacheMetadata` config),
`getSchemaCollection()` returns a `CachedSchemaCollection` decorator. The
decorator stores `CollectionSchema` instances in the configured cache and
supports `forceRefresh` on `describe()`:

```php
$schema = $collection->describe('posts', ['forceRefresh' => true]);
$collection->clearCache('posts');
```

## Key differences from Cake's Schema System

| Cake (`TableSchema`) | Crustum (`CollectionSchema`) |
|---|---|
| `addColumn()` / `column()` / `columns()` | `addField()` / `field()` / `fields()` (column aliases kept for compatibility) |
| primary key: auto-increment / composite | `_id` — always the primary key, generated by MongoDB |
| `addConstraint()` (primary/unique/foreign) | no foreign keys; uniqueness expressed via `Index` (`unique: true`) |
| index as column list | index key map `field => direction` (+ text/geo/hashed/vector types) |
| `createSql()` / `dropSql()` → SQL | `SchemaManager::createCollection()` / `dropCollection()` (Mongo commands) |
| SQL column types | Mongo types via `TypeFactory` (`objectid`, `date`, `decimal128`, vectors, ...) |
| — | `$jsonSchema` `Validator` value object |

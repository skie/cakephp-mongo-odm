# ORM ↔ Mongo Association Bridge

`class` Crustum\Mongo\Orm\Bridge\Association

When a single application runs both a **SQL ORM** layer (`Cake\ORM\Table`) and a
**Mongo ODM** layer (`Crustum\Mongo\ODM\BaseCollection`), the two repositories
have no real foreign keys or joins between them. A SQL row's `id` might be
referenced from a Mongo document's field, or a Mongo `_id` might be referenced
from a SQL column. Without any infrastructure, code ends up reaching across by
hand with locator calls scattered through the application.

The association bridge formalizes that cross-boundary access into
**declarative associations** with the same ergonomics as a normal Cake
association: `$entity->property`, `contain()`-style eager loading, cascade
delete, and a controlled save chain. Under the hood the "join" is always a
**batched query + dictionary match** (Mongo `whereIn` / SQL `whereIn`) — never
a SQL join, never a `$lookup`.

The bridge ships in `Crustum\Mongo\Orm\Bridge\` and is fully opt-in: a table
or collection declares it only when it actually needs to reach across the
boundary.

## The Two Directions

```
         cross-boundary associations
SQL Table  ───────────────────────────►  Mongo Collection
 (source)                                 (target)
```

- **Direction 1 — ORM → ODM.** A SQL `Table` is the source, a Mongo
  `Collection` is the target. Declared with the `mongo*()` sugar on the table.
  This is the common case: `$order->documents`, `$order->author`.
- **Direction 2 — ODM → ORM.** The mirror: a Mongo `Collection` is the source,
  a SQL `Table` is the target. Declared with the `*Orm` sugar on the
  collection. Used for read-mostly lookups, e.g. resolving which SQL row a
  Mongo file record belongs to.

Direction 1 supports all five association types — BelongsTo, HasOne, HasMany,
BelongsToMany and DBRef. Direction 2 currently covers BelongsTo, HasOne and
HasMany, with the roles swapped.

## Direction 1 — Associations on an ORM Table

Add `MongoAssociationsTrait` and `MongoCollectionAwareTrait` to the table, and
declare the associations in `initialize()`. The trait registers each
association as a real Cake association on `Table->associations()`, so property
access and `contain()` behave natively:

```php
// src/Model/Table/OrdersTable.php
namespace App\Model\Table;

use Cake\ORM\Table;
use Crustum\Mongo\Orm\Bridge\MongoAssociationsTrait;
use Crustum\Mongo\Orm\Bridge\MongoCollectionAwareInterface;
use Crustum\Mongo\Orm\Bridge\MongoCollectionAwareTrait;

class OrdersTable extends Table implements MongoCollectionAwareInterface
{
    use MongoAssociationsTrait;
    use MongoCollectionAwareTrait;

    public function initialize(array $config): void
    {
        $this->setTable('orders');

        $this->mongoBelongsTo('Authors', ['property' => 'author']);
        $this->mongoHasOne('Profiles', ['property' => 'profile']);
        $this->mongoHasMany('Documents', ['property' => 'documents']);
        $this->mongoBelongsToMany('Tags', ['property' => 'tags', 'pivot' => 'array']);
    }
}
```

`MongoCollectionAwareTrait` is what lets the bridge resolve a Mongo target
collection by alias through the collection locator. Any method call on the
association that is not a bridge method is forwarded to that target
collection, so `$this->Orders->associations()->get('Authors')->getCollection()`
returns `'authors'` as if the collection itself were the association.

Each `mongo*()` method returns the underlying bridge association object, which
is what you use to configure or inspect the relation directly:

```php
$documents = $this->mongoHasMany('Documents', ['property' => 'documents']);

$documents->setConditions(['active' => true]);
$documents->setDependent('nullify');
```

### BelongsTo

A SQL row holds a Mongo `_id` in a column (convention `{name}_id`); the
association loads the single matching document. This is the Mongo mirror of
`Cake\ORM\Association\BelongsTo`.

```php
// orders.author_id holds a Mongo _id → loads the Authors document
$this->mongoBelongsTo('Authors', ['property' => 'author']);

$order = $this->Orders->find()->contain(['Authors'])->first();
echo $order->author->name;
```

Defaults: `foreignKey` = `{target singular}_id` (`author_id`), `bindingKey` =
`_id`, `property` = singular (`author`). A missing match attaches `null`.

### HasOne

A Mongo document holds the SQL row's primary key in a field (convention
`{source singular}_id`, e.g. `order_id`); the association loads the single
matching document (unique on the Mongo side).

```php
// profiles.order_id holds a SQL orders.id → loads the single matching document
$this->mongoHasOne('Profiles', ['property' => 'profile']);

$order = $this->Orders->find()->contain(['Profiles'])->first();
echo $order->profile->bio;
```

Defaults: `foreignKey` = `{source singular}_id` (`order_id`), `bindingKey` =
the source table's primary key, `property` = singular (`profile`).

### HasMany

The same foreign key shape as HasOne, without uniqueness: every document whose
field matches the SQL row's primary key is loaded into an array property. This
is the generalized version of the classic "files belong to a record" case.

```php
// documents.order_id holds a SQL orders.id → loads all matching documents
$this->mongoHasMany('Documents', ['property' => 'documents']);

$order = $this->Orders->find()->contain(['Documents'])->first();
foreach ($order->documents as $document) {
    echo $document->title;
}
```

Defaults: `foreignKey` = `{source singular}_id` (`order_id`), `bindingKey` =
the source table's primary key, `property` = plural (`documents`).

### BelongsToMany

A many-to-many link between a SQL source row and Mongo target documents,
supported by two pivot designs:

- **Junction collection** (`pivot` => `'junction'`, the default): a pivot
  collection (conventional name = the sorted `{source_table}_{target_collection}`
  names joined, e.g. `orders_tags`) holds `{source}_id` / `{target}_id` links.
  Load resolves the junction rows, then fetches the target documents in one
  batched query.
- **Array pivot** (`pivot` => `'array'`): each target document carries a
  `{source singular}_ids` array of source primary keys. Load matches documents
  whose array contains the source key. No extra collection.

```php
// Junction pivot (default)
$this->mongoBelongsToMany('Tags', ['property' => 'tags']);

// Array pivot
$this->mongoBelongsToMany('Tags', ['property' => 'tags', 'pivot' => 'array']);

$order = $this->Orders->find()->contain(['Tags'])->first();
foreach ($order->tags as $tag) {
    echo $tag->name;
}
```

For the junction pivot you can override the collection and the link fields:

```php
$this->mongoBelongsToMany('Tags', [
    'property' => 'tags',
    'junctionCollection' => 'order_tag_links',
    'sourceForeignKey' => 'order_id',
    'targetForeignKey' => 'tag_id',
]);
```

`targetForeignKey` defaults to `{target singular}_id` derived from the target
*alias*. When `className` maps the association to a differently-named
collection (e.g. `'Tags'` → `BridgeTags`), pass `targetForeignKey` explicitly.

### DBRef

A SQL column stores a Mongo DBRef pointer (`{ '$ref': collection, '$id': id }`);
the association loads the referenced document. The stored value may be either
the DBRef array itself or the raw Mongo `_id`.

```php
// src/Model/Table/FilesTable.php — SQL side of the DBRef
namespace App\Model\Table;

use Cake\ORM\Table;
use Crustum\Mongo\Orm\Bridge\MongoAssociationsTrait;
use Crustum\Mongo\Orm\Bridge\MongoCollectionAwareInterface;
use Crustum\Mongo\Orm\Bridge\MongoCollectionAwareTrait;

class FilesTable extends Table implements MongoCollectionAwareInterface
{
    use MongoAssociationsTrait;
    use MongoCollectionAwareTrait;

    public function initialize(array $config): void
    {
        $this->setTable('files');
        $this->mongoDbref('Files', ['property' => 'file']);
    }
}

// files.file_ref holds { $ref, $id } or a raw _id → loads the Files document
$fileRow = $this->fetchTable(FilesTable::class)->find()->contain(['Files'])->first();
echo $fileRow->file->path;
```

Defaults: `foreignKey` = `{target singular}_ref` (`file_ref`), `bindingKey` =
`_id`, `property` = singular (`file`). A missing match attaches `null`. The
`$ref` part is informational — resolution is always by `$id`.

## Loading Associated Data

Loading is **always batched**: for a set of source rows the bridge collects the
keys, runs **one** `whereIn` query on the target collection, builds a
dictionary, and attaches the results to each row. There are no N+1 queries.

With the associations declared, use `contain()` exactly as with a native Cake
association:

```php
$orders = $this->Orders
    ->find()
    ->contain(['Authors', 'Documents', 'Tags'])
    ->all();

foreach ($orders as $order) {
    debug($order->author->name);
    foreach ($order->documents as $document) {
        debug($document->title);
    }
}
```

The bridge always loads externally (SQL joins are never used for a Mongo
target), and every document for the whole set is fetched in a single batched
query before any result is hydrated.

You can also drive a load explicitly on a set of entities you already have, or
grab a lazy query against the target collection:

```php
// Batched load for an existing result set.
$orders = $this->Orders->find()->all();
$association = $this->Orders->associations()->get('Documents')->getBridge();
$association->load($orders);

// A lazy query against the target collection, never executed until you ask.
$query = $association->find()->where(['active' => true]);
```

## Wrapping Documents as ORM Entities

By default the loaded values are ODM `Document` objects. When the cross-boundary
data needs to flow through views and forms as if it were an ORM entity — e.g.
a file upload form expects an `EntityInterface` — enable `autoWrap`:

```php
$this->mongoHasMany('Documents', [
    'property' => 'documents',
    'autoWrap' => true,
]);
```

Each loaded document is then wrapped in a `DocumentWrapper` (a
`Cake\ORM\Entity`), while the original ODM document stays reachable:

```php
$order = $this->Orders->find()->contain(['Documents'])->first();
$document = $order->documents[0];

// Entity surface for views and forms.
echo $document->get('title');

// The raw ODM Document underneath.
$raw = $document->getDocument();
```

`autoWrap` works for every association type, including a single-value
BelongsTo, HasOne or DBRef.

## Saving Across the Boundary

Mongo payloads must never flow through the ORM's `saveAssociated()` machinery —
that would marshal them into SQL inserts. The bridge routes saves through a
dedicated two-phase method:

```php
$order = $this->Orders->newEmptyEntity();
$order->set('customer_name', 'Acme');
$order->set('documents', [['title' => 'Contract.pdf', 'created' => new DateTimeImmutable()]]);
$order->setDirty('documents', true);

$saved = $this->Orders->saveWithBridge($order, ['associate' => ['documents']]);
```

`saveWithBridge()` runs the SQL save of the ORM entity inside a transaction,
then persists each dirty cross-boundary property to its target collection. On a
Mongo failure it rolls the SQL save back (best effort) and reports errors on
the entity. The `associate` option restricts which dirty bridge properties are
written (an empty list, the default, writes all dirty ones).

```php
// Write only the SQL row; keep Mongo properties untouched.
$this->Orders->saveWithBridge($order, ['associate' => []]);
```

Which writes actually happen depends on the association type:

| Association | What `saveWithBridge()` writes |
|---|---|
| HasOne | One target document carrying the SQL foreign key |
| HasMany | A list of target documents, each carrying the SQL foreign key |
| BelongsTo | Nothing — the FK lives on the SQL column (the target document already exists) |
| DBRef | Nothing — the FK lives on the SQL column (the pointer already exists) |

`patchWithBridge()` mirrors `patchEntity()` but strips cross-boundary payloads
out of the form data first, so the ORM marshaller never sees them, then
re-applies them as dirty properties:

```php
$order = $this->Orders->patchWithBridge($order, $request->getData());
```

## Deleting Across the Boundary

```php
$this->Orders->deleteWithBridge($order);
```

`deleteWithBridge()` runs inside a SQL transaction and, for every association
with `dependent` enabled, cascades to the target documents before deleting the
SQL row:

| Association | `dependent` | Action on source delete |
|---|---|---|
| BelongsTo | n/a (source owns the FK) | FK removed from the source row |
| HasOne | `true` | deletes the target document |
| HasMany | `true` | deletes the target documents |
| HasMany | `'nullify'` | sets the foreign key to `null` on the target documents |
| BelongsToMany (junction) | `true` | deletes the junction rows |
| BelongsToMany (array pivot) | `true` | `$pull`s the source id from the target `*_ids` arrays |
| DBRef | `true` | no-op (a DBRef is only a pointer) |

```php
$this->mongoHasMany('Documents', ['property' => 'documents', 'dependent' => 'nullify']);
```

## Direction 2 — SQL Lookups From a Collection

When a Mongo collection needs SQL lookups, add `OrmAssociationsTrait` and
`OrmTableAwareTrait` to the collection and declare the reverse associations:

```php
// src/Model/Collection/FilesCollection.php
namespace App\Model\Collection;

use Crustum\Mongo\ODM\BaseCollection;
use Crustum\Mongo\Orm\Bridge\Orm\OrmAssociationsTrait;
use Crustum\Mongo\Orm\Bridge\OrmTableAwareInterface;
use Crustum\Mongo\Orm\Bridge\OrmTableAwareTrait;
use App\Model\Table\FilesTable;

class FilesCollection extends BaseCollection implements OrmTableAwareInterface
{
    use OrmAssociationsTrait;
    use OrmTableAwareTrait;

    protected ?string $collection = 'files';

    public function initialize(array $config): void
    {
        $this->belongsToOrm('FileRow', [
            'foreignKey' => 'file_ref',
            'property' => 'fileRow',
            'className' => FilesTable::class,
        ]);
    }
}
```

Loading is a single batched SQL `whereIn` on the target table — the SQL rows
whose `file_ref` column holds a matching Mongo `_id` are fetched in one query.
Because there is no `contain()` hook on the ODM side, call
`loadOrmAssociations()` after fetching the documents you care about:

```php
$files = $this->fetchCollection('Files');

$documents = $files->find()->all();
$files->loadOrmAssociations($documents);

foreach ($documents as $document) {
    $fileRow = $document->get('fileRow');
    debug($fileRow?->name);
}
```

Direction 2 supports `belongsToOrm`, `hasOneOrm` and `hasManyOrm` (a
`belongsToManyOrm` / `dbrefOrm` do not exist yet). The `foreignKey` /
`property` / `className` options work as in Direction 1, with the SQL table as
the target. The SQL row is the read side of the link — the Mongo document owns
the key.

## Association Options Reference

| Option | Type | Default | Applies to |
|---|---|---|---|
| `className` | string | the alias | all — target collection or table alias/class |
| `property` | string | singular / plural | all — entity property to attach |
| `foreignKey` | string\|array | per-type convention | all |
| `bindingKey` | string\|array | `_id` or source primary key | all |
| `conditions` | array | `[]` | all — pre-applied to every target query |
| `dependent` | bool\|string | `false` | all — cascade-delete matrix above |
| `autoWrap` | bool | `false` | all — wrap documents as ORM entities |
| `pivot` | `'junction'`\|`'array'` | `'junction'` | BelongsToMany |
| `through` | BaseCollection\|string | conventional `{a}_{b}` | BelongsToMany junction |
| `junctionCollection` | string | conventional | BelongsToMany junction — override the pivot alias |
| `sourceForeignKey` | string | `{source}_id` | BelongsToMany junction |
| `targetForeignKey` | string | `{target singular}_id` | BelongsToMany junction |

## Notes and Limitations

- **Direction-2 loading is manual.** There is no `contain()` hook on the ODM
  side; call `loadOrmAssociations()` on the collection after fetching.
- **`saveWithBridge()` cannot clear a property.** Values that are `null` or
  `[]` are skipped, so a bridge property cannot be nullified through the save
  chain — only the cascade `'nullify'` on delete exists.
- **BelongsToMany writes are not wired into `saveWithBridge()`.** The save path
  covers HasOne/HasMany; BelongsTo and DBRef are no-ops by design (their FK
  lives on the SQL row). For N:N writes (junction inserts, `$addToSet` /
  `$pull` on pivot arrays) manage the target collections directly.
- **`DBRef` resolves by `$id` only.** The `$ref` part of the pointer is not
  validated against the association's target collection.
- **No cross-repository transactions.** SQL and Mongo writes are ordered
  best-effort; a Mongo failure rolls back the SQL save but there is no
  two-phase commit, and no `afterCommit` hook fires.
- **Junction link fields derive from aliases.** With `pivot` => `'junction'`
  and a `className` that points at a differently-named collection, pass
  `sourceForeignKey` / `targetForeignKey` explicitly.

## See Also

- [Associations](../ODM/associations) — ODM-internal associations that never
  cross the boundary.
- [Collections](../ODM/collections) — declaring and configuring
  `BaseCollection` objects.

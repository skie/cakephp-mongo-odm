# Deleting Data

`class` Crustum\Mongo\ODM\**BaseCollection**

> ### PENDING
>
> This page is ported from the Cake ORM cookbook page
> `docs/orm/deleting-data.md` in full. Deleting a single document, cascading
> deletes (`dependent` + `cascadeCallbacks`), bulk deletes (`deleteMany()`,
> `deleteManyOrFail()`, `deleteAll()`) and strict deletes (`deleteOrFail()`)
> are all covered here.
>
> Every claim and code sample was checked against
> `crustum/src/ODM/BaseCollection.php`,
> `crustum/src/ODM/Association/DependentDeleteHelper.php`,
> `crustum/src/ODM/Association/BelongsToMany.php` and
> `tests/TestCase/ODM/BaseCollectionTest.php`. Before the `### PENDING` banner
> is removed, the remaining samples must be run against a live test database
> (see `docs/working-memory-docs/52-deleting-data-diffs.md`).

## Deleting a Single Document

`method` Crustum\Mongo\ODM\BaseCollection::**delete**(EntityInterface $document, array $options = []): bool

Once you've loaded a document you can delete it by calling the originating
collection's delete method:

```php
// In a controller.
$document = $this->fetchCollection('Articles')->get('000000000000000000000002');
$result = $this->fetchCollection('Articles')->delete($document);
```

When deleting documents a few things happen:

1. The [delete rules](../ODM/validation#application-rules) will be applied. If the rules
    fail, deletion will be prevented.
2. The `Collection.beforeDelete` event is triggered. If this event is stopped, the
    delete will be aborted and the event's result will be returned.
3. The document will be deleted.
4. All dependent associations will be deleted. If associations are being deleted
    as documents, additional events will be dispatched.
5. Any junction records for BelongsToMany associations will be removed.
6. The `Collection.afterDelete` event will be triggered.

By default, all deletes happen within a transaction (a MongoDB session). You
can disable the transaction with the `atomic` option:

```php
$result = $this->fetchCollection('Articles')->delete($document, ['atomic' => false]);
```

The `$options` parameter supports the following options:

- `atomic` Defaults to true. When true the deletion happens within
  a transaction.
- `checkRules` Defaults to true. Check deletion rules before deleting
  records.

## Cascading Deletes

When deleting documents, associated data can also be deleted. If your HasOne and
HasMany associations are configured as `dependent`, delete operations will
'cascade' to those documents as well. By default, documents in associated
collections are removed using `deleteAll()`. You can elect to
have the ODM load related documents, and delete them individually by setting the
`cascadeCallbacks` option to `true`. A sample HasMany association with both
these options enabled would be:

```php
// In a collection's initialize() method.
$this->hasMany('Comments', [
    'dependent' => true,
    'cascadeCallbacks' => true,
]);
```

> [!NOTE]
> Setting `cascadeCallbacks` to `true`, results in considerably slower deletes
> when compared to bulk deletes. The cascadeCallbacks option should only be
> enabled when your application has important work handled by event listeners.

## Bulk Deletes

`method` Crustum\Mongo\ODM\BaseCollection::**deleteMany**(iterable $entities, array $options = []): iterable|false

If you have an array of documents you want to delete you can use `deleteMany()`
to delete them in a single transaction:

```php
// Get a boolean indicating success
$success = $this->fetchCollection('Articles')->deleteMany($documents);

// Will throw a PersistenceFailedException if any document cannot be deleted.
$this->fetchCollection('Articles')->deleteManyOrFail($documents);
```

The `$options` for these methods are the same as `delete()`. Deleting
records with these method **will** trigger events.

### deleteAll()

`method` Crustum\Mongo\ODM\BaseCollection::**deleteAll**(QueryExpression|Closure|array|string|null $conditions): int

There may be times when deleting rows one by one is not efficient or useful.
In these cases it is more performant to use a bulk-delete to remove many rows at
once:

```php
// Delete all the spam
public function destroySpam()
{
    return $this->fetchCollection('Articles')->deleteAll(['is_spam' => true]);
}
```

A bulk-delete will be considered successful if 1 or more rows are deleted. The
function returns the number of deleted records as an integer.

> [!WARNING]
> deleteAll will *not* trigger beforeDelete/afterDelete events.
> If you need callbacks triggered, first load the documents with `find()`
> and delete them in a loop.

## Strict Deletes

`method` Crustum\Mongo\ODM\BaseCollection::**deleteOrFail**(EntityInterface $document, array $options = []): void

Using this method will throw a
`Crustum\Mongo\ODM\Exception\PersistenceFailedException` if:

- the document is new
- the document has no primary key value
- application rules checks failed
- the delete was aborted by a callback.

If you want to track down the document that failed to delete, you can use the
`Crustum\Mongo\ODM\Exception\PersistenceFailedException::getDocument()` method:

```php
try {
    $collection->deleteOrFail($document);
} catch (\Crustum\Mongo\ODM\Exception\PersistenceFailedException $e) {
    echo $e->getDocument();
}
```

As this internally performs a `Crustum\Mongo\ODM\BaseCollection::delete()` call,
all corresponding delete events will be triggered.
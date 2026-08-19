---
title: "SoftDelete"
description: "Soft-delete documents in the Crustum Mongo ODM: mark records as deleted with a timestamp instead of removing them."
---

# SoftDelete

`class` Crustum\Mongo\ODM\Behavior\**SoftDeleteBehavior**

> ### PENDING
>
> This behavior is ODM-specific — there is no `SoftDeleteBehavior` in the Cake
> ORM cookbook. The examples below were written against
> `src/ODM/Behavior/SoftDeleteBehavior.php` and have not been executed against a
> live `test_mongo_db` yet — run them before removing this banner.

Sometimes you don't want to permanently delete documents. You may want to keep
a record of the deletion (for auditing, "recycle bin" features, or to keep
referential integrity with other documents). The SoftDelete behavior replaces
physical deletes with an update: it sets a `deleted_at` timestamp on the
document instead of removing it.

## Basic Usage

Enable the behavior on the collection where you want to soft-delete documents:

```php
class ArticlesCollection extends Collection
{
    public function initialize(array $config): void
    {
        $this->addBehavior('SoftDelete');
    }
}
```

Once enabled, all queries automatically exclude soft-deleted documents:

```php
$articles->find()->all(); // excludes documents with a deleted_at timestamp
```

And calling `delete()` on a document will no longer remove it — it will only
set the `deleted_at` field to the current UTC time:

```php
$article = $articles->get('64c7c2e3a1b2c3d4e5f6a7b8');
$articles->delete($article); // sets deleted_at, the document stays in the collection
```

## Including Soft-Deleted Documents in Results

To include soft-deleted documents in a find, pass the `withDeleted` option
(option name configurable via `withDeletedOption`):

```php
$results = $articles->find(withDeleted: true)->all();

$deletedOnly = $articles->find(withDeleted: true)
    ->where(['deleted_at IS NOT' => null])
    ->all();
```

## Forcing a Physical Delete

To permanently remove a document instead of soft-deleting it, pass the
`forceDelete` option to `delete()`:

```php
$article = $articles->get('64c7c2e3a1b2c3d4e5f6a7b8');
$articles->delete($article, ['forceDelete' => true]);
```

## Configuration

```php
class ArticlesCollection extends Collection
{
    public function initialize(array $config): void
    {
        $this->addBehavior('SoftDelete', [
            'field' => 'deleted_at',      // the timestamp field
            'withDeletedOption' => 'withDeleted', // find option to include deleted
        ]);
    }
}
```

The `field` should be a nullable timestamp field in the collection schema.
Add an index on it if you filter on `deleted_at` frequently.

## Notes and Limitations

- Soft deletes are implemented in `Collection.beforeDelete`: the behavior
  performs the timestamp update and stops the actual delete event, so
  `afterDelete` / `afterDeleteCommit` callbacks do **not** fire for soft
  deletes. Instead, the behavior dispatches a dedicated
  `Collection.afterSoftDelete` event (with `document` and `options` payload)
  so other behaviors and application code can react. `CounterCacheBehavior`
  subscribes to it and keeps parent counters in sync automatically.
- A soft-deleted document is excluded from counter-cache counts because
  `CounterCacheBehavior` explicitly adds the SoftDelete field condition when
  that behavior is attached to the same collection. (A bare `count()` query
  does not re-run `beforeFind`; use the `withDeleted` option or filter
  explicitly if you need to count soft-deleted documents.)
- `deleteAll()` / `updateAll()` run raw queries and are **not** intercepted —
  they permanently remove or modify documents regardless of the behavior.
- Soft-deleted documents are still included in any query that runs on the
  raw collection connection, and in counter caches attached to a collection
  that does **not** have the SoftDelete behavior — exclude them explicitly
  when needed (e.g. with a condition on the `field`).
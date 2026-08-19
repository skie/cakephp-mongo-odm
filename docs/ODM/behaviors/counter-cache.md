---
title: "CounterCache"
description: "Maintain count caches in the Crustum Mongo ODM: use CounterCacheBehavior to automatically update related record counts for better performance."
---

# CounterCache

`class` Crustum\Mongo\ODM\Behavior\**CounterCacheBehavior**

> ### PENDING
>
> The examples below are ported from the Cake ORM cookbook and verified against
> `src/ODM/Behavior/CounterCacheBehavior.php`. They have not been executed
> against a live `test_mongo_db` yet — run them before removing this banner.

Often times web applications need to display counts of related objects. For
example, when showing a list of articles you may want to display how many
comments it has. Or when showing a user you might want to show how many
friends/followers she has. The CounterCache behavior is intended for these
situations. CounterCache will update a field in the associated collections
assigned in the options when it is invoked. The fields should exist in the
schema and hold integer counts.

## Basic Usage

You enable the CounterCache behavior like any other behavior, but it won't do
anything until you configure some relations and the field counts that should be
stored on each of them. Using our example below, we could cache the comment
count for each article with the following:

```php
class CommentsCollection extends Collection
{
    public function initialize(array $config): void
    {
        $this->addBehavior('CounterCache', [
            'Articles' => ['comment_count'],
        ]);
    }
}
```

> [!NOTE]
> The field `comment_count` should exist in the `articles` collection schema.

The CounterCache configuration should be a map of relation names and the
specific configuration for that relation.

As you see you need to add the behavior on the "other side" of the association
where you actually want the field to be updated. In this example the behavior
is added to the `CommentsCollection` even though it updates the
`comment_count` field in the `ArticlesCollection`.

The counter's value will be updated each time a document is saved or deleted.
The counter **will not** be updated when you

- save the document without changing data or
- use `updateAll()` or
- use `deleteAll()` or
- run your own raw queries against the collection

## Advanced Usage

If you need to keep a cached counter for less than all the related records,
you can supply additional conditions or finder methods to generate a
counter value:

```php
// Use a specific find method.
// In this case find(published)
$this->addBehavior('CounterCache', [
    'Articles' => [
        'comment_count' => [
            'finder' => 'published',
        ],
    ],
]);
```

If you don't have a custom finder method you can provide an array of conditions
to find records instead:

```php
$this->addBehavior('CounterCache', [
    'Articles' => [
        'comment_count' => [
            'conditions' => ['Comments.spam' => false],
        ],
    ],
]);
```

If you want CounterCache to update multiple fields, for example both showing a
conditional count and a basic count you can add these fields in the array:

```php
$this->addBehavior('CounterCache', [
    'Articles' => [
        'comment_count',
        'published_comment_count' => [
            'finder' => 'published',
        ],
    ],
]);
```

If you want to calculate the CounterCache field value on your own, you can set
the `ignoreDirty` option to `true`. This will prevent the field from being
recalculated if you've set it dirty before:

```php
$this->addBehavior('CounterCache', [
    'Articles' => [
        'comment_count' => [
            'ignoreDirty' => true,
        ],
    ],
]);
```

Lastly, if a custom finder and conditions are not suitable you can provide
a callback function. Your function must return the count value to be stored:

```php
$this->addBehavior('CounterCache', [
    'Articles' => [
        'rating_avg' => function ($event, $document, $collection, $original) {
            return 4.5;
        },
    ],
]);
```

Your function can return `false` to skip updating the counter field. The
`$collection` parameter refers to the collection object holding the behavior
(not the target relation) for convenience. The callback is invoked at least
once with `$original` set to `false`. If the document-update changes the
association then the callback is invoked a *second* time with `true`, the
return value then updates the counter of the *previously* associated item.

> [!NOTE]
> The CounterCache behavior works for `belongsTo` associations only. For
> example for "Comments belongsTo Articles", you need to add the CounterCache
> behavior to the `CommentsCollection` in order to generate `comment_count` for
> the Articles collection.

In the ODM the count is always computed with a separate count query which is
then written back with an `updateAll()` assignment. The behavior does not use
SQL sub-queries and has no `useSubQuery` option.

## Manually updating counter caches

`method` Crustum\Mongo\ODM\Behavior\CounterCacheBehavior::**updateCounterCache**(?string $assocName = null, int $limit = 100, ?int $page = null): void

The `updateCounterCache()` method allows you to update the counter cache values
for all records of one or all configured associations in batches. This can be
useful, for example, to update the counter cache after importing data directly
into the database.

```php
// Update the counter cache for all configured associations
$comments->getBehavior('CounterCache')->updateCounterCache();

// Update the counter cache for a specific association, 200 records per batch
$comments->getBehavior('CounterCache')->updateCounterCache('Articles', 200);

// Update only the first page of records
$comments->getBehavior('CounterCache')->updateCounterCache('Articles', page: 1);
```

> [!NOTE]
> This method won't update the counter cache values for fields which are
> configured to use a closure to get the count value.

## Working with SoftDelete

When the source collection also has the `SoftDelete` behavior attached, the
counter cache stays in sync automatically:

- The counter count is computed with the SoftDelete filter applied: the
  behavior adds the configured SoftDelete field condition to the count query
  whenever that behavior is attached to the same collection, so soft-deleted
  records are excluded.
- A soft delete stops the normal `afterDelete` event, so the behavior listens
  to the dedicated `Collection.afterSoftDelete` event (dispatched by
  `SoftDeleteBehavior`) and updates the parent counter through that path.

```php
$this->Comments->belongsTo('Articles');

$this->Comments->addBehavior('CounterCache', [
    'Articles' => ['comment_count'],
]);
$this->Comments->addBehavior('SoftDelete');

// Deleting a comment now decrements `article.comment_count`
$this->Comments->delete($comment);

// `forceDelete` takes the physical delete path, so `afterDelete` fires
// and the counter is updated as usual.
$this->Comments->delete($comment, ['forceDelete' => true]);
```
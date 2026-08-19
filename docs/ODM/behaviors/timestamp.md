---
title: "Timestamp"
description: "Auto-update timestamps in the Crustum Mongo ODM: use TimestampBehavior to manage created/modified fields automatically on save operations."
---

# Timestamp

`class` Crustum\Mongo\ODM\Behavior\**TimestampBehavior**

> ### PENDING
>
> The examples below are ported from the Cake ORM cookbook and verified against
> `src/ODM/Behavior/TimestampBehavior.php`. They have not been executed against
> a live `test_mongo_db` yet — run them before removing this banner.

The timestamp behavior allows your collection objects to update one or more
timestamps on each model event. This is primarily used to populate data into
`created` and `modified` fields. However, with some additional configuration,
you can update any timestamp/datetime field on any event a collection publishes.

## Basic Usage

You enable the timestamp behavior like any other behavior:

```php
class ArticlesCollection extends Collection
{
    public function initialize(array $config): void
    {
        $this->addBehavior('Timestamp');
    }
}
```

The default configuration will do the following:

- When a new document is saved the `created` and `modified` fields will be set
  to the current time.
- When a document is updated, the `modified` field is set to the current time.

## Using and Configuring the Behavior

If you need to modify fields with different names, or want to update additional
timestamp fields on custom events you can use some additional configuration:

```php
class OrdersCollection extends Collection
{
    public function initialize(array $config): void
    {
        $this->addBehavior('Timestamp', [
            'events' => [
                'Collection.beforeSave' => [
                    'created_at' => 'new',
                    'updated_at' => 'always',
                ],
                'Orders.completed' => [
                    'completed_at' => 'always',
                ],
            ],
        ]);
    }
}
```

As you can see above, in addition to the standard `Collection.beforeSave`
event, we are also updating the `completed_at` field when orders are completed.
The behavior reads the event name from the dispatched event, so a custom event
must actually be fired — for example through the collection's event manager:

```php
$orders->getEventManager()->dispatch('Orders.completed', compact('order'));
```

## Updating Timestamps on Documents

Sometimes you'll want to update just the timestamps on a document without
changing any other properties. This is sometimes referred to as 'touching'
a record. In the ODM you can use the `touch()` method to do exactly this:

```php
// Touch based on the Collection.beforeSave event.
$articles->getBehavior('Timestamp')->touch($article);

// Touch based on a specific event.
$orders->getBehavior('Timestamp')->touch($order, 'Orders.completed');
```

After you have saved the document, the field is updated.

Touching records can be useful when you want to signal that a parent resource
has changed when a child resource is created/updated. For example: updating an
article when a new comment is added.

## Saving Updates Without Modifying Timestamps

To disable the automatic modification of the `modified` timestamp field when
saving a document you can mark the attribute as 'dirty'. The behavior leaves
dirty timestamp fields alone:

```php
// Mark the modified field as dirty, making the current value be set on update.
$order->setDirty('modified', true);
```
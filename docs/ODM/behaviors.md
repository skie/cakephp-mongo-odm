# Behaviors

`class` Crustum\Mongo\ODM\Behavior

Behaviors are a way to organize and enable horizontal re-use of Model layer
logic. Conceptually they are similar to traits. However, behaviors are
implemented as separate classes. This allows them to hook into the life-cycle
callbacks that collections emit, while providing trait-like features.

Behaviors provide a convenient way to package up behavior that is common across
many collections. For example, Crustum includes a `TimestampBehavior`. Many
collections will want timestamp fields, and the logic to manage these fields is
not specific to any one collection. It is these kinds of scenarios that behaviors
are a perfect fit for.

> ### PENDING
>
> This page is ported from `docs/orm/behaviors.md`. The samples and claims below
> are verified against `src/ODM/Behavior.php`, `src/ODM/BehaviorRegistry.php`,
> `src/ODM/Behavior/` (`TimestampBehavior`, `TranslateBehavior`,
> `CounterCacheBehavior`, `TreeBehavior`, `SoftDeleteBehavior`), and
> `src/ODM/Marshaller.php`. Samples still need to run against a live
> `test_mongo_db` before this banner is removed.

## Using Behaviors

`method` Crustum\Mongo\ODM\BaseCollection::**addBehavior**(string $name, array $options = []): static

Behaviors provide a way to create horizontally re-usable pieces of logic
related to collection classes. You may be wondering why behaviors are regular
classes and not traits. The primary reason for this is event listeners. While
traits would allow for re-usable pieces of logic, they would complicate binding
events.

To add a behavior to your collection you can call the `addBehavior()` method.
Generally the best place to do this is in the `initialize()` method:

```php
namespace App\Model\Collection;

use Crustum\Mongo\ODM\BaseCollection;

class ArticlesCollection extends BaseCollection
{
    public function initialize(array $config): void
    {
        $this->addBehavior('Timestamp');
    }
}
```

As with associations, you can use `plugin syntax` and provide additional
configuration options:

```php
$this->addBehavior('Timestamp', [
    'events' => [
        'Collection.beforeSave' => [
            'created_at' => 'new',
            'modified_at' => 'always',
        ],
    ],
]);
```

You can also attach several behaviors at once with `addBehaviors()`:

```php
$this->addBehaviors([
    'Timestamp',
    'CounterCache' => ['Users' => ['post_count']],
]);
```

By default behavior event listeners run in the order the behaviors were added.
You can control the ordering with the `priority` option:

```php
$this->addBehavior('Tree', [
    'priority' => 2,
]);
```

For callback priorities see [Lifecycle Callbacks](../ODM/collections#lifecycle-callbacks).

## Core Behaviors

Crustum bundles these behaviors:

- [CounterCache](../ODM/behaviors/counter-cache)
- [Timestamp](../ODM/behaviors/timestamp)
- [Translate](../ODM/behaviors/translate)
- [Tree](../ODM/behaviors/tree)
- [SoftDelete](../ODM/behaviors/soft-delete)

## Creating a Behavior

In the following examples we will create a very simple `SluggableBehavior`.
This behavior will allow us to populate a slug field with the results of
`Text::slug()` based on another field.

Before we create our behavior we should understand the conventions for
behaviors:

- App-level behavior files are located in **src/Model/MongoBehavior** (or
  `MyPlugin\Model\MongoBehavior`). The registry also resolves app-level
  behaviors from **src/ODM/Behavior**, and the plugin's own behaviors live in
  `Crustum\Mongo\ODM\Behavior\`.
- App-level behavior classes should be in the `App\Model\MongoBehavior`
  namespace (or `MyPlugin\Model\MongoBehavior`).
- Behavior class names end in `Behavior`.
- Behaviors extend `Crustum\Mongo\ODM\Behavior`.

To create our sluggable behavior, put the following into
**src/Model/MongoBehavior/SluggableBehavior.php**:

```php
namespace App\Model\MongoBehavior;

use Crustum\Mongo\ODM\Behavior;

class SluggableBehavior extends Behavior
{
}
```

Similar to collections, behaviors also have an `initialize()` hook where you can
put your behavior's initialization code, if required:

```php
public function initialize(array $config): void
{
    // Some initialization code here
}
```

We can now add this behavior to one of our collection classes. In this example
we'll use an `ArticlesCollection`, as articles often have slug properties for
creating friendly URLs:

```php
namespace App\Model\Collection;

use Crustum\Mongo\ODM\BaseCollection;

class ArticlesCollection extends BaseCollection
{
    public function initialize(array $config): void
    {
        $this->addBehavior('Sluggable');
    }
}
```

Our new behavior doesn't do much of anything right now. Next, we'll add a method
and an event listener so that when we save documents we can automatically slug a
field.

### Calling behavior methods

Public methods on behaviors can be called as normal methods:

```php
$articles->getBehavior('Sluggable')->slug($value);
```

The ODM registry also exposes behavior methods explicitly through `call()`.
There is no magic `$articles->slug()` mixin forwarding — method dispatch is
always explicit:

```php
$articles->behaviors()->call('slug', $value);
```

If two behaviors expose the same method through the registry an exception is
raised when the second is loaded. For example, if our SluggableBehavior defined
the following method:

```php
public function slug(string $value): string
{
    return Text::slug($value, $this->getConfig('replacement'));
}
```

It could be invoked using:

```php
$slug = $articles->getBehavior('Sluggable')->slug('My article');
```

#### Limiting or Renaming Exposed Methods

When creating behaviors, there may be situations where you don't want to expose
public methods through the registry. In these cases you can use the
`implementedMethods` configuration key to rename or exclude methods. For
example if we wanted to prefix our slug() method we could do the following:

```php
protected array $defaultConfig = [
    'implementedMethods' => [
        'superSlug' => 'slug',
    ],
];
```

Applying this configuration will make `behaviors()->call('slug')` raise an
error, however it will add a `superSlug()` method to the registry. Notably if
our behavior implemented other public methods they would **not** be available
with the above configuration.

Since the exposed methods are decided by configuration you can also
rename/remove methods when adding a behavior to a collection. For example:

```php
// In a collection's initialize() method.
$this->addBehavior('Sluggable', [
    'implementedMethods' => [
        'superSlug' => 'slug',
    ],
]);
```

### Defining Event Listeners

Now that our behavior has a method to slug fields, we can implement a callback
listener to automatically slug a field when documents are saved. We'll also
modify our slug method to accept a document instead of just a plain value. Our
behavior should now look like:

```php
namespace App\Model\MongoBehavior;

use ArrayObject;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Crustum\Mongo\ODM\Behavior;
use Cake\Utility\Text;

class SluggableBehavior extends Behavior
{
    protected array $defaultConfig = [
        'field' => 'title',
        'slug' => 'slug',
        'replacement' => '-',
    ];

    public function slug(EntityInterface $document): void
    {
        $config = $this->getConfig();
        $value = $document->get($config['field']);
        $document->set($config['slug'], Text::slug($value, $config['replacement']));
    }

    public function beforeSave(EventInterface $event, EntityInterface $document, ArrayObject $options): void
    {
        $this->slug($document);
    }
}
```

The above code shows a few interesting features of behaviors:

- Behaviors can define callback methods by defining methods that follow the
  [Lifecycle Callbacks](../ODM/collections#lifecycle-callbacks) conventions.
  The callbacks fire on the `Collection.*` events (`Collection.beforeSave`,
  `Collection.afterSave`, `Collection.beforeFind`, and so on).
- Behaviors can define a default configuration property. This property is merged
  with the overrides when a behavior is attached to the collection.

To prevent the save from continuing, simply stop event propagation in your callback:

```php
public function beforeSave(EventInterface $event, EntityInterface $document, ArrayObject $options): void
{
    if (...) {
        $event->stopPropagation();
        $event->setResult(false);

        return;
    }
    $this->slug($document);
}
```

Alternatively, you can return false from the callback. This has the same effect
as stopping event propagation.

You can also load a behavior without subscribing its event listeners. The
`enabled` option registers the behavior (its finders and methods stay
available) but does not attach it to the event manager:

```php
$this->addBehavior('Sluggable', ['enabled' => false]);
```

### Defining Finders

Now that we are able to save articles with slug values, we should implement a
finder method so we can fetch articles by their slug. Behavior finder methods
use the same conventions as [Custom Finder Methods](../ODM/retrieving-data-and-resultsets#custom-finder-methods)
do. Our `find('slug')` method would look like:

```php
public function findSlug(SelectQuery $query, string $slug): SelectQuery
{
    return $query->where(['slug' => $slug]);
}
```

Once our behavior has the above method we can call it:

```php
$article = $articles->find('slug', slug: $value)->first();
```

#### Limiting or Renaming Exposed Finder Methods

When creating behaviors, there may be situations where you don't want to expose
finder methods, or you need to rename finders to avoid duplicated methods. In
these cases you can use the `implementedFinders` configuration key to rename or
exclude finder methods. For example if we wanted to rename our `find(slug)`
method we could do the following:

```php
protected array $defaultConfig = [
    'implementedFinders' => [
        'slugged' => 'findSlug',
    ],
];
```

Applying this configuration will make `find('slug')` trigger an error. However
it will make `find('slugged')` available. Notably if our behavior implemented
other finder methods they would **not** be available, as they are not included
in the configuration.

Since the exposed methods are decided by configuration you can also
rename/remove finder methods when adding a behavior to a collection. For
example:

```php
// In a collection's initialize() method.
$this->addBehavior('Sluggable', [
    'implementedFinders' => [
        'slugged' => 'findSlug',
    ],
]);
```

## Transforming Request Data into Entity Properties

Behaviors can define logic for how the custom fields they provide are
marshalled by implementing the `Crustum\Mongo\ODM\PropertyMarshalInterface`.
This interface requires a single method to be implemented:

```php
use Crustum\Mongo\ODM\Marshaller;

public function buildMarshalMap(Marshaller $marshaller, array $map, array $options): array
{
    return [
        'custom_behavior_field' => function ($value, $entity) {
            // Transform the value as necessary
            return $value . '123';
        },
    ];
}
```

The `TranslateBehavior` has a non-trivial implementation of this interface
that you might want to refer to.

## Removing Loaded Behaviors

To remove a behavior from your collection you can call the `removeBehavior()`
method:

```php
// Remove the loaded behavior
$this->removeBehavior('Sluggable');
```

## Accessing Loaded Behaviors

Once you've attached behaviors to your Collection instance you can introspect
the loaded behaviors, or access specific behaviors using the `BehaviorRegistry`:

```php
// See which behaviors are loaded
$articles->behaviors()->loaded();

// Check if a specific behavior is loaded.
// Remember to omit plugin prefixes.
$articles->behaviors()->has('CounterCache');

// Get a loaded behavior
// Remember to omit plugin prefixes
$articles->behaviors()->get('CounterCache');
```

### Re-configuring Loaded Behaviors

To modify the configuration of an already loaded behavior you can combine the
`BehaviorRegistry::get` command with the `setConfig()` command provided by the
`InstanceConfigTrait` trait.

For example, if a parent class, such as `AppCollection`, loaded the `Timestamp`
behavior you could do the following to add, modify or remove the configurations
for the behavior. In this case, we will add an event we want Timestamp to
respond to:

```php
// In a collection's initialize() method.
if ($this->behaviors()->has('Timestamp')) {
    $this->behaviors()->get('Timestamp')->setConfig('events', [
        'Collection.beforeSave' => ['created' => 'new', 'modified' => 'always'],
        'Users.login' => ['last_login' => 'always'],
    ]);
}
```

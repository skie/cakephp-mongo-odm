# Collection Objects

> ### PENDING
>
> This page is ported from the Cake ORM cookbook page
> `docs/orm/table-objects.md` in full. Creating collection classes, the
> `initialize()` hook, naming conventions (`ArticlesCollection` → `articles`),
> the document class assertion, `fetchCollection()` / `CollectionLocator`
> usage, lifecycle callbacks, event priorities, behaviors and connection
> configuration are all covered here.
>
> Every claim and code sample was checked against
> `crustum/src/ODM/BaseCollection.php`, `crustum/src/ODM/CollectionEventsTrait.php`,
> `crustum/src/ODM/Locator/CollectionLocator.php`,
> `crustum/src/ODM/CollectionRegistry.php` and
> `tests/TestCase/ODM/BaseCollectionTest.php`. Before the `### PENDING` banner
> is removed, the remaining samples must be run against a live test database
> (see `docs/working-memory-docs/53-collections-diffs.md`).

`class` Crustum\Mongo\ODM\**BaseCollection**

Collection objects provide access to the collection of documents stored in a
MongoDB collection. Each collection in your application should have an
associated collection class which is used to interact with that collection. If
you do not need to customize the behavior of a given collection, the ODM will
generate a `BaseCollection` instance for you to use.

## Basic Usage

To get started, create a collection class. These classes live in
**src/Model/Collection**. Collection objects are the main interface to your
database in the ODM. The most basic collection class would look like:

```php
// src/Model/Collection/ArticlesCollection.php
namespace App\Model\Collection;

use Crustum\Mongo\ODM\BaseCollection;

class ArticlesCollection extends BaseCollection
{
}
```

Note that we did not tell the ODM which collection to use for our class. By
convention collection objects will use a collection that matches the lower
cased and underscored version of the class name. In the above example the
`articles` collection will be used. If our collection class was named
`BlogPosts` your collection should be named `blog_posts`. You can specify the
collection to use by using the `setCollection()` method:

```php
namespace App\Model\Collection;

use Crustum\Mongo\ODM\BaseCollection;

class ArticlesCollection extends BaseCollection
{
    public function initialize(array $config): void
    {
        $this->setCollection('my_collection');
    }
}
```

No inflection conventions will be applied when specifying a collection. By
convention the ODM also expects each collection to have a primary key with the
name of `_id`. If you need to modify this you can use the `setPrimaryKey()`
method:

```php
namespace App\Model\Collection;

use Crustum\Mongo\ODM\BaseCollection;

class ArticlesCollection extends BaseCollection
{
    public function initialize(array $config): void
    {
        $this->setPrimaryKey('my_id');
    }
}
```

### Customizing the Document Class a Collection Uses

By default, collection objects use a document class based on naming
conventions. For example if your collection class is called
`ArticlesCollection` the document would be `Article`. If the collection class
was `PurchaseOrdersCollection` the document would be `PurchaseOrder`. If
however, you want to use a document that doesn't follow the conventions you can
use the `setDocumentClass()` method to change things up:

```php
class PurchaseOrdersCollection extends BaseCollection
{
    public function initialize(array $config): void
    {
        $this->setDocumentClass('App\Model\Document\PO');
    }
}
```

As seen in the examples above collection objects have an `initialize()` method
which is called at the end of the constructor. It is recommended that you use
this method to do initialization logic instead of overriding the constructor.

Collection methods `save()`, `delete()`, `patchDocument()` and `loadInto()`
will throw an exception if the document being passed down does not belong to
the collection instance. This prevents accidental data corruption or deleted
records.

If you don't want this behavior, you can disable it by calling
`$this->disableDocumentClassAssertion();` in your `initialize()` method.

### Getting Instances of a Collection Class

Before you can query a collection, you'll need to get an instance of it. You
can do this by using the `CollectionLocator` class, which is registered in the
datasource `FactoryLocator` under the `Collection` key by the Mongo plugin's
`bootstrap()`:

```php
// In a controller

$articles = $this->fetchCollection('Articles');
```

`CollectionLocator` provides the various dependencies for constructing a
collection, and maintains a registry of all the constructed collection
instances making it easier to build relations and configure the ODM. See
[Collection Locator Usage](#collection-locator-usage) for more information.

If your collection class is in a plugin, be sure to use the correct name for
your collection class. Failing to do so can result in validation rules, or
callbacks not being triggered as a default class is used instead of your actual
class. To correctly load plugin collection classes use the following:

```php
// Plugin collection
$articlesCollection = $this->fetchCollection('PluginName.Articles');

// Vendor prefixed plugin collection
$articlesCollection = $this->fetchCollection('VendorName/PluginName.Articles');
```

<a id="collection-callbacks"></a>

## Lifecycle Callbacks

As you have seen above collection objects trigger a number of events. Events
are useful if you want to hook into the ODM and add logic in without
subclassing or overriding methods. Event listeners can be defined in collection
or behavior classes. You can also use a collection's event manager to bind
listeners in.

When using callback methods behaviors attached in the `initialize()` method
will have their listeners fired **before** the collection callback methods are
triggered. This follows the same sequencing as controllers & components.

To add an event listener to a collection class or behavior simply implement the
method signatures as described below:

```php
// In a controller
$articles->save($article, ['customVariable1' => 'yourValue1']);

// In ArticlesCollection.php
public function afterSave(Event $event, EntityInterface $document, ArrayObject $options): void
{
    $customVariable = $options['customVariable1'];  // 'yourValue1'
    $options['customVariable2'] = 'yourValue2';
}

public function afterSaveCommit(Event $event, EntityInterface $document, ArrayObject $options): void
{
    $customVariable = $options['customVariable1'];  // 'yourValue1'
    $customVariable = $options['customVariable2'];  // 'yourValue2'
}
```

### Event List

- `Collection.initialize`
- `Collection.beforeMarshal`
- `Collection.afterMarshal`
- `Collection.beforeFind`
- `Collection.buildValidator`
- `Collection.buildRules`
- `Collection.beforeRules`
- `Collection.afterRules`
- `Collection.beforeSave`
- `Collection.afterSave`
- `Collection.afterSaveCommit`
- `Collection.beforeDelete`
- `Collection.afterDelete`
- `Collection.afterDeleteCommit`

### initialize

`method` Crustum\Mongo\ODM\BaseCollection::**initialize**(EventInterface $event, ArrayObject $data, ArrayObject $options): void

The `Collection.initialize` event is fired after the constructor and
`initialize()` methods are called. The collection classes do not listen to this
event by default, and instead use the `initialize()` hook method.

To respond to the `Collection.initialize` event you can create a listener class
which implements `EventListenerInterface`:

```php
use Cake\Event\EventListenerInterface;
class CollectionInitializeListener implements EventListenerInterface
{
    public function implementedEvents(): array
    {
        return [
            'Collection.initialize' => 'initializeEvent',
        ];
    }

    public function initializeEvent($event): void
    {
        $collection = $event->getSubject();
        // do something here
    }
}
```

and attach the listener to the `EventManager` as below:

```php
use Cake\Event\EventManager;
$listener = new CollectionInitializeListener();
EventManager::instance()->attach($listener);
```

This will call the `initializeEvent` when any collection class is constructed.

### beforeMarshal

`method` Crustum\Mongo\ODM\BaseCollection::**beforeMarshal**(EventInterface $event, ArrayObject $data, ArrayObject $options): void

The `Collection.beforeMarshal` event is fired before request data is converted
into documents. See the [Before Marshal](../ODM/saving-data#before-marshal)
documentation for more information.

### afterMarshal

`method` Crustum\Mongo\ODM\BaseCollection::**afterMarshal**(EventInterface $event, EntityInterface $document, ArrayObject $data, ArrayObject $options): void

The `Collection.afterMarshal` event is fired after request data is converted
into documents. Event handlers will get the converted documents, original
request data and the options provided to the `patchDocument()` or
`newDocument()` call.

### beforeFind

`method` Crustum\Mongo\ODM\BaseCollection::**beforeFind**(EventInterface $event, SelectQuery $query, ArrayObject $options, bool $primary): void

The `Collection.beforeFind` event is fired before each find operation. By
stopping the event, and feeding the query with a custom result set, you can
bypass the find operation entirely:

```php
public function beforeFind(EventInterface $event, SelectQuery $query, ArrayObject $options, $primary): void
{
    if (/* ... */) {
        $event->stopPropagation();
        $query->setResult(new \Cake\Datasource\ResultSetDecorator([]));

        return;
    }
    // ...
}
```

In this example, no further `beforeFind` events will be triggered on the
related collection or its attached behaviors (though behavior events are
usually invoked earlier given their default priorities), and the query will
return the empty result set that was passed via `SelectQuery::setResult()`.

Any changes done to the `$query` instance will be retained for the rest of the
find. The `$primary` parameter indicates whether or not this is the root query,
or an associated query. All associations participating in a query will have a
`Collection.beforeFind` event triggered. In your event listener you can set
additional fields, conditions or result formatters. These options/features
will be copied onto the root query.

### buildValidator

`method` Crustum\Mongo\ODM\BaseCollection::**buildValidator**(EventInterface $event, Validator $validator, $name): void

The `Collection.buildValidator` event is fired when `$name` validator is
created. Behaviors can use this hook to add in validation methods.

### buildRules

`method` Crustum\Mongo\ODM\BaseCollection::**buildRules**(RulesChecker $rules): RulesChecker

The `Collection.buildRules` event is fired after a rules instance has been
created and after the `BaseCollection::buildRules()` method has been called.

### beforeRules

`method` Crustum\Mongo\ODM\BaseCollection::**beforeRules**(EventInterface $event, EntityInterface $document, ArrayObject $options, string $operation): void

The `Collection.beforeRules` event is fired before a document has had rules
applied. By stopping this event, you can halt the rules checking and set the
result of applying rules.

### afterRules

`method` Crustum\Mongo\ODM\BaseCollection::**afterRules**(EventInterface $event, EntityInterface $document, ArrayObject $options, bool $result, string $operation): void

The `Collection.afterRules` event is fired after a document has rules applied.
By stopping this event, you can return the final value of the rules checking
operation.

### beforeSave

`method` Crustum\Mongo\ODM\BaseCollection::**beforeSave**(EventInterface $event, EntityInterface $document, ArrayObject $options): void

The `Collection.beforeSave` event is fired before each document is saved.
Stopping this event will abort the save operation. When the event is stopped
the result of the event will be returned.

### afterSave

`method` Crustum\Mongo\ODM\BaseCollection::**afterSave**(EventInterface $event, EntityInterface $document, ArrayObject $options): void

The `Collection.afterSave` event is fired after a document is saved.

### afterSaveCommit

`method` Crustum\Mongo\ODM\BaseCollection::**afterSaveCommit**(EventInterface $event, EntityInterface $document, ArrayObject $options): void

The `Collection.afterSaveCommit` event is fired after the transaction in which
the save operation is wrapped has been committed. It's also triggered for non
atomic saves where database operations are implicitly committed. The event is
triggered only for the primary collection on which `save()` is directly called.
The event is not triggered if a transaction is started before calling save.

### beforeDelete

`method` Crustum\Mongo\ODM\BaseCollection::**beforeDelete**(EventInterface $event, EntityInterface $document, ArrayObject $options): void

The `Collection.beforeDelete` event is fired before a document is deleted. By
stopping this event you will abort the delete operation. When the event is
stopped the result of the event will be returned.

### afterDelete

`method` Crustum\Mongo\ODM\BaseCollection::**afterDelete**(EventInterface $event, EntityInterface $document, ArrayObject $options): void

The `Collection.afterDelete` event is fired after a document has been deleted.

### afterDeleteCommit

`method` Crustum\Mongo\ODM\BaseCollection::**afterDeleteCommit**(EventInterface $event, EntityInterface $document, ArrayObject $options): void

The `Collection.afterDeleteCommit` event is fired after the transaction in which
the delete operation is wrapped has been committed. It's also triggered for non
atomic deletes where database operations are implicitly committed. The event is
triggered only for the primary collection on which `delete()` is directly
called. The event is not triggered if a transaction is started before calling
delete.

### Stopping Collection Events

To prevent the save from continuing, simply stop event propagation in your
callback:

```php
public function beforeSave(EventInterface $event, EntityInterface $document, ArrayObject $options): void
{
    if (...) {
        $event->stopPropagation();
        $event->setResult(false);

        return;
    }
    ...
}
```

Alternatively, you can return false from the callback. This has the same effect
as stopping event propagation.

### Callback priorities

When using events on your collections and behaviors be aware of the priority
and the order listeners are attached. Behavior events are attached before
collection events are. With the default priorities this means that behavior
callbacks are triggered **before** the collection event with the same name.

As an example, if your collection is using `TreeBehavior` the
`TreeBehavior::beforeDelete()` method will be called before your collection's
`beforeDelete()` method, and you will not be able to work with the child nodes
of the record being deleted in your collection's method.

You can manage event priorities in one of a few ways:

1. Change the `priority` of a behavior's listeners using the `priority`
    option. This will modify the priority of **all** callback methods in the
    behavior:

    ```php
    // In a collection initialize() method
    $this->addBehavior('Tree', [
        // Default value is 10 and listeners are dispatched from the
        // lowest to highest priority.
        'priority' => 2,
    ]);
    ```

2. Modify the `priority` in your collection class by using the
    `Collection.implementedEvents()` method. This allows you to assign a
    different priority per callback-function:

    ```php
    // In a collection class.
    public function implementedEvents(): array
    {
        $events = parent::implementedEvents();
        $events['Collection.beforeDelete'] = [
            'callable' => 'beforeDelete',
            'priority' => 3,
        ];

        return $events;
    }
    ```

## Behaviors

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
namespace App\Model\Collection;

use Crustum\Mongo\ODM\BaseCollection;

class ArticlesCollection extends BaseCollection
{
    public function initialize(array $config): void
    {
        $this->addBehavior('Timestamp', [
            'events' => [
                'Collection.beforeSave' => [
                    'created' => 'new',
                    'modified' => 'always',
                ]
            ]
        ]);
    }
}
```

You can find out more about behaviors in the chapter on
[Behaviors](../ODM/behaviors).

<a id="configuring-collection-connections"></a>

## Configuring Connections

By default, all collection instances use the `mongo` connection. If your
application uses multiple database connections you will want to configure which
collections use which connections. This is the `defaultConnectionName()` method:

```php
namespace App\Model\Collection;

use Crustum\Mongo\ODM\BaseCollection;

class ArticlesCollection extends BaseCollection
{
    public static function defaultConnectionName(): string {
        return 'replica_db';
    }
}
```

> [!NOTE]
> The `defaultConnectionName()` method **must** be static.

<a id="collection-locator-usage"></a>

## Using the CollectionLocator

`class` Crustum\Mongo\ODM\Locator\**CollectionLocator**

As we've seen earlier, the CollectionLocator class provides a way to use a
factory/registry for accessing your applications collection instances. It
provides a few other useful features as well.

### Configuring Collection Objects

`method` Crustum\Mongo\ODM\Locator\CollectionLocator::**get**(string $alias, array $options = []): RepositoryInterface

When loading collections from the registry you can customize their
dependencies, or use mock objects by providing an `$options` array:

```php
use Cake\Datasource\FactoryLocator;

$articles = FactoryLocator::get('Collection')->get('Articles', [
    'className' => 'App\Custom\ArticlesCollection',
    'collection' => 'my_articles',
    'connection' => $connectionObject,
    'schema' => $schemaObject,
    'documentClass' => 'Custom\DocumentClass',
    'eventManager' => $eventManager,
    'behaviors' => $behaviorRegistry,
]);
```

Pay attention to the connection and schema configuration settings, they aren't
string values but objects. The connection will take an object of
`Cake\Database\Connection` and schema an object implementing
`Crustum\Mongo\ODM\Schema\SchemaInterface`.

> [!NOTE]
> If your collection also does additional configuration in its `initialize()`
> method, those values will overwrite the ones provided to the registry.

You can also pre-configure the registry using the `setConfig()` method.
Configuration data is stored *per alias*, and can be overridden by an object's
`initialize()` method:

```php
FactoryLocator::get('Collection')->setConfig('Users', ['collection' => 'my_users']);
```

> [!NOTE]
> You can only configure a collection before or during the **first** time you
> access that alias. Doing it after the registry is populated will have no
> effect.

### Flushing the Registry

`method` Crustum\Mongo\ODM\Locator\CollectionLocator::**clear**(): void

During test cases you may want to flush the registry. Doing so is often useful
when you are using mock objects, or modifying a collection's dependencies:

```php
FactoryLocator::get('Collection')->clear();
```

### Configuring the Namespace to Locate ODM Classes

If you have not followed the conventions it is likely that your collection or
document classes will not be detected by the ODM. In order to fix this, you can
set a namespace with the `Cake\Core\Configure::write` method. As an example:

    /src
        /App
            /My
                /Namespace
                    /Model
                        /Collection
                        /Document

Would be configured with:

```php
Cake\Core\Configure::write('App.namespace', 'App\My\Namespace');
```

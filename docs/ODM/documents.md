# Documents

> ### PENDING
>
> This page is ported from the Cake ORM cookbook page
> `docs/orm/entities.md` in full. Creating document classes, direct
> instantiation, `newEmptyDocument()`/`newDocument()`, accessing document data
> (`get`/`set`/`patch`/`has`/`hasValue`), accessors & mutators, virtual fields,
> dirty tracking, validation errors, mass assignment, `isNew()`/`setNew()`,
> lazy loading via `loadInto()`, traits, array/JSON conversion with virtual &
> hidden fields are all covered here.
>
> Every claim and code sample was checked against `crustum/src/ODM/Document.php`,
> `crustum/src/ODM/BaseCollection.php` and `tests/TestCase/ODM/DocumentTest.php`.
> Before the `### PENDING` banner is removed, the remaining samples must be run
> against a live test database (see
> `docs/working-memory-docs/51-documents-diffs.md`).

`class` Crustum\Mongo\ODM\**Document**

While [Collection Objects](../ODM/collections) represent and provide access to a
collection of documents, documents represent individual records or domain
objects in your application. Documents contain methods to manipulate and access
the data they contain. Fields can also be accessed as properties on the object.

Documents are created for you each time you iterate the query instance returned
by `find()` of a collection object, or when you call the `all()` or `first()`
method of the query instance.

## Creating Document Classes

You don't need to create document classes to get started with the ODM. However,
if you want to have custom logic in your documents you will need to create
classes. By convention document classes live in **src/Model/Document/**. If our
application had an `articles` collection we could create the following document:

```php
// src/Model/Document/Article.php
namespace App\Model\Document;

use Crustum\Mongo\ODM\Document;

class Article extends Document
{
}
```

Right now this document doesn't do very much. However, when we load data from
our articles collection, we'll get instances of this class.

> [!NOTE]
> If you don't define a document class the ODM will use the basic `Document`
> class. The class is derived from the collection alias when unset, following
> the `App\Model\Document\<Alias>` convention, and falls back to the generic
> `Crustum\Mongo\ODM\Document`.

## Creating Documents

Documents can be directly instantiated:

```php
use App\Model\Document\Article;

$article = new Article();
```

When instantiating a document you can pass the fields with the data you want
to store in them:

```php
use App\Model\Document\Article;

$article = new Article([
    '_id' => '000000000000000000000001',
    'title' => 'New Article',
    'created' => new DateTime('now'),
]);
```

> [!NOTE]
> In MongoDB the identity field is `_id`. Reading `$doc->id` / `$doc['id']`
> maps to it so cake-style code keeps working.

The preferred way of getting new documents is using the `newEmptyDocument()`
method from the collection objects:

```php
use Crustum\Mongo\ODM\Locator\LocatorAwareTrait;

$article = $this->fetchCollection('Articles')->newEmptyDocument();

$article = $this->fetchCollection('Articles')->newDocument([
    '_id' => '000000000000000000000001',
    'title' => 'New Article',
    'created' => new DateTime('now'),
]);
```

`$article` will be an instance of `App\Model\Document\Article`, or fall back to
`Crustum\Mongo\ODM\Document` if you haven't created the `Article` class.

> [!NOTE]
> The ODM also keeps the cake-style method names `newEmptyEntity()`,
> `newEntity()`, `newEntities()`, `patchEntity()` and `patchEntities()` as
> compatibility aliases that behave exactly like their `Document`
> counterparts.

## Accessing Document Data

Documents provide a few ways to access the data they contain. Most commonly you
will access the data in a document using object notation:

```php
use App\Model\Document\Article;

$article = new Article;
$article->title = 'This is my first post';
echo $article->title;
```

You can also use the `get()` and `set()` methods.

### set()

`method` Crustum\Mongo\ODM\Document::**set**(array|string $field, mixed $value = null, array $options = []): static

### get()

`method` Crustum\Mongo\ODM\Document::**get**(string $field): mixed

For example:

```php
$article->set('title', 'This is my first post');
echo $article->get('title');
```

### patch()

`method` Crustum\Mongo\ODM\Document::**patch**(array $fields, array $options = []): static

Using `patch()` you can mass assign multiple fields at once:

```php
$article->patch([
    'title' => 'My first post',
    'body' => 'It is the best ever!',
]);
```

> [!WARNING]
> When updating documents with request data you should configure which fields
> can be set with mass assignment.

You can check if fields are defined in your documents with `has()`:

```php
$article = new Article([
    'title' => 'First post',
    'user_id' => null,
]);
$article->has('title'); // true
$article->has('user_id'); // true
$article->has('undefined'); // false
```

The `has()` method will return `true` if a field is defined. You can use
`hasValue()` to check if a field contains a 'non-empty' value:

```php
$article = new Article([
    'title' => 'First post',
    'user_id' => null,
    'text' => '',
    'links' => [],
]);
$article->has('title'); // true
$article->hasValue('title'); // true

$article->has('user_id'); // true
$article->hasValue('user_id'); // false

$article->has('text'); // true
$article->hasValue('text'); // false

$article->has('links'); // true
$article->hasValue('links'); // false
```

If you often partially load documents you should enable strict-property access
behavior to ensure you're not using properties that haven't been loaded. On
a per-document basis you can enable this behavior:

```php
$article->requireFieldPresence();
```

Once enabled, accessing properties that are not defined will raise a
`Cake\Datasource\Exception\MissingPropertyException`.

## Accessors & Mutators

In addition to the simple get/set interface, documents allow you to provide
accessors and mutator methods. These methods let you customize how fields
are read or set.

### Accessors

Accessors let you customize how fields are read. They use the convention of
`_get(FieldName)` with `(FieldName)` being the CamelCased version (multiple
words are joined together to a single word with the first letter of each word
capitalized) of the field name.

They receive the basic value stored in the `_fields` array as their only
argument. For example:

```php
namespace App\Model\Document;

use Crustum\Mongo\ODM\Document;

class Article extends Document
{
    protected function _getTitle(string $title): string
    {
        return strtoupper($title);
    }
}
```

The example above converts the value of the `title` field to an uppercase
version each time it is read. It would be run when getting the field through any
of these two ways:

```php
echo $article->title; // returns FOO instead of foo
echo $article->get('title'); // returns FOO instead of foo
```

> [!NOTE]
> Code in your accessors is executed each time you reference the field. You can
> use a local variable to cache it if you are performing a resource-intensive
> operation in your accessor like this: `$myDocumentProp = $document->my_property`.

> [!WARNING]
> Accessors will be used when saving documents, so be careful when defining
> methods that format data, as the formatted data will be persisted.

### Mutators

You can customize how fields get set by defining a mutator. They use the
convention of `_set(FieldName)` with `(FieldName)` being the CamelCased version
of the field name.

Mutators should always return the value that should be stored in the field.
You can also use mutators to set other fields. When doing this,
be careful to not introduce any loops, as the ODM will not prevent infinitely
looping mutator methods. For example:

```php
namespace App\Model\Document;

use Crustum\Mongo\ODM\Document;
use Cake\Utility\Text;

class Article extends Document
{
    protected function _setTitle(string $title): string
    {
        $this->slug = Text::slug($title);

        return strtoupper($title);
    }
}
```

The example above is doing two things: It stores a modified version of the
given value in the `slug` field and stores an uppercase version in the
`title` field. It would be run when setting the field through
any of these two ways:

```php
$user->title = 'foo'; // sets slug field and stores FOO instead of foo
$user->set('title', 'foo'); // sets slug field and stores FOO instead of foo
```

> [!WARNING]
> Accessors are also run before documents are persisted to the database.
> If you want to transform fields but not persist that transformation,
> we recommend using virtual fields as those are not persisted.

<a id="documents-virtual-fields"></a>

### Creating Virtual Fields

By defining accessors you can provide access to fields that do not
actually exist. For example if your users collection has `first_name` and
`last_name` you could create a method for the full name:

```php
namespace App\Model\Document;

use Crustum\Mongo\ODM\Document;

class User extends Document
{
    protected function _getFullName(): string
    {
        return $this->first_name . '  ' . $this->last_name;
    }
}
```

You can access virtual fields as if they existed on the document. The property
name will be the lower case and underscored version of the method (`full_name`):

```php
echo $user->full_name;
echo $user->get('full_name');
```

Do bear in mind that virtual fields cannot be used in finds. If you want
them to be part of JSON or array representations of your documents,
see [Exposing Virtual Fields](#exposing-virtual-fields).

## Checking if a Document Has Been Modified

`method` Crustum\Mongo\ODM\Document::**isDirty**(?string $field = null): bool

You may want to make code conditional based on whether or not fields have
changed in a document. For example, you may only want to validate fields when
they change:

```php
// See if the title has been modified.
$article->isDirty('title');
```

You can also flag fields as being modified. This is handy when appending into
array fields as this wouldn't automatically mark the field as dirty, only
exchanging completely would.

```php
// Add a comment and mark the field as changed.
$article->comments[] = $newComment;
$article->setDirty('comments', true);
```

In addition you can also base your conditional code on the original field
values by using the `getOriginal()` method. This method will either return
the original value of the field if it has been modified or its actual value.

You can also check for changes to any field in the document:

```php
// See if the document has changed
$article->isDirty();
```

To remove the dirty mark from fields in a document, you can use the `clean()`
method:

```php
$article->clean();
```

When creating a new document, you can avoid the fields from being marked as
dirty by passing an extra option:

```php
$article = new Article(['title' => 'New Article'], ['markClean' => true]);
```

To get a list of all dirty fields of a `Document` you may call:

```php
$dirtyFields = $document->getDirty();
```

## Validation Errors

After you [save a document](../ODM/saving-data#saving-documents) any validation
errors will be stored on the document itself. You can access any validation
errors using the `getErrors()`, `getError()` or `hasErrors()` methods:

```php
// Get all the errors
$errors = $user->getErrors();

// Get the errors for a single field.
$errors = $user->getError('password');

// Does the document or any nested document have an error.
$user->hasErrors();

// Does only the root document have an error
$user->hasErrors(false);
```

The `setErrors()` or `setError()` method can also be used to set the errors
on a document, making it easier to test code that works with error messages:

```php
$user->setError('password', ['Password is required']);
$user->setErrors([
    'password' => ['Password is required'],
    'username' => ['Username is required'],
]);
```

<a id="documents-mass-assignment"></a>

## Mass Assignment

While setting fields to documents in bulk is simple and convenient, it can
create significant security issues. Bulk assigning user data from the request
into a document allows the user to modify any and all columns. When using
anonymous document classes or creating the document class with the Bake
Console the ODM does not protect against mass-assignment.

The `_accessible` property allows you to provide a map of fields and
whether or not they can be mass-assigned. The values `true` and `false`
indicate whether a field can or cannot be mass-assigned:

```php
namespace App\Model\Document;

use Crustum\Mongo\ODM\Document;

class Article extends Document
{
    protected array $_accessible = [
        'title' => true,
        'body' => true,
    ];
}
```

In addition to concrete fields there is a special `*` field which defines the
fallback behavior if a field is not specifically named:

```php
namespace App\Model\Document;

use Crustum\Mongo\ODM\Document;

class Article extends Document
{
    protected array $_accessible = [
        'title' => true,
        'body' => true,
        '*' => false,
    ];
}
```

> [!NOTE]
> If the `*` field is not defined it will default to `false`.

### Avoiding Mass Assignment Protection

When creating a new document using the `new` keyword you can tell it to not
protect itself against mass assignment:

```php
use App\Model\Document\Article;

$article = new Article(['_id' => '000000000000000000000001', 'title' => 'Foo'], ['guard' => false]);
```

### Modifying the Guarded Fields at Runtime

You can modify the list of guarded fields at runtime using the `setAccess()`
method:

```php
// Make user_id accessible.
$article->setAccess('user_id', true);

// Make title guarded.
$article->setAccess('title', false);
```

> [!NOTE]
> Modifying accessible fields affects only the instance the method is called
> on.

When using the `newDocument()` and `patchDocument()` methods in the collection
objects you can customize mass assignment protection with options. Please refer
to the [Changing Accessible Fields](../ODM/saving-data#changing-accessible-fields) section for more information.

### Bypassing Field Guarding

There are some situations when you want to allow mass-assignment to guarded
fields:

```php
$article->patch($fields, ['guard' => false]);
```

By setting the `guard` option to `false`, you can ignore the accessible
field list for a single call to `patch()`.

### Checking if a Document was Persisted

It is often necessary to know if a document represents a row that is already
in the database. In those situations use the `isNew()` method:

```php
if (!$article->isNew()) {
    echo 'This article was saved already!';
}
```

If you are certain that a document has already been persisted, you can use
`setNew()`:

```php
$article->setNew(false);

$article->setNew(true);
```

> [!NOTE]
> In the ODM a document constructed with a present `_id` is still considered
> new until it is persisted or explicitly marked not-new. `setId()` sets the
> identifier and marks the document not-new in one call.

<a id="lazy-load-associations"></a>

## Lazy Loading Associations

While eager loading associations is generally the most efficient way to access
your associations, there may be times when you need to lazily load associated
data. Before we get into how to lazy load associations, we should discuss the
differences between eager loading and lazy loading associations:

**Eager loading**

Eager loading uses `$lookup` pipeline stages (where possible) to fetch data
from the database in as *few* queries as possible. When a separate query is
required, like in the case of a HasMany association, a single batched query is
emitted to fetch *all* the associated data for the current set of documents.
You eager load associations with `contain()` on a query, or by defaulting to
eager loading for an association when you define it. See
[Eager Loading Associations Via Contain](../ODM/retrieving-data-and-resultsets#eager-loading-associations-via-contain)
for the full reference.

**Lazy loading**

Lazy loading defers loading association data until it is absolutely required.
While this can save CPU time because possibly unused data is not hydrated into
objects, it can result in many more queries being emitted to the database. For
example looping over a set of articles & their comments will frequently emit
N queries where N is the number of articles being iterated.

The ODM provides `loadInto()` on the collection object for lazy loading: it
loads the requested associations for a document or list of documents by running
additional queries and merging the results into the documents.

```php
use App\Model\Document\Article;

$article = $this->fetchCollection('Articles')->get('000000000000000000000001');

// The comments property was lazy loaded
$article = $this->fetchCollection('Articles')->loadInto($article, ['Comments']);
foreach ($article->comments as $comment) {
    echo $comment->body;
}
```

`loadInto()` accepts the same `contain()`-compatible syntax as eager loading,
including nested associations and per-association query options:

```php
$articles = $this->fetchCollection('Articles')->find()->all();

$result = $this->fetchCollection('Articles')->loadInto($articles, [
    'Comments',
    'Tags',
]);
```

You can also load additional associations onto an already-hydrated document or
result set returned by a query; see
[Loading Additional Associations](../ODM/retrieving-data-and-resultsets#loading-additional-associations).

## Creating Re-usable Code with Traits

You may find yourself needing the same logic in multiple document classes.
PHP's traits are a great fit for this. You can put your application's traits in
**src/Model/Document**. By convention traits in Crustum are suffixed with
`Trait` so they can be discernible from classes or interfaces. Traits are
often a good complement to behaviors, allowing you to provide functionality for
the collection and document objects.

For example if we had SoftDeletable plugin, it could provide a trait. This trait
could give methods for marking documents as 'deleted', the method `softDelete`
could be provided by a trait:

```php
// SoftDelete/Model/Document/SoftDeleteTrait.php

namespace SoftDelete\Model\Document;

trait SoftDeleteTrait
{
    public function softDelete(): void
    {
        $this->set('deleted', true);
    }
}
```

You could then use this trait in your document class by importing it and
including it:

```php
namespace App\Model\Document;

use Crustum\Mongo\ODM\Document;
use SoftDelete\Model\Document\SoftDeleteTrait;

class Article extends Document
{
    use SoftDeleteTrait;
}
```

## Converting to Arrays/JSON

When building APIs, you may often need to convert documents into arrays or JSON
data. The ODM makes this simple:

```php
// Get an array.
// Associations will be converted with toArray() as well.
$array = $user->toArray();

// Convert to JSON
// Associations will be converted with jsonSerialize hook as well.
$json = json_encode($user);
```

When converting a document to JSON, the virtual & hidden field lists are
applied. Documents are recursively converted to JSON as well. This means that
if you eager loaded documents and their associations the ODM will correctly
handle converting the associated data into the correct format.

### Exposing Virtual Fields

By default, virtual fields are not exported when converting documents to
arrays or JSON. In order to expose virtual fields you need to make them
visible. When defining your document class you can provide a list of virtual
fields that should be exposed:

```php
namespace App\Model\Document;

use Crustum\Mongo\ODM\Document;

class User extends Document
{
    protected array $_virtual = ['full_name'];
}
```

This list can be modified at runtime using the `setVirtual()` method:

```php
$user->setVirtual(['full_name', 'is_admin']);
```

### Hiding Fields

There are often fields you do not want exported in JSON or array formats. For
example it is often unwise to expose password hashes or account recovery
questions. When defining a document class, define which fields should be
hidden:

```php
namespace App\Model\Document;

use Crustum\Mongo\ODM\Document;

class User extends Document
{
    protected array $_hidden = ['password'];
}
```

This list can be modified at runtime using the `setHidden()` method:

```php
$user->setHidden(['password', 'recovery_question']);
```

## Storing Complex Types

Accessor & Mutator methods on documents are not intended to contain the logic
for serializing and unserializing complex data coming from the database. Refer
to the [Saving Complex Types](../ODM/saving-data#saving-complex-types) section
to understand how your application can store more complex data types like
arrays and objects.
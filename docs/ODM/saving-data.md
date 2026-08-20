# Saving Data

> ### PENDING
>
> This page is ported from the Cake ORM cookbook page
> `docs/orm/saving-data.md` in full. Inserting/updating documents, saving with
> associations (belongsTo, hasOne, hasMany, belongsToMany), converting request
> data via `newDocument()`/`patchDocument()` (validation, `associated`,
> `fields`, `strictFields`, `_ids`, `_joinData`, `onlyIds`),
> `Collection.beforeMarshal`/`Collection.afterMarshal`, mass-assignment
> protection, `save()`/`saveOrFail()`/`saveMany()`, `findOrCreate()`,
> `updateAll()` and bulk updates are all covered here.
>
> Every claim and code sample was checked against
> `crustum/src/ODM/BaseCollection.php`, `crustum/src/ODM/Marshaller.php`,
> `crustum/src/ODM/Association/BelongsToMany.php`,
> `crustum/src/ODM/Association/HasMany.php` and
> `tests/TestCase/ODM/BaseCollectionTest.php`. Before the `### PENDING` banner
> is removed, the remaining samples must be run against a live test database
> (see `docs/working-memory-docs/54-saving-data-diffs.md`).

`class` Crustum\Mongo\ODM\**BaseCollection**

After you have [loaded your data](../ODM/retrieving-data-and-resultsets) you
will probably want to update and save the changes.

## A Glance Over Saving Data

Applications will usually have a couple of ways in which data is saved. The
first one is obviously through web forms and the other is by directly generating
or changing data in the code to be sent to the database.

### Inserting Data

The easiest way to insert data in the database is by creating a new document and
passing it to the `save()` method in the collection class:

```php
$articlesCollection = $this->fetchCollection('Articles');
$article = $articlesCollection->newEmptyDocument();

$article->title = 'A New Article';
$article->body = 'This is the body of the article';

if ($articlesCollection->save($article)) {
    // The $article document contains the _id now
    $id = $article->_id;
}
```

### Updating Data

Updating your data is achieved by using the `save()` method:

```php
$articlesCollection = $this->fetchCollection('Articles');
$article = $articlesCollection->get('000000000000000000000012'); // Return article with _id 0x...12

$article->title = 'Crustum is THE best Mongo ODM!';
$articlesCollection->save($article);
```

The ODM will know whether to perform an insert or an update based on the return
value of the `isNew()` method. Documents that were retrieved with `get()` or
`find()` will always return `false` when `isNew()` is called on them.

### Saving With Associations

By default, the `save()` method will also save one level of associations:

```php
$articlesCollection = $this->fetchCollection('Articles');
$author = $articlesCollection->Authors->findByUsername('mark')->first();

$article = $articlesCollection->newEmptyDocument();
$article->title = 'An article by mark';
$article->author = $author;

if ($articlesCollection->save($article)) {
    // The foreign key value was set automatically.
    echo $article->author_id;
}
```

The `save()` method is also able to create new records for associations:

```php
$firstComment = $articlesCollection->Comments->newEmptyDocument();
$firstComment->body = 'The Crustum ODM features are outstanding';

$secondComment = $articlesCollection->Comments->newEmptyDocument();
$secondComment->body = 'Crustum ODM performance is terrific!';

$tag1 = $articlesCollection->Tags->findByName('crustum')->first();
$tag2 = $articlesCollection->Tags->newEmptyDocument();
$tag2->name = 'awesome';

$article = $articlesCollection->get('000000000000000000000012');
$article->comments = [$firstComment, $secondComment];
$article->tags = [$tag1, $tag2];

$articlesCollection->save($article);
```

### Associate Many To Many Records

The previous example demonstrates how to associate a few tags to an article.
Another way of accomplishing the same thing is by using the `link()` method in
the association:

```php
$tag1 = $articlesCollection->Tags->findByName('crustum')->first();
$tag2 = $articlesCollection->Tags->newEmptyDocument();
$tag2->name = 'awesome';

$articlesCollection->Tags->link($article, [$tag1, $tag2]);
```

### Unlink Many To Many Records

Unlinking many to many records is done via the `unlink()` method:

```php
$tags = $articlesCollection
    ->Tags
    ->find()
    ->where(['name IN' => ['crustum', 'awesome']])
    ->toList();

$articlesCollection->Tags->unlink($article, $tags);
```

When modifying records by directly setting or changing the properties no
validation happens, which is a problem when accepting form data. The following
sections will demonstrate how to efficiently convert form data into documents so
that they can be validated and saved.

<a id="converting-request-data"></a>

## Converting Request Data into Documents

Before editing and saving data back to your database, you'll need to convert
the request data from the array format held in the request, and the documents
that the ODM uses. The collection class provides an efficient way to convert
one or many documents from request data. You can convert a single document
using:

```php
// In a controller

$articles = $this->fetchCollection('Articles');

// Validate and convert to a Document object
$document = $articles->newDocument($this->request->getData());
```

> [!NOTE]
> If you are using `newDocument()` and the resulting documents are missing some
> or all the data they were passed, double check that the fields you want to
> set are listed in the `$_accessible` property of your document. See
> [Documents Mass Assignment](../ODM/documents#documents-mass-assignment).

The request data should follow the structure of your documents. For example if
you have an article, which belonged to a user, and had many comments, your
request data should resemble:

```php
$data = [
    'title' => 'Crustum ODM For the Win',
    'body' => 'Working with Crustum ODM makes web development fun!',
    'user_id' => '000000000000000000000001',
    'user' => [
        'username' => 'mark',
    ],
    'comments' => [
        ['body' => 'The Crustum ODM features are outstanding'],
        ['body' => 'Crustum ODM performance is terrific!'],
    ]
];
```

By default, the `newDocument()` method validates the data that gets passed to
it, as explained in the [Validating Request Data](../ODM/validation#validating-request-data) section. If you wish to
bypass data validation pass the `'validate' => false` option:

```php
$document = $articles->newDocument($data, ['validate' => false]);
```

When building forms that save nested associations, you need to define which
associations should be marshalled:

```php
// In a controller

$articles = $this->fetchCollection('Articles');

// New document with nested associations
$document = $articles->newDocument($this->request->getData(), [
    'associated' => [
        'Tags', 'Comments' => ['associated' => ['Users']],
    ],
]);
```

The above indicates that the 'Tags', 'Comments' and 'Users' for the Comments
should be marshalled.

You can also use a nested array format similar to `contain()`:

```php
// Nested arrays (same format as contain())
$document = $articles->newDocument($this->request->getData(), [
    'associated' => [
        'Tags',
        'Comments' => [
            'Users',
            'Attachments',
        ],
    ],
]);

// Mixed with options
$document = $articles->newDocument($this->request->getData(), [
    'associated' => [
        'Tags' => ['onlyIds' => true],
        'Comments' => [
            'Users',
            'validate' => 'special',
        ],
    ],
]);
```

The ODM distinguishes associations from options using naming conventions:
association names use PascalCase (e.g., `Users`), while option keys use
camelCase (e.g., `onlyIds`).

Alternatively, you can use dot notation for brevity:

```php
// In a controller

$articles = $this->fetchCollection('Articles');

// New document with nested associations using dot notation
$document = $articles->newDocument($this->request->getData(), [
    'associated' => ['Tags', 'Comments.Users'],
]);
```

You may also disable marshalling of possible nested associations like so:

```php
$document = $articles->newDocument($data, ['associated' => []]);
// or...
$document = $articles->patchDocument($document, $data, ['associated' => []]);
```

Associated data is also validated by default unless told otherwise. You may also
change the validation set to be used per association:

```php
// In a controller

$articles = $this->fetchCollection('Articles');

// Bypass validation on Tags association and
// Designate 'signup' validation set for Comments.Users
$document = $articles->newDocument($this->request->getData(), [
    'associated' => [
        'Tags' => ['validate' => false],
        'Comments.Users' => ['validate' => 'signup'],
    ],
]);
```

The [Using Different Validators Per Association](../ODM/validation#using-different-validators-per-association) chapter has more
information on how to use different validators for associated marshalling.

You can always count on getting a document back from `newDocument()`. If
validation fails your document will contain errors, and any invalid fields will
not be populated in the created document.

### Converting BelongsToMany Data

If you are saving belongsToMany associations you can either use a list of
document data or a list of ids. When using a list of document data your request
data should look like:

```php
$data = [
    'title' => 'My title',
    'body' => 'The text',
    'user_id' => '000000000000000000000001',
    'tags' => [
        ['name' => 'Crustum'],
        ['name' => 'Internet'],
    ],
];
```

The above will create 2 new tags. If you want to link an article with existing
tags you can use a list of ids. Your request data should look like:

```php
$data = [
    'title' => 'My title',
    'body' => 'The text',
    'user_id' => '000000000000000000000001',
    'tags' => [
        '_ids' => [
            '000000000000000000000001',
            '000000000000000000000002',
            '000000000000000000000003',
            '000000000000000000000004',
        ],
    ],
];
```

If you need to link against some existing belongsToMany records, and create new
ones at the same time you can use an expanded format:

```php
$data = [
    'title' => 'My title',
    'body' => 'The text',
    'user_id' => '000000000000000000000001',
    'tags' => [
        ['name' => 'A new tag'],
        ['name' => 'Another new tag'],
        ['_id' => '000000000000000000000005'],
        ['_id' => '000000000000000000000006'],
    ],
];
```

When the above data is converted into documents, you will have 4 tags. The first
two will be new objects, and the second two will be references to existing
records.

When converting belongsToMany data, you can disable document creation, by using
the `onlyIds` option:

```php
$result = $articles->patchDocument($document, $data, [
    'associated' => ['Tags' => ['onlyIds' => true]],
]);
```

When used, this option restricts belongsToMany association marshalling to only
use the `_ids` data.

### Converting HasMany Data

If you want to update existing hasMany associations and update their properties,
you should first ensure your document is loaded with the hasMany association
populated. You can then use request data similar to:

```php
$data = [
    'title' => 'My Title',
    'body' => 'The text',
    'comments' => [
        ['_id' => '000000000000000000000001', 'comment' => 'Update the first comment'],
        ['_id' => '000000000000000000000002', 'comment' => 'Update the second comment'],
        ['comment' => 'Create a new comment'],
    ],
];
```

If you are saving hasMany associations and want to link existing records to a
new parent record you can use the `_ids` format:

```php
$data = [
    'title' => 'My new article',
    'body' => 'The text',
    'user_id' => '000000000000000000000001',
    'comments' => [
        '_ids' => [
            '000000000000000000000001',
            '000000000000000000000002',
            '000000000000000000000003',
            '000000000000000000000004',
        ],
    ],
];
```

When converting hasMany data, you can disable the new document creation, by
using the `onlyIds` option. When enabled, this option restricts hasMany
marshalling to only use the `_ids` key and ignore all other data.

### Converting Multiple Records

When creating forms that create/update multiple records at once you can use
`newDocuments()`:

```php
// In a controller.

$articles = $this->fetchCollection('Articles');
$documents = $articles->newDocuments($this->request->getData());
```

In this situation, the request data for multiple articles should look like:

```php
$data = [
    [
        'title' => 'First post',
        'published' => true,
    ],
    [
        'title' => 'Second post',
        'published' => true,
    ],
];
```

Once you've converted request data into documents you can save:

```php
// In a controller.
foreach ($documents as $document) {
    // Save document
    $articles->save($document);
}
```

The above will run a separate transaction for each document saved. If you'd like
to process all the documents as a single transaction you can use `saveMany()` or
`saveManyOrFail()`:

```php
// Get a boolean indicating success
$articles->saveMany($documents);

// Get a PersistenceFailedException if any records fail to save.
$articles->saveManyOrFail($documents);
```

### Changing Accessible Fields

It's also possible to allow `newDocument()` to write into non accessible fields.
For example, `_id` is usually absent from the `_accessible` property. In such
case, you can use the `accessibleFields` option. It could be useful to keep ids
of associated documents:

```php
// In a controller

$articles = $this->fetchCollection('Articles');
$document = $articles->newDocument($this->request->getData(), [
    'associated' => [
        'Tags', 'Comments' => [
            'associated' => [
                'Users' => [
                    'accessibleFields' => ['_id' => true],
                ],
            ],
        ],
    ],
]);
```

The above will keep the association unchanged between Comments and Users for the
concerned document.

> [!NOTE]
> If you are using `newDocument()` and the resulting documents are missing some
> or all the data they were passed, double check that the fields you want to
> set are listed in the `$_accessible` property of your document. See
> [Documents Mass Assignment](../ODM/documents#documents-mass-assignment).

### Merging Request Data Into Documents

In order to update documents you may choose to apply request data directly to an
existing document. This has the advantage that only the fields that actually
changed will be saved, as opposed to sending all fields to the database to be
persisted. You can merge an array of raw data into an existing document using
the `patchDocument()` method:

```php
// In a controller.

$articles = $this->fetchCollection('Articles');
$article = $articles->get('000000000000000000000001');
$articles->patchDocument($article, $this->request->getData());
$articles->save($article);
```

#### Validation and patchDocument

Similar to `newDocument()`, the `patchDocument` method will validate the data
before it is copied to the document. The mechanism is explained in the
[Validating Request Data](../ODM/validation#validating-request-data) section. If you wish to disable validation
while patching a document, pass the `validate` option as follows:

```php
// In a controller.

$articles = $this->fetchCollection('Articles');
$article = $articles->get('000000000000000000000001');
$articles->patchDocument($article, $data, ['validate' => false]);
```

You may also change the validation set used for the document or any of the
associations:

```php
$articles->patchDocument($article, $this->request->getData(), [
    'validate' => 'custom',
    'associated' => ['Tags', 'Comments.Users' => ['validate' => 'signup']],
]);
```

#### Patching HasMany and BelongsToMany

As explained in the previous section, the request data should follow the
structure of your document. The `patchDocument()` method is equally capable of
merging associations, by default only the first level of associations are
merged, but if you wish to control the list of associations to be merged or
merge deeper to deeper levels, you can use the third parameter of the method:

```php
// In a controller.
$associated = ['Tags', 'Comments.Users'];
// or using nested arrays
$associated = ['Tags', 'Comments' => ['associated' => ['Users']]];
$article = $articles->get('000000000000000000000001', ['contain' => $associated]);
$articles->patchDocument($article, $this->request->getData(), [
    'associated' => $associated,
]);
$articles->save($article);
```

Associations are merged by matching the primary key field in the source
documents to the corresponding fields in the data array. Associations will
construct new documents if no previous document is found for the association's
target property.

For example give some request data like the following:

```php
$data = [
    'title' => 'My title',
    'user' => [
        'username' => 'mark',
    ],
];
```

Trying to patch a document without a document in the user property will create
a new user document:

```php
// In a controller.
$document = $articles->patchDocument(new Article, $data);
echo $document->user->username; // Echoes 'mark'
```

The same can be said about hasMany and belongsToMany associations, with an
important caveat:

> [!NOTE]
> For belongsToMany associations, ensure the relevant document has a property
> accessible for the associated document.

If a Product belongsToMany Tag:

```php
// in the Product Document
protected array $_accessible = [
    // .. other properties
    'tags' => true,
];
```

> [!NOTE]
> For hasMany and belongsToMany associations, if there were any documents that
> could not be matched by primary key to a record in the data array, then those
> records will be discarded from the resulting document.
>
> Remember that using either `patchDocument()` or `patchDocuments()` does not
> persist the data, it just edits (or creates) the given documents. In order to
> save the document you will have to call the collection's `save()` method.

For example, consider the following case:

```php
$data = [
    'title' => 'My title',
    'body' => 'The text',
    'comments' => [
        ['body' => 'First comment', '_id' => '000000000000000000000001'],
        ['body' => 'Second comment', '_id' => '000000000000000000000002'],
    ],
];
$document = $articles->newDocument($data);
$articles->save($document);

$newData = [
    'comments' => [
        ['body' => 'Changed comment', '_id' => '000000000000000000000001'],
        ['body' => 'A new comment'],
    ],
];
$articles->patchDocument($document, $newData);
$articles->save($document);
```

At the end, if the document is converted back to an array you will obtain the
following result:

```php
[
    'title' => 'My title',
    'body' => 'The text',
    'comments' => [
        ['body' => 'Changed comment', '_id' => '000000000000000000000001'],
        ['body' => 'A new comment'],
    ],
];
```

As you can see, the comment with `_id` `0x...002` is no longer there, as it
could not be matched to anything in the `$newData` array. This happens because
the ODM is reflecting the new state described in the request data.

Some additional advantages of this approach is that it reduces the number of
operations to be executed when persisting the document again.

Please note that this does not mean that the comment with `_id` `0x...002` was
removed from the database, if you wish to remove the comments for that article
that are not present in the document, you can collect the primary keys and
execute a batch delete for those not in the list:

```php
// In a controller.
use Cake\Collection\Collection;

$comments = $this->fetchCollection('Comments');
$present = (new Collection($document->comments))->extract('_id')->filter()->toList();
$comments->deleteAll([
    'article_id' => $article->_id,
    '_id NOT IN' => $present,
]);
```

As you can see, this also helps creating solutions where an association needs to
be implemented like a single set.

You can also patch multiple documents at once. The consideration made for
patching hasMany and belongsToMany associations apply for patching multiple
documents: Matches are done by the primary key field value and missing matches
in the original documents array will be removed and not present in the result:

```php
// In a controller.

$articles = $this->fetchCollection('Articles');
$list = $articles->find('popular')->toList();
$patched = $articles->patchDocuments($list, $this->request->getData());
foreach ($patched as $document) {
    $articles->save($document);
}
```

Similarly to using `patchDocument()`, you can use the third argument for
controlling the associations that will be merged in each of the documents in the
array:

```php
// In a controller.
$patched = $articles->patchDocuments(
    $list,
    $this->request->getData(),
    ['associated' => ['Tags', 'Comments.Users']],
);
```

<a id="before-marshal"></a>

### Modifying Request Data Before Building Documents

If you need to modify request data before it is converted into documents, you
can use the `Collection.beforeMarshal` event. This event lets you manipulate the
request data just before documents are created:

```php
// Include use statements at the top of your file.
use Cake\Event\EventInterface;
use ArrayObject;

// In a collection or behavior class
public function beforeMarshal(EventInterface $event, ArrayObject $data, ArrayObject $options): void
{
    if (isset($data['username'])) {
        $data['username'] = mb_strtolower($data['username']);
    }
}
```

The `$data` parameter is an `ArrayObject` instance, so you don't have to return
it to change the data used to create documents.

The main purpose of `beforeMarshal` is to assist the users to pass the
validation process when simple mistakes can be automatically resolved, or when
data needs to be restructured so it can be put into the right fields.

The `Collection.beforeMarshal` event is triggered just at the start of the
validation process, one of the reasons is that `beforeMarshal` is allowed to
change the validation rules and the saving options, such as the field list.
Validation is triggered just after this event is finished. A common example of
changing the data before it is validated is trimming all fields before saving:

```php
// Include use statements at the top of your file.
use Cake\Event\EventInterface;
use ArrayObject;

// In a collection or behavior class
public function beforeMarshal(EventInterface $event, ArrayObject $data, ArrayObject $options): void
{
    foreach ($data as $key => $value) {
        if (is_string($value)) {
            $data[$key] = trim($value);
        }
    }
}
```

Because of how the marshalling process works, if a field does not pass
validation it will automatically be removed from the data array and not be
copied into the document. This is to prevent inconsistent data from entering
the document object.

Moreover, the data in `beforeMarshal` is a copy of the passed data. This is
because it is important to preserve the original user input, as it may be used
elsewhere.

### Modifying Documents After Updating From Request Data

The `Collection.afterMarshal` event allows you to modify documents after they
have been created or updated from request data. It can be useful to apply
additional validation logic that you cannot easily express through Validator
methods:

```php
// Include use statements at the top of your file.
use Cake\Event\EventInterface;
use Cake\Datasource\EntityInterface;
use ArrayObject;

// In a collection or behavior class
public function afterMarshal(
    EventInterface $event,
    EntityInterface $document,
    ArrayObject $data,
    ArrayObject $options,
): void {
    // Don't accept people who have a name starting with J on the 20th
    // of each month.
    if (mb_substr($document->name, 1) === 'J' && (int)date('d') === 20) {
        $document->setError('name', 'No J people today sorry.');
    }
}
```

### Validating Data Before Building Documents

The [Validating Data](../ODM/validation) chapter has more information on how to use the
validation features of ODM to ensure your data stays correct and consistent.

### Avoiding Property Mass Assignment Attacks

When creating or merging documents from request data you need to be careful of
what you allow your users to change or add in the documents. For example, by
sending an array in the request containing the `user_id` an attacker could
change the owner of an article, causing undesirable effects:

```php
// Contains ['user_id' => 100, 'title' => 'Hacked!'];
$data = $this->request->getData();
$document = $this->patchDocument($document, $data);
$this->save($document);
```

There are two ways of protecting you against this problem. The first one is by
setting the default fields that can be safely set from a request using the
[Documents Mass Assignment](../ODM/documents#documents-mass-assignment) feature in the documents.

The second way is by using the `fields` option when creating or merging data
into a document:

```php
// Contains ['user_id' => 100, 'title' => 'Hacked!'];
$data = $this->request->getData();

// Only allow title to be changed
$document = $this->patchDocument($document, $data, [
    'fields' => ['title'],
]);
$this->save($document);
```

You can also control which properties can be assigned for associations:

```php
// Only allow changing the title and tags
// and the tag name is the only field that can be set
$document = $this->patchDocument($document, $data, [
    'fields' => ['title', 'tags'],
    'associated' => ['Tags' => ['fields' => ['name']]],
]);
$this->save($document);
```

Using this feature is handy when you have many different functions your users
can access and you want to let your users edit different data based on their
privileges.

When using the `fields` option, validation will be applied to all fields in the
request data. You can limit validation to only the allowed fields by passing
`strictFields` to the `patchDocument()` or `newDocument()` call:

```php
// Contains ['user_id' => 100, 'title' => 'Hacked!'];
$data = $this->request->getData();

// Only title will be validated and updated.
$document = $this->patchDocument($document, $data, [
    'fields' => ['title'],
    'strictFields' => true,
]);
$this->save($document);
```

## Saving Documents

`method` Crustum\Mongo\ODM\BaseCollection::**save**(EntityInterface $document, array $options = []): EntityInterface|false

When saving request data to your database you need to first hydrate a new
document using `newDocument()` for passing into `save()`. For example:

```php
// In a controller

$articles = $this->fetchCollection('Articles');
$article = $articles->newDocument($this->request->getData());
if ($articles->save($article)) {
    // ...
}
```

The ODM uses the `isNew()` method on a document to determine whether or not an
insert or update should be performed. If the `isNew()` method returns `true`
and the document has a primary key value, an 'exists' query will be issued. The
'exists' query can be suppressed by passing `'checkExisting' => false` in the
`$options` argument:

```php
$articles->save($article, ['checkExisting' => false]);
```

Once you've loaded some documents you'll probably want to modify them and update
your database. This is a pretty simple exercise in ODM:

```php
$articles = $this->fetchCollection('Articles');
$article = $articles->find('all')->where(['_id' => '000000000000000000000002'])->first();

$article->title = 'My new title';
$articles->save($article);
```

When saving, ODM will [apply your rules](../ODM/validation#application-rules), and wrap the save
operation in a database transaction (a Mongo session). It will also only update
properties that have changed. The above `save()` call would generate an update
operation like:

```
update articles SET title = 'My new title' WHERE _id = 0x...002
```

If you had a new document, the following insert would be generated:

```
insert into articles (title) values ('My new title')
```

When a document is saved a few things happen:

1. Rule checking will be started if not disabled.
2. Rule checking will trigger the `Collection.beforeRules` event. If this event
    is stopped, the save operation will fail and return `false`.
3. Rules will be checked. If the document is being created, the `create` rules
    will be used. If the document is being updated, the `update` rules will be
    used.
4. The `Collection.afterRules` event will be triggered.
5. The `Collection.beforeSave` event is dispatched. If it is stopped, the save
    will be aborted, and `save()` will return `false`.
6. Parent associations are saved. For example, any listed belongsTo
    associations will be saved.
7. The modified fields on the document will be saved.
8. Child associations are saved. For example, any listed hasMany, hasOne, or
    belongsToMany associations will be saved.
9. The `Collection.afterSave` event will be dispatched.
10. The `Collection.afterSaveCommit` event will be dispatched.

See the [Application Rules](../ODM/validation#application-rules) section for more information on creating
and using rules.

> [!WARNING]
> If no changes are made to the document when it is saved, the callbacks will
> not fire because no save is performed.

The `save()` method will return the modified document on success, and `false`
on failure. You can disable rules and/or transactions using the `$options`
argument for save:

```php
// In a controller or collection method.
$articles->save($article, ['checkRules' => false, 'atomic' => false]);
```

### Saving Associations

When you are saving a document, you can also choose to save some or all the
associated documents. By default, all first level documents will be saved. For
example saving an Article, will also automatically update any dirty documents
that are directly related to articles collection.

You can fine tune which associations are saved by using the `associated`
option:

```php
// In a controller.

// Only save the comments association
$articles->save($document, ['associated' => ['Comments']]);
```

You can define save distant or deeply nested associations by using dot notation:

```php
// Save the company, the employees and related addresses for each of them.
$companies->save($document, ['associated' => ['Employees.Addresses']]);
```

Moreover, you can combine the dot notation for associations with the options
array:

```php
$companies->save($document, [
    'associated' => [
    'Employees',
    'Employees.Addresses',
    ]
]);
```

Your documents should be structured in the same way as they are when loaded from
the database.

If you are building or modifying association data after building your documents
you will have to mark the association property as modified with `setDirty()`:

```php
$company->author->name = 'Master Chef';
$company->setDirty('author', true);
```

### Saving BelongsTo Associations

When saving belongsTo associations, the ODM expects a single nested document
named with the singular, underscored version of the association name. For
example:

```php
// In a controller.
$data = [
    'title' => 'First Post',
    'user' => [
        '_id' => '000000000000000000000001',
        'username' => 'mark',
    ],
];

$articles = $this->fetchCollection('Articles');
$article = $articles->newDocument($data, [
    'associated' => ['Users'],
]);

$articles->save($article);
```

### Saving HasOne Associations

When saving hasOne associations, the ODM expects a single nested document named
with the singular, underscored version of the association name. For example:

```php
// In a controller.
$data = [
    '_id' => '000000000000000000000001',
    'username' => 'crustum',
    'profile' => [
        'twitter' => '@crustum',
    ],
];

$users = $this->fetchCollection('Users');
$user = $users->newDocument($data, [
    'associated' => ['Profiles'],
]);
$users->save($user);
```

### Saving HasMany Associations

When saving hasMany associations, the ODM expects an array of documents named
with the plural, underscored version of the association name. For example:

```php
// In a controller.
$data = [
    'title' => 'First Post',
    'comments' => [
        ['body' => 'Best post ever'],
        ['body' => 'I really like this.'],
    ]
];

$articles = $this->fetchCollection('Articles');
$article = $articles->newDocument($data, [
    'associated' => ['Comments'],
]);
$articles->save($article);
```

When saving hasMany associations, associated records will either be updated, or
inserted. For the case that the record already has associated records in the
database, you have the choice between two saving strategies:

append
: Associated records are updated in the database or, if not matching any
  existing record, inserted.

replace
: Any existing records that do not match the records provided will be deleted
  from the database. Only provided records will remain (or be inserted).

By default, the `append` saving strategy is used.
See [Has Many Associations](../ODM/associations#has-many-associations) for details on defining the `saveStrategy`.

Whenever you add new records to an existing association you should always mark
the association property as 'dirty'. This lets the ODM know that the association
property has to be persisted:

```php
$article->comments[] = $comment;
$article->setDirty('comments', true);
```

Without the call to `setDirty()` the updated comments will not be saved.

If you are creating a new document, and want to add existing records to a has
many/belongs to many association you need to initialize the association property
first:

```php
$article->comments = [];
```

Without initialization calling `$article->comments[] = $comment;` will have no
effect.

### Saving BelongsToMany Associations

When saving belongsToMany associations, the ODM expects an array of documents
named with the plural, underscored version of the association name. For example:

```php
// In a controller.
$data = [
    'title' => 'First Post',
    'tags' => [
        ['name' => 'Crustum'],
        ['name' => 'Framework'],
    ]
];

$articles = $this->fetchCollection('Articles');
$article = $articles->newDocument($data, [
    'associated' => ['Tags'],
]);
$articles->save($article);
```

When converting request data into documents, the `newDocument()` and
`newDocuments()` methods will handle both arrays of properties, as well as a
list of ids at the `_ids` key. Using the `_ids` key makes it possible to
building a select box or checkbox based form controls for belongs to many
associations. See the [Converting Request Data](#converting-request-data)
section for more information.

When saving belongsToMany associations, you have the choice between two saving
strategies:

append
: Only new links will be created between each side of this association. This
  strategy will not destroy existing links even though they may not be present
  in the array of documents to be saved.

replace
: When saving, existing links will be removed and new links will be created in
  the junction collection. If there are existing link in the database to some
  of the documents intended to be saved, those links will be updated, not
  deleted and then re-saved.

See [Belongs To Many Associations](../ODM/associations#belongs-to-many-associations) for details on defining the `saveStrategy`.

By default, the `replace` strategy is used. Whenever you add new records into an
existing association you should always mark the association property as 'dirty'.
This lets the ODM know that the association property has to be persisted:

```php
$article->tags[] = $tag;
$article->setDirty('tags', true);
```

Without the call to `setDirty()` the updated tags will not be saved.

Often you'll find yourself wanting to make an association between two existing
documents, e.g. a user coauthoring an article. This is done by using the method
`link()`, like this:

```php
$article = $this->Articles->get($articleId);
$user = $this->Users->get($userId);

$this->Articles->Users->link($article, [$user]);
```

When saving belongsToMany Associations, it can be relevant to save some
additional data to the junction collection. In the previous example of tags, it
could be the `vote_type` of person who voted on that article. The `vote_type`
can be either `upvote` or `downvote` and is represented by a string. The
relation is between Users and Articles.

Saving that association, and the `vote_type` is done by first adding some data
to `_joinData` and then saving the association with `link()`, example:

```php
$article = $this->Articles->get($articleId);
$user = $this->Users->get($userId);

$user->_joinData = new Document(['vote_type' => $voteType], ['markNew' => true]);
$this->Articles->Users->link($article, [$user]);
```

### Saving Additional Data to the Join Collection

In some situations the collection joining your BelongsToMany association, will
have additional fields on it. ODM makes it simple to save properties into
these fields. Each document in a belongsToMany association has a `_joinData`
property that contains the additional fields on the junction collection. This
data can be either an array or a Document instance. For example if Students
BelongsToMany Courses, we could have a junction collection that looks like:

    _id | student_id | course_id | days_attended | grade

When saving data you can populate the additional fields on the junction
collection by setting data to the `_joinData` property:

```php
$student->courses[0]->_joinData->grade = 80.12;
$student->courses[0]->_joinData->days_attended = 30;

$studentsCollection->save($student);
```

The example above will only work if the property `_joinData` is already a
reference to a Join Collection Document. If you don't already have a `_joinData`
document, you can create one using `newDocument()`:

```php
$coursesMembershipsCollection = $this->fetchCollection('CoursesMemberships');
$student->courses[0]->_joinData = $coursesMembershipsCollection->newDocument([
    'grade' => 80.12,
    'days_attended' => 30,
]);

$studentsCollection->save($student);
```

The `_joinData` property can be either a document, or an array of data if you
are saving documents built from request data. When saving junction collection
data from request data your POST data should look like:

```php
$data = [
    'first_name' => 'Sally',
    'last_name' => 'Parker',
    'courses' => [
        [
            '_id' => '000000000000000000000010',
            '_joinData' => [
                'grade' => 80.12,
                'days_attended' => 30,
            ]
        ],
        // Other courses.
    ]
];
$student = $this->Students->newDocument($data, [
    'associated' => ['Courses._joinData'],
]);
```

The `_joinData` property can be renamed with `setJunctionProperty()`:

```php
// in StudentsCollection::initialize()
$this->belongsToMany('Courses')
    ->setJunctionProperty('course_mark');
```

When a junction property is set, the new junction property name must be used to
manipulate documents, marshall request data, and create form fields.

### Saving Complex Types

Collections are capable of storing data represented in basic types, like
strings, integers, floats, booleans, etc. But it can also be extended to accept
more complex types such as arrays or objects and serialize this data into
simpler types that can be saved in the database.

This functionality is achieved by using the custom types system:

```php
use Crustum\Mongo\Database\Type\TypeFactory;

TypeFactory::map('json', 'Cake\Database\Type\JsonType');

// In src/Model/Collection/UsersCollection.php

class UsersCollection extends BaseCollection
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->getSchema()->setColumnType('preferences', 'json');
    }
}
```

The code above maps the `preferences` field to the `json` custom type. This
means that when retrieving data for that field, it will be unserialized from a
JSON string in the database and put into a document as an array.

Likewise, when saved, the array will be transformed back into its JSON
representation:

```php
$user = new User([
    'preferences' => [
        'sports' => ['football', 'baseball'],
        'books' => ['Mastering PHP', 'Hamlet'],
    ]
]);
$usersCollection->save($user);
```

When using complex types it is important to validate that the data you are
receiving from the end user is the correct type. Failing to correctly handle
complex data could result in malicious users being able to store data they
would not normally be able to.

## Strict Saving

`method` Crustum\Mongo\ODM\BaseCollection::**saveOrFail**(EntityInterface $document, array $options = []): EntityInterface

Using this method will throw a
`Crustum\Mongo\ODM\Exception\PersistenceFailedException` if:

- the application rules checks failed
- the document contains errors
- the save was aborted by a callback.

Using this can be helpful when you performing complex database operations
without human monitoring, for example, inside a Shell task.

> [!NOTE]
> If you use this method in a controller, be sure to catch the
> `PersistenceFailedException` that could be raised.

If you want to track down the document that failed to save, you can use the
`Crustum\Mongo\ODM\Exception\PersistenceFailedException::getDocument()` method:

```php
try {
    $collection->saveOrFail($document);
} catch (\Crustum\Mongo\ODM\Exception\PersistenceFailedException $e) {
    echo $e->getDocument();
}
```

As this internally performs a `Crustum\Mongo\ODM\BaseCollection::save()` call,
all corresponding save events will be triggered.

## Find or Create a Document

`method` Crustum\Mongo\ODM\BaseCollection::**findOrCreate**(SelectQuery|callable|array $search, callable|array|null $callback = null, array $options = []): EntityInterface

Find an existing record based on `$search` or create a new record using the
properties in `$search` and calling the optional `$callback`. This method is
ideal in scenarios where you need to reduce the chance of duplicate records:

```php
$record = $collection->findOrCreate(
    ['email' => 'bobbi@example.com'],
    function ($document) use ($otherData) {
        // Only called when a new record is created.
        $document->name = $otherData['name'];
    }
);
```

You can provide an array of data to set into the document when it is created:

```php
$otherData = ['name' => 'bobbi'];
$record = $collection->findOrCreate(
    ['email' => 'bobbi@example.com'],
    $otherData,
);
```

If your find conditions require custom order, associations or conditions, then
the `$search` parameter can be a callable or `SelectQuery` object. If you use a
callable, it should take a `SelectQuery` as its argument.

The returned document will have been saved if it was a new record. The supported
options for this method are:

- `atomic` Should the find and save operation be done inside a transaction.
- `defaults` Set to `false` to not set `$search` properties into the created
  document.

## Creating with an existing primary key

When handling UUID primary keys you often want to provide an externally
generated value, and not have an identifier generated for you.

In this case make sure you are not passing the primary key as part of the
marshalled data. Instead, assign the primary key and then patch in the
remaining document data:

```php
$record = $collection->newEmptyDocument();
$record->_id = $existingUuid;
$record = $collection->patchDocument($record, $existingData);
$collection->saveOrFail($record);
```

## Saving Multiple Documents

`method` Crustum\Mongo\ODM\BaseCollection::**saveMany**(iterable $entities, array $options = []): iterable|false

Using this method you can save multiple documents atomically. `$entities` can be
an array of documents created using `newDocuments()` / `patchDocuments()`.
`$options` can have the same options as accepted by `save()`:

```php
$data = [
    [
        'title' => 'First post',
        'published' => true,
    ],
    [
        'title' => 'Second post',
        'published' => true,
    ],
];

$articles = $this->fetchCollection('Articles');
$documents = $articles->newDocuments($data);
$result = $articles->saveMany($documents);
```

The result will be updated documents on success or `false` on failure.

## Bulk Updates

`method` Crustum\Mongo\ODM\BaseCollection::**updateAll**(ExpressionInterface|Closure|array|string $fields, ExpressionInterface|Closure|array|string|null $conditions): int

There may be times when updating rows individually is not efficient or
necessary. In these cases it is more efficient to use a bulk-update to modify
many rows at once, by assigning the new field values, and conditions for the
update:

```php
// Publish all the unpublished articles.
function publishAllUnpublished()
{
    $this->updateAll(
        [  // fields
            'published' => true,
            'publish_date' => new DateTime('now'),
        ],
        [  // conditions
            'published' => false,
        ]
    );
}
```

If you need to do bulk updates and use expressions, you can use an expression
object such as the query's function builder for `$inc` style updates:

```php
use Crustum\Mongo\ODM\Query\UpdateQuery;

...

function incrementCounters()
{
    $query = $this->updateQuery();
    $this->updateAll([
        'view_count' => $query->func()->inc(1),
    ], ['published' => true]);
}
```

A bulk-update will be considered successful if 1 or more rows are updated.

> [!WARNING]
> `updateAll` will *not* trigger beforeSave/afterSave events. If you need those
> first load a collection of records and update them.

`updateAll()` is for convenience only. You can use this more flexible interface
as well:

```php
// Publish all the unpublished articles.
function publishAllUnpublished()
{
    $this->updateQuery()
        ->set(['published' => true])
        ->where(['published' => false])
        ->execute();
}
```

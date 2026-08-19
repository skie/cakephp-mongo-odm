# Associations - Linking Collections Together

> ### PENDING
>
> This page is ported from the Cake ORM cookbook page
> `docs/orm/associations.md` in full. The four classic association types, the
> loading strategies, the ODM-native **Embedded** and **DBRef** associations,
> association finders, association conventions, and eager loading are all
> covered here.
>
> Every code sample was checked against `crustum/src/ODM`. The aggregation
> pipelines shown are the shapes asserted by the ODM test-suite
> (`EagerLoaderTest`, `HasOneTest`, `BelongsToTest`). Before the `### PENDING`
> banner is removed, the remaining samples must be run against a live test
> database (see `docs/working-memory-docs/47-association-work-analyze.md`).

Defining relations between different objects in your application should be
a natural process. For example, an article may have many comments, and belong to
an author. Authors may have many articles and comments. The four classic
association types in the Crustum ODM are: hasOne, hasMany, belongsTo, and
belongsToMany. Because the ODM persists to MongoDB, two additional Mongo-native
association kinds are available: **embedded** documents (embedOne/embedMany)
and **DBRef** references.

| Relationship             | Association Type | Example                                |
|--------------------------|------------------|----------------------------------------|
| one to one               | hasOne           | A user has one profile.                |
| one to many              | hasMany          | A user can have multiple articles.     |
| many to one              | belongsTo        | Many articles belong to a user.        |
| many to many             | belongsToMany    | Tags belong to many articles.          |
| one to one (embedded)    | embedOne         | A user has one profile inside the user document. |
| one to many (embedded)   | embedMany        | A user has many addresses inside the user document. |
| one to one (reference)   | dbref            | A user references one profile by DBRef. |

Embedded associations store their data inside the parent document, and DBRef
associations store a `$ref`/`$id` pointer that is resolved lazily. The four
classic association types reference records in other collections by key.

Associations are defined during the `initialize()` method of your collection
object. Methods matching the association type allow you to define the
associations in your application. For example if we wanted to define a belongsTo
association in our `ArticlesCollection`:

```php
namespace App\Model\Collection;

use Crustum\Mongo\ODM\BaseCollection;

class ArticlesCollection extends BaseCollection
{
    public function initialize(array $config): void
    {
        $this->belongsTo('Authors');
    }
}
```

The simplest form of any association setup takes the collection alias you want
to associate with. By default, all the details of an association will use the
Crustum conventions. If you want to customize how your associations are handled
you can modify them with setters:

```php
class ArticlesCollection extends BaseCollection
{
    public function initialize(array $config): void
    {
        $this->belongsTo('Authors', [
            'className' => 'Publishing.Authors',
        ])
            ->setForeignKey('author_id')
            ->setProperty('author');
    }
}
```

The property name will be the property key (of the associated document) on the
document object, in this case:

```php
$authorDocument = $articleDocument->author;
```

You can also use arrays to customize your associations:

```php
$this->belongsTo('Authors', [
    'className' => 'Publishing.Authors',
    'foreignKey' => 'author_id',
    'propertyName' => 'author',
]);
```

However, arrays do not offer the typehinting and autocomplete benefits that the
fluent interface does.

The same collection can be used multiple times to define different types of
associations. For example consider a case where you want to separate
approved comments and those that have not been moderated yet:

```php
class ArticlesCollection extends BaseCollection
{
    public function initialize(array $config): void
    {
        $this->hasMany('Comments')
            ->setFinder('approved');

        $this->hasMany('UnapprovedComments', [
            'className' => 'Comments',
        ])
            ->setFinder('unapproved')
            ->setProperty('unapproved_comments');
    }
}
```

As you can see, by specifying the `className` key, it is possible to use the
same collection as different associations for the same collection. You can even
create self-associated collections to create parent-child relationships:

```php
class CategoriesCollection extends BaseCollection
{
    public function initialize(array $config): void
    {
        $this->hasMany('SubCategories', [
            'className' => 'Categories',
        ]);

        $this->belongsTo('ParentCategories', [
            'className' => 'Categories',
        ]);
    }
}
```

You can also setup associations in mass by making a single call to
`BaseCollection::addAssociations()` which accepts an array containing a set of
collection names indexed by association type as an argument:

```php
class PostsCollection extends BaseCollection
{
    public function initialize(array $config): void
    {
        $this->addAssociations([
            'belongsTo' => [
                'Users' => ['className' => 'App\Model\Collection\UsersCollection'],
            ],
            'hasMany' => ['Comments'],
            'belongsToMany' => ['Tags'],
        ]);
    }
}
```

Each association type accepts multiple associations where the keys are the
aliases, and the values are association config data. If numeric keys are used
the values will be treated as association aliases.

## Loading Strategies

Cake's ORM loads associations with SQL joins or `IN`-list subqueries. MongoDB
has no joins, so the ODM ships three loading strategies. They are declared per
association and control how many queries a `contain()` produces:

| Strategy   | Loading mechanism                                                        | Extra queries |
|------------|--------------------------------------------------------------------------|---------------|
| `lookup`   | In-pipeline `$lookup` stages appended to the root aggregation pipeline.   | 0             |
| `select`   | Separate batched query (`$in` over the parent keys) matched in memory.    | 1             |
| `embed`    | No loading at all - the data lives inside the parent document.            | 0             |

The `strategy` option on a classic association accepts `lookup` or `select`.
`subquery` and `join` are accepted as compatibility values and map to the ODM
equivalents:

- `subquery` (HasMany/BelongsToMany default) resolves to a **separate batched
  query** - the same external loader used by `select`. There is no SQL-style
  subquery in MongoDB.
- `join` is accepted for cake `belongsTo`/`hasOne` code that used the default
  SQL JOIN strategy and maps to `lookup`.

The defaults per association type are:

| Association  | Default strategy | Notes                                                          |
|--------------|------------------|----------------------------------------------------------------|
| belongsTo    | `lookup`         | Single `$lookup` + `$unwind`.                                  |
| hasOne       | `lookup`         | `$lookup` with `let`/pipeline + `$limit: 1`, then `$unwind`.   |
| hasMany      | `subquery`       | External batched query; `$in` over the parent `_id`s.          |
| belongsToMany| `subquery`       | Loads junction ids in one query, then `$lookup`s the targets.  |
| embedOne     | `embed`          | Only `embed` is accepted.                                      |
| embedMany    | `embed`          | Only `embed` is accepted.                                      |
| dbref        | `lookup`         | Field holds a `$ref`/`$id` pointer; resolved on demand.        |

You can override the default either in the association definition or per
`contain()` call:

```php
$this->hasMany('Comments', ['strategy' => 'lookup']);

// or per query
$articles->find('all')->contain(['Comments' => ['strategy' => 'select']]);
```

The `select` strategy is useful when the related collection lives in a
different database or on a different connection, since the ODM performs the
match in application memory instead of asking the server to `$lookup` across
databases.

<a id="has-one-associations"></a>

## HasOne Associations

Let's set up a Users collection with a hasOne relationship to the Addresses
collection.

First, your collections need to be keyed correctly. For a hasOne relationship
to work, one collection has to contain a foreign key that points to a record in
the other collection. In this case, the Addresses collection will contain a
field called `user_id`. The basic pattern is:

**hasOne:** the *other* collection contains the foreign key.

| Relation               | Schema            |
|------------------------|-------------------|
| Users hasOne Addresses | addresses.user_id |
| Doctors hasOne Mentors | mentors.doctor_id |

> [!NOTE]
> It is not mandatory to follow Crustum conventions, you can override the name
> of any `foreignKey` in your associations definitions. Nevertheless, sticking
> to conventions will make your code less repetitive, easier to read and to
> maintain.

Once you create the `UsersCollection` and `AddressesCollection` classes, you
can make the association with the following code:

```php
class UsersCollection extends BaseCollection
{
    public function initialize(array $config): void
    {
        $this->hasOne('Addresses');
    }
}
```

If you need more control, you can define your associations using the setters.
For example, you might want to limit the association to include only certain
records:

```php
class UsersCollection extends BaseCollection
{
    public function initialize(array $config): void
    {
        $this->hasOne('Addresses')
            ->setFinder('primary')
            ->setDependent(true);
    }
}
```

> [!NOTE]
> The association alias is fixed when the association is created, so there is
> no `setName()` on the ODM `Association` base class. To expose the same
> collection under a second alias, define a second association (see below).

If you want to break different addresses into multiple associations, you can
do something like:

```php
class UsersCollection extends BaseCollection
{
    public function initialize(array $config): void
    {
        $this->hasOne('HomeAddresses', [
            'className' => 'Addresses',
        ])
            ->setProperty('home_address')
            ->setConditions(['HomeAddresses.label' => 'Home'])
            ->setDependent(true);

        $this->hasOne('WorkAddresses', [
            'className' => 'Addresses',
        ])
            ->setProperty('work_address')
            ->setConditions(['WorkAddresses.label' => 'Work'])
            ->setDependent(true);
    }
}
```

> [!NOTE]
> If a field is shared by multiple hasOne associations, you must qualify it with the association alias.
> In the above example, the 'label' field is qualified with the 'HomeAddresses' and 'WorkAddresses' aliases.

Possible keys for hasOne association arrays include:

- **className**: The class name of the other collection. This is the same name
  used when getting an instance of the collection. In the 'Users hasOne
  Addresses' example, it should be 'Addresses'. The default value is the name
  of the association.
- **foreignKey**: The name of the foreign key field in the other collection.
  The default value is the underscored, singular name of the current model,
  suffixed with `_id` such as 'user_id' in the above example.
- **bindingKey**: The name of the field in the current collection used to match
  the `foreignKey`. The default value is the primary key of the current
  collection such as `_id` of Users in the above example.
- **conditions**: An array of find() compatible conditions such as
  `['Addresses.primary' => true]`.
- **joinType**: The type of join used in the aggregation pipeline. Accepted
  values are 'LEFT' and 'INNER'. You can use 'INNER' to get results only where
  the association is set. The default value is 'LEFT'.
- **dependent**: When the dependent key is set to `true`, and a document is
  deleted, the associated records are also deleted. In this case we set it to
  `true` so that deleting a User will also delete her associated Address.
- **cascadeCallbacks**: When this and **dependent** are `true`, cascaded
  deletes will load and delete documents so that callbacks are properly
  triggered. When `false`, `deleteAll()` is used to remove associated data
  and no callbacks are triggered.
- **propertyName**: The property name that should be filled with data from the
  associated collection into the source results. By default, this is the
  underscored & singular name of the association so `address` in our example.
- **strategy**: The query strategy used to load the matching record from the
  other collection. Accepted values are `'lookup'` and `'select'`. Using
  `'select'` will generate a separate query and can be useful when the other
  collection is in a different database. The default is `'lookup'`.
- **finder**: The finder method to use when loading associated records.

Once this association has been defined, find operations on the Users collection
can contain the Address record if it exists:

```php
// In a controller or collection method.
$query = $users->find('all')->contain(['Addresses'])->all();
foreach ($query as $user) {
    echo $user->address->street;
}
```

The default `lookup` strategy appends aggregation stages similar to:

```php
[
    ['$lookup' => [
        'from' => 'addresses',
        'as' => 'address',
        'let' => ['bindingValue' => '$_id'],
        'pipeline' => [
            ['$match' => ['$expr' => ['$and' => [
                ['$ne' => ['$$bindingValue', null]],
                ['$eq' => ['$user_id', '$$bindingValue']],
            ]]]],
            ['$limit' => 1],
        ],
    ]],
    ['$unwind' => [
        'path' => '$address',
        'preserveNullAndEmptyArrays' => true,
    ]],
]
```

With `joinType: 'INNER'` the `$unwind` stage drops unmatched source documents
(`preserveNullAndEmptyArrays: false`).

<a id="belongs-to-associations"></a>

## BelongsTo Associations

Now that we have Address data access from the User collection, let's define
a belongsTo association in the Addresses collection in order to get access to
related User data. The belongsTo association is a natural complement to the
hasOne and hasMany associations - it allows us to see related data from the
other direction.

When keying your collections for a belongsTo relationship, follow this
convention:

**belongsTo:** the *current* collection contains the foreign key.

| Relation                  | Schema            |
|---------------------------|-------------------|
| Addresses belongsTo Users | addresses.user_id |
| Mentors belongsTo Doctors | mentors.doctor_id |

> [!TIP]
> If a collection contains a foreign key, it belongs to the other collection.

We can define the belongsTo association in our Addresses collection as follows:

```php
class AddressesCollection extends BaseCollection
{
    public function initialize(array $config): void
    {
        $this->belongsTo('Users');
    }
}
```

We can also define a more specific relationship using the setters:

```php
class AddressesCollection extends BaseCollection
{
    public function initialize(array $config): void
    {
        $this->belongsTo('Users')
            ->setForeignKey('user_id')
            ->setJoinType('INNER');
    }
}
```

Possible keys for belongsTo association arrays include:

- **className**: The class name of the other collection. This is the same name
  used when getting an instance of the collection. In the 'Addresses belongsTo
  Users' example, it should be 'Users'. The default value is the name of the
  association.
- **foreignKey**: The name of the foreign key field in the current collection.
  The default value is the underscored, singular name of the other model,
  suffixed with `_id` such as 'user_id' in the above example.
- **bindingKey**: The name of the field in the other collection used to match
  the `foreignKey`. The default value is the primary key of the other
  collection such as `_id` of Users in the above example.
- **conditions**: An array of find() compatible conditions such as
  `['Users.active' => true]`.
- **joinType**: The type of join used in the aggregation pipeline. Accepted
  values are 'LEFT' and 'INNER'. You can use 'INNER' to get results only where
  the association is set. The default value is 'LEFT'.
- **propertyName**: The property name that should be filled with data from the
  associated collection into the source results. By default, this is the
  underscored & singular name of the association so `user` in our example.
- **strategy**: The query strategy used to load the matching record from the
  other collection. Accepted values are `'lookup'` and `'select'`. Using
  `'select'` will generate a separate query and can be useful when the other
  collection is in a different database. The default is `'lookup'`.
- **finder**: The finder method to use when loading associated records.

Once this association has been defined, find operations on the Addresses
collection can contain the User record if it exists:

```php
// In a controller or collection method.
$query = $addresses->find('all')->contain(['Users'])->all();
foreach ($query as $address) {
    echo $address->user->username;
}
```

The default `lookup` strategy appends aggregation stages similar to:

```php
[
    ['$lookup' => [
        'from' => 'users',
        'as' => 'user',
        'localField' => 'user_id',
        'foreignField' => '_id',
    ]],
    ['$unwind' => [
        'path' => '$user',
        'preserveNullAndEmptyArrays' => true,
    ]],
]
```

<a id="has-many-associations"></a>

## HasMany Associations

An example of a hasMany association is "Articles hasMany Comments". Defining
this association will allow us to fetch an article's comments when the article
is loaded.

When creating your collections for a hasMany relationship, follow this
convention:

**hasMany:** the *other* collection contains the foreign key.

| Relation                  | Schema              |
|---------------------------|---------------------|
| Articles hasMany Comments | comments.article_id |
| Products hasMany Options  | options.product_id  |
| Doctors hasMany Patients  | patients.doctor_id  |

We can define the hasMany association in our Articles model as follows:

```php
class ArticlesCollection extends BaseCollection
{
    public function initialize(array $config): void
    {
        $this->hasMany('Comments');
    }
}
```

We can also define a more specific relationship using the setters:

```php
class ArticlesCollection extends BaseCollection
{
    public function initialize(array $config): void
    {
        $this->hasMany('Comments')
            ->setForeignKey('article_id')
            ->setDependent(true);
    }
}
```

Sometimes you may want to configure composite keys in your associations:

```php
// Within ArticlesCollection::initialize() call
$this->hasMany('Comments')
    ->setForeignKey([
        'article_id',
        'article_hash',
    ]);
```

Relying on the example above, we have passed an array containing the desired
composite keys to `setForeignKey()`. By default, the `bindingKey` would be
automatically defined as `_id` and `hash` respectively, but let's assume that
you need to specify different binding fields than the defaults. You can setup it
manually with `setBindingKey()`:

```php
// Within ArticlesCollection::initialize() call
$this->hasMany('Comments')
    ->setForeignKey([
        'article_id',
        'article_hash',
    ])
    ->setBindingKey([
        'whatever_id',
        'whatever_hash',
    ]);
```

Like hasOne associations, `foreignKey` is in the other (Comments) collection
and `bindingKey` is in the current (Articles) collection.

Possible keys for hasMany association arrays include:

- **className**: The class name of the other collection. This is the same name
  used when getting an instance of the collection. In the 'Articles hasMany
  Comments' example, it should be 'Comments'. The default value is the name of
  the association.
- **foreignKey**: The name of the foreign key field in the other collection.
  The default value is the underscored, singular name of the current model,
  suffixed with `_id` such as 'article_id' in the above example.
- **bindingKey**: The name of the field in the current collection used to match
  the `foreignKey`. The default value is the primary key of the current
  collection such as `_id` of Articles in the above example.
- **conditions**: an array of find() compatible conditions such as
  `['Comments.visible' => true]`. It is recommended to use the `finder` option
  instead.
- **sort**: an array of find() compatible order clauses such as
  `['Comments.created' => 'ASC']`.
- **dependent**: When dependent is set to `true`, recursive model deletion is
  possible. In this example, Comment records will be deleted when their
  associated Article record has been deleted.
- **cascadeCallbacks**: When this and **dependent** are `true`, cascaded
  deletes will load and delete documents so that callbacks are properly
  triggered. When `false`, `deleteAll()` is used to remove associated data
  and no callbacks are triggered.
- **propertyName**: The property name that should be filled with data from the
  associated collection into the source results. By default, this is the
  underscored & plural name of the association so `comments` in our example.
- **strategy**: Defines the query strategy to use. Defaults to `subquery`,
  which is executed as a separate batched query over the parent keys (`$in`).
  The other valid values are `select` (same external loader, used directly in
  the definition) and `lookup` (in-pipeline `$lookup`).
- **saveStrategy**: Either `append` or `replace`. Defaults to `append`. When
  `append` the current records are appended to any records in the database.
  When `replace` associated records not in the current set will be removed.
- **finder**: The finder method to use when loading associated records.

Once this association has been defined, find operations on the Articles
collection can contain the Comment records if they exist:

```php
// In a controller or collection method.
$query = $articles->find('all')->contain(['Comments'])->all();
foreach ($query as $article) {
    echo $article->comments[0]->text;
}
```

Because MongoDB has no SQL subqueries, the default `subquery` strategy issues
two separate find operations similar to:

```php
// Root query
db.articles.find({})

// Batched related query, using the collected article _ids
db.comments.find({
    article_id: { $in: ['000000000000000000000001', '000000000000000000000002', '000000000000000000000003', '000000000000000000000004', '000000000000000000000005'] }
})
```

The related results are matched against the loaded articles in application
memory, so the article's comments property is populated without N+1 queries.

When the `lookup` strategy is used, a single query with an in-pipeline
`$lookup` is generated instead:

```php
[
    ['$lookup' => [
        'from' => 'comments',
        'as' => 'comments',
        'localField' => '_id',
        'foreignField' => 'article_id',
    ]],
]
```

> [!NOTE]
> Conditions, sorting and the `fields` option of a `lookup` hasMany are pushed
> into a `$match`/`$sort`/`$project` sub-pipeline inside the `$lookup`. With the
> `select`/`subquery` strategies they become part of the separate related query.

You may want to cache the counts for your hasMany associations. This is useful
when you often need to show the number of associated records, but don't want to
load all the records just to count them. For example, the comment count on any
given article is often cached to make generating lists of articles more
efficient. You can use the [CounterCacheBehavior](../ODM/behaviors/counter-cache)
to cache counts of associated records.

You should make sure that your collections do not contain fields that match
association property names. If for example you have counter fields that
conflict with association properties, you must either rename the association
property, or the field name.

<a id="belongs-to-many-associations"></a>

## BelongsToMany Associations

An example of a BelongsToMany association is "Article BelongsToMany Tags",
where the tags from one article are shared with other articles. BelongsToMany
is often referred to as "has and belongs to many", and is a classic "many to
many" association.

The main difference between hasMany and BelongsToMany is that the link between
the collections in a BelongsToMany association is not exclusive. For example,
we are joining our Articles collection with a Tags collection. Using 'funny' as
a Tag for my Article, doesn't "use up" the tag. I can also use it on the next
article I write.

Three collections are required for a BelongsToMany association. In the example
above we would need collections for `articles`, `tags` and `articles_tags`.
The `articles_tags` collection contains the data that links tags and articles
together. The joining collection is named after the two collections involved,
separated with an underscore by convention. In its simplest form, this
collection consists of `article_id` and `tag_id` and an index spanning both
fields. The join collection is not required to have its own `_id`.

**belongsToMany** requires a separate join collection that includes both
*model* names.

| Relationship | Join Collection Fields |
| ---- | ---- |
| Articles belongsToMany Tags | articles_tags.article_id, articles_tags.tag_id |
| Patients belongsToMany Doctors | doctors_patients.doctor_id, doctors_patients.patient_id |

We can define the belongsToMany association in both our models as follows:

```php
// In src/Model/Collection/ArticlesCollection.php
class ArticlesCollection extends BaseCollection
{
    public function initialize(array $config): void
    {
        $this->belongsToMany('Tags');
    }
}

// In src/Model/Collection/TagsCollection.php
class TagsCollection extends BaseCollection
{
    public function initialize(array $config): void
    {
        $this->belongsToMany('Articles');
    }
}
```

We can also define a more specific relationship using configuration:

```php
// In src/Model/Collection/TagsCollection.php
class TagsCollection extends BaseCollection
{
    public function initialize(array $config): void
    {
        $this->belongsToMany('Articles', [
            'joinCollection' => 'articles_tags',
        ]);
    }
}
```

> [!NOTE]
> The ODM option is `joinCollection` (the ODM analog of Cake's `joinTable`).
> The value names the Mongo collection that holds the link documents. When it
> is omitted, the default name is derived from the aliases of the two sides,
> e.g. `articles_tags`.

Possible keys for belongsToMany association arrays include:

- **className**: The class name of the other collection. This is the same name
  used when getting an instance of the collection. In the 'Articles belongsToMany
  Tags' example, it should be 'Tags'. The default value is the name of the
  association.
- **joinCollection**: The name of the join collection used in this association
  (if the current collection doesn't adhere to the naming convention for
  belongsToMany join collections). By default, this collection name will be
  used to load the collection instance for the join collection.
- **foreignKey**: The name of the foreign key that references the current model
  found on the join collection, or list in case of composite foreign keys.
  This is especially handy if you need to define multiple belongsToMany
  relationships. The default value for this key is the underscored, singular
  name of the current model, suffixed with `_id`.
- **bindingKey**: The name of the field in the current collection, that will be
  used for matching the `foreignKey`. Defaults to the primary key.
- **targetForeignKey**: The name of the foreign key that references the target
  model found on the join collection, or list in case of composite foreign
  keys. The default value for this key is the underscored, singular name of
  the target model, suffixed with `_id`.
- **conditions**: An array of `find()` compatible conditions. If you have
  conditions on an associated collection, you should use a `through` model, and
  define the necessary belongsTo associations on it. It is recommended to use
  the `finder` option instead.
- **sort**: an array of find() compatible order clauses.
- **dependent**: When the dependent key is set to `true`, and a document is
  deleted, the data of the join collection is also deleted. The default value
  in the ODM is `true` (Cake's default is `false`).
- **through**: Allows you to provide either the alias of the collection
  instance you want used on the join collection, or the instance itself. This
  makes customizing the join collection keys possible, and allows you to
  customize the behavior of the pivot collection.
- **cascadeCallbacks**: When this is `true`, cascaded deletes will load and
  delete documents so that callbacks are properly triggered on join collection
  records. When `false`, `deleteAll()` is used to remove associated data and
  no callbacks are triggered. This defaults to `false` to help reduce
  overhead.
- **propertyName**: The property name that should be filled with data from the
  associated collection into the source results. By default, this is the
  underscored & plural name of the association, so `tags` in our example.
- **strategy**: Defines the query strategy to use. Defaults to `subquery`,
  which is executed as a separate query for the junction ids followed by an
  in-pipeline `$lookup` of the targets. `select` uses the same two-phase load
  but reads the junction ids directly. `lookup` performs both joins inside the
  root aggregation pipeline.
- **saveStrategy**: Either `append` or `replace`. Defaults to `replace`.
  Indicates the mode to be used for saving associated documents. The former
  will only create new links between both sides of the relation and the latter
  will do a wipe and replace to create the links between the passed documents
  when saving.
- **finder**: The finder method to use when loading associated records.

Once this association has been defined, find operations on the Articles
collection can contain the Tag records if they exist:

```php
// In a controller or collection method.
$query = $articles->find('all')->contain(['Tags'])->all();
foreach ($query as $article) {
    echo $article->tags[0]->text;
}
```

The default `subquery` strategy first loads the parent articles and collects
their `_id`s, then resolves the tags through the join collection. When the
`lookup` strategy is used, the following two-stage pipeline is generated
instead:

```php
[
    ['$lookup' => [
        'from' => 'articles_tags',
        'as' => '_join_tags',
        'localField' => '_id',
        'foreignField' => 'article_id',
    ]],
    ['$lookup' => [
        'from' => 'tags',
        'as' => 'tags',
        'localField' => '_join_tags.tag_id',
        'foreignField' => '_id',
    ]],
]
```

The intermediate `_join_tags` array carries the link documents; the ODM
resolves the target records through the second `$lookup`. When you use
`matching('Tags')`, an `$unwind` over the `tags` path is added so that only
articles that actually have a matching tag are returned.

### Using the 'through' Option

If you plan on adding extra information to the join/pivot collection, or if you
need to use join fields outside of the conventions, you will need to define the
`through` option. The `through` option provides you full control over how the
belongsToMany association will be created.

It is sometimes desirable to store additional data with a many to many
association. Consider the following:

    Student BelongsToMany Course
    Course BelongsToMany Student

A Student can take many Courses and a Course can be taken by many Students. This
is a simple many to many association. The following collection would suffice:

    { _id, student_id, course_id }

Now what if we want to store the number of days that were attended by the
student on the course and their final grade? The collection we'd want would be:

    { _id, student_id, course_id, days_attended, grade }

The way to implement our requirement is to use a **join model**, otherwise known
as a **hasMany through** association. That is, the association is a model
itself. So, we can create a new model CoursesMemberships. Take a look at the
following models:

```php
class StudentsCollection extends BaseCollection
{
    public function initialize(array $config): void
    {
        $this->belongsToMany('Courses', [
            'through' => 'CoursesMemberships',
        ]);
    }
}

class CoursesCollection extends BaseCollection
{
    public function initialize(array $config): void
    {
        $this->belongsToMany('Students', [
            'through' => 'CoursesMemberships',
        ]);
    }
}

class CoursesMembershipsCollection extends BaseCollection
{
    public function initialize(array $config): void
    {
        $this->belongsTo('Students');
        $this->belongsTo('Courses');
    }
}
```

The CoursesMemberships join collection uniquely identifies a given Student's
participation on a Course in addition to extra meta-information.

When using a query object with a BelongsToMany relationship with a `through`
model, add contain and matching conditions for the association target collection
into your query object. The `through` collection can then be referenced in
other conditions such as a where condition by designating the through collection
name before the field you are filtering on:

```php
// In a StudentsCollection method or controller action.
$query = $this->Students->find(
    'list',
    valueField: 'first_name',
    order: 'Students._id',
)
    ->contain(['Courses'])
    ->matching('Courses')
    ->where(['CoursesMemberships.grade' => 'B']);
```

<a id="embedded-associations"></a>

## Embedded Associations (embedOne / embedMany)

The ODM extends the four classic association types with Mongo's document model.
Instead of storing a foreign key that points into another collection, embedded
associations store the related data **inside the parent document**. This is the
classic MongoDB denormalization pattern: it keeps all related data in a single
document, so reading it back requires zero additional queries and preserves
atomicity when the parent is written.

`class` Crustum\\Mongo\\ODM\\Association\\**Embedded** (abstract), with the two
concrete subtypes **EmbedOne** and **EmbedMany**.

Embedded associations are registered with `embedOne()` and `embedMany()` on the
collection. Because the data lives inside the parent document, the association
"target" is the source collection itself - no other collection is involved:

```php
namespace App\Model\Collection;

use Crustum\Mongo\ODM\BaseCollection;
use App\Model\Document\Address;

class UsersCollection extends BaseCollection
{
    public function initialize(array $config): void
    {
        $this->embedMany('Addresses', [
            'documentClass' => Address::class,
            'propertyName' => 'addresses',
        ]);

        $this->embedOne('Profile', [
            'documentClass' => Address::class,
            'propertyName' => 'profile',
        ]);
    }
}
```

The `documentClass` option names the `Document` subclass used to hydrate each
embedded item. Without it, plain `Crustum\Mongo\ODM\Document` instances are
created.

Loading embedded associations does not add any queries: the data is already in
the parent document, so `contain()` is effectively free:

```php
$query = $users->find('all')->contain(['addresses', 'profile'])->all();
foreach ($query as $user) {
    echo $user->addresses[0]->city;   // Document[] for embedMany
    echo $user->profile->city;        // Document|null for embedOne
}
```

Filtering embedded data uses the dotted path for the embedded field. A
`where(['addresses.city' => 'NYC'])` matches the whole document; to require one
of the array items to match all conditions, pass the array form which becomes
an `$elemMatch`:

```php
// Dotted path match - any address in the array with city 'NYC'
$query = $users->find()->where(['addresses.city' => 'NYC']);

// $elemMatch - one address item must match BOTH conditions
$query = $users->find()->where([
    'addresses' => ['city' => 'NYC', 'zip' => '10001'],
]);

// Existence filters via matching()/notMatching()
$query = $users->find()->matching('addresses');
$query = $users->find()->notMatching('addresses');
```

`matching()` on an embedded association compiles to a plain `$match` on the
field: the field must exist and be non-empty (with conditions, an
`$elemMatch`). `notMatching()` matches documents where the field is absent,
`null`, or an empty array.

Embedded children are saved through their parent. A new child is added by
appending to the property and saving the parent:

```php
$user = $users->get('000000000000000000000001');
$user->addresses[] = new Address(['city' => 'Berlin']);
$users->save($user);
```

Updating an existing child with its own `_id` is routed to a positional update
so only the changed child is touched:

```php
$user = $users->get('000000000000000000000001');
$address = $user->addresses[0];
$address->city = 'Boston';
$users->save($address);
```

The ODM tracks the parent behind the child document (via the association) and
translates the child save into an update on the parent's field: `$set` for an
`embedOne`, `$push`/`$set` with the positional `$` operator for `embedMany`.
Removing a child is done with `$pull` on its `_id`. Deleting the parent cascades
to the embedded children when `dependent` is `true`.

Embedded children can be validated through the parent. Define a validator with
`setEmbeddedValidator()` (either a `Validator` instance or a closure that
receives and returns one), and the child documents are validated before the
parent is saved.

Possible keys for embedOne/embedMany association arrays include:

- **documentClass**: The `Document` class used to hydrate embedded items.
- **propertyName**: The property name that should be filled with data on the
  parent document. By default, this is the underscored, singular (embedOne) or
  plural (embedMany) name of the association.
- **localKey**: The field on the parent document that holds the embedded data.
  Defaults to the association property.
- **dependent**: When `true`, embedded children are removed when the parent is
  deleted (cascading `$pull`/`$unset`).
- **embeddedValidator**: A `Validator` instance or a closure receiving and
  returning one, used to validate embedded children before save.
- **strategy**: Only `'embed'` is accepted. Passing any other value throws an
  `InvalidArgumentException`.

The `localKey` can be overridden when the stored field differs from the
property name:

```php
$this->embedMany('Addresses', [
    'documentClass' => Address::class,
    'propertyName' => 'addresses',
    'localKey' => 'contact_addresses',
]);
```

<a id="dbref-associations"></a>

## DBRef Associations

`class` Crustum\\Mongo\\ODM\\Association\\**DBRef** (extends `Embedded`)

A DBRef association stores a Mongo database reference on the parent document - a
BSON document of the form `{ $ref: 'users', $id: ObjectId(...) }` - instead of
the full related data. The reference is resolved on demand, which keeps the
parent document small while still allowing access to the referenced document.

```php
namespace App\Model\Collection;

use Crustum\Mongo\ODM\BaseCollection;

class ArticlesCollection extends BaseCollection
{
    public function initialize(array $config): void
    {
        $this->dbref('Authors', [
            'collection' => 'users',
            'propertyName' => 'author',
        ]);
    }
}
```

The `collection` option names the Mongo collection the reference points at.
The property then hydrates to a `Document` by resolving the `$ref`/`$id`
pointer through the connection:

```php
$article = $articles->get('000000000000000000000001');
$author = $article->author;   // resolves the $ref/$id pointer
echo $author->username;
```

Create a DBRef value for a document with `createRef()`:

```php
$author = $users->get('000000000000000000000002');
$association = $articles->getAssociation('Authors');
$article->author = $association->createRef($author); // { $ref: 'users', $id: ... }
$articles->save($article);
```

Possible keys for dbref association arrays include:

- **collection**: The name of the Mongo collection referenced by the `$ref`
  field. Required.
- **propertyName**: The property name that holds the reference on the parent
  document. By default, this is the underscored, singular name of the
  association.
- **localKey**: The field on the parent document that holds the DBRef value.
  Defaults to the association property.

Choose between the association kinds by data access pattern:

- **embedMany/embedOne**: data is always read together with the parent, needs
  atomic updates, and is bounded in size. Best for line items, addresses, and
  other "owned" data.
- **belongsTo/hasOne/hasMany/belongsToMany**: data is shared, queried
  independently, and normalized. Use keys and `contain()`/`matching()`.
- **dbref**: a normalized reference you want to keep, but resolve on demand
  rather than through aggregation. Useful when the target lives in another
  database and `$lookup` is not an option.

<a id="association-finder"></a>

## Using Association Finders

By default, associations load records based on the foreign key fields. If you
want to define additional conditions for associations, you can use a `finder`.
When an association is loaded, the ODM runs your [custom
finder](retrieving-data-and-resultsets#custom-find-methods) against the target
collection to load, update, or delete associated records. Using finders lets
you encapsulate your queries and make them more reusable.

Finders are configured with the `finder` option or the `setFinder()` setter.
The default finder is `all`:

```php
class ArticlesCollection extends BaseCollection
{
    public function initialize(array $config): void
    {
        $this->belongsTo('Authors', [
            'finder' => 'published',
        ]);

        $this->hasMany('Comments')
            ->setFinder('approved');
    }

    public function findPublished(SelectQuery $query): SelectQuery
    {
        return $query->where(['published' => 'Y']);
    }

    public function findApproved(SelectQuery $query): SelectQuery
    {
        return $query->where(['moderated' => true]);
    }
}
```

The finder can also be supplied per containment, using the array form
`['finder' => ['name', 'with', 'options']]`.

There are some limitations when using finders to load data in associations
that are loaded in-pipeline with the `lookup` strategy (belongsTo/hasOne by
default). The finder query is compiled into a surrogate target query, and only
the following aspects are carried into the `$lookup`:

- Where conditions (become a `$match` inside the `$lookup`).
- Selected fields (become the projection).
- Order clauses (become a `$sort`).
- Additional aggregation pipeline stages and contained associations.

Other aspects of the query, such as result formatters or map/reduce-style
post-processing, are not applied to pipeline-loaded associations.
Associations that are *not* loaded through the pipeline
(hasMany/belongsToMany with the `select`/`subquery` strategies), do not have
the above restrictions and can also use result formatters.

## Association Conventions

By default, associations should be configured and referenced using the
CamelCase style. This enables property chains to related collections in the
following way:

```php
$this->MyCollectionOne->MyCollectionTwo->find()->...;
```

`BaseCollection::__get()` resolves an association alias to the association
object, which forwards method calls to its target collection.

Association properties on documents do not use CamelCase conventions though.
Instead for a hasOne/belongsTo relation like "User belongsTo Roles", you would
get a `role` property instead of `Role` or `Roles`:

```php
// A single document (or null if not available)
$role = $user->role;
```

Whereas for the other direction "Roles hasMany Users" it would be:

```php
// Collection of user documents (or null if not available)
$users = $role->users;
```

## Loading Associations

Once you've defined your associations you can
[eager load associations](retrieving-data-and-resultsets#eager-loading-associations)
when fetching results. `contain()` pulls the related records into each loaded
document, and `matching()`/`notMatching()` filter the source documents by the
existence of related records - see the loading strategies above for which
queries each association type produces.

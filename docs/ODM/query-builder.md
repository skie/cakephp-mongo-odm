# Query Builder

`class` Crustum\Mongo\ODM\Query\SelectQuery

The ODM's query builder provides a simple to use fluent interface for creating
and running queries. By composing queries together, you can create advanced
queries using unions and subqueries with ease.

Underneath the covers, the query builder compiles to a **MongoDB aggregation
pipeline** (or a find filter) — there is no SQL string building, so the classic
SQL-injection class does not exist here. The injection surface moves to field
paths and operator/function names, see
[Injection Prevention](#sql-injection-prevention).

> ### PENDING
>
> This page is ported from `docs/orm/query-builder.md` (the largest and most
> divergent page). The sections below are verified against
> `crustum/src/Database/Query/`, `src/Database/FunctionsBuilder.php`,
> `src/Database/Expression/`, `src/ODM/Query/SelectQuery.php` and
> `src/ODM/BaseCollection.php`. A few sections depend on the Database-layer
> operator expansion proposed in
> `docs/reference/50-aggregation-builder-comparison-proposals.md`
> (`stringAgg`, custom SQL functions, multi-branch `$switch` case) — those are
> marked *pending doc 50*. Samples still need to run against a live
> `test_mongo_db` before this banner is removed (see
> `docs/working-memory-docs/56-query-builder-analysis.md`).

## The SelectQuery Object

The easiest way to create a `SelectQuery` object is to use `find()` from a
`BaseCollection` object. This method will return an incomplete query ready to
be modified. You can also use a collection's connection object to access the
lower level query builder that does not include ODM features, if necessary. See
the [Database Queries](../ODM/Database-basics#database-queries) section for
more information:

```php
use Crustum\Mongo\ODM\Locator\LocatorAwareTrait;

$articles = $this->fetchCollection('Articles');

// Start a new query.
$query = $articles->find();
```

When inside a controller, you can use the automatic collection variable that is
created using the conventions system:

```php
// Inside ArticlesController.php

$query = $this->Articles->find();
```

### Selecting Rows From A Collection

```php
use Crustum\Mongo\ODM\Locator\LocatorAwareTrait;

$query = $this->fetchCollection('Articles')->find();

foreach ($query->all() as $article) {
    debug($article->title);
}
```

For the remaining examples, assume that `$articles` is a
`Crustum\Mongo\ODM\BaseCollection`. When inside controllers, you can use
`$this->Articles` instead of `$articles`.

Almost every method in a `SelectQuery` object will return the same query, this
means that `SelectQuery` objects are lazy, and will not be executed unless you
tell them to:

```php
$query->where(['_id' => '000000000000000000000001']); // Return the same query object
$query->orderBy(['title' => 'DESC']); // Still same object, no query executed
```

You can of course chain the methods you call on SelectQuery objects:

```php
$query = $articles
    ->find()
    ->select(['_id', 'name'])
    ->where(['_id !=' => '000000000000000000000001'])
    ->orderBy(['created' => 'DESC']);

foreach ($query->all() as $article) {
    debug($article->created);
}
```

If you try to call `debug()` on a SelectQuery object, you will see its internal
state and the compiled filter/pipeline that will be executed in the database:

```php
debug($articles->find()->where(['_id' => '000000000000000000000001']));

// Outputs
// ...
// 'filter' => ['_id' => '000000000000000000000001']
// ...
```

You can execute a query directly without having to use `foreach` on it.
The easiest way is to either call the `all()` or `toList()` methods:

```php
$resultsIteratorObject = $articles
    ->find()
    ->where(['_id >' => '000000000000000000000001'])
    ->all();

foreach ($resultsIteratorObject as $article) {
    debug($article->_id);
}

$resultsArray = $articles
    ->find()
    ->where(['_id >' => '000000000000000000000001'])
    ->all()
    ->toList();

foreach ($resultsArray as $article) {
    debug($article->_id);
}

debug($resultsArray[0]->title);
```

In the above example, `$resultsIteratorObject` will be an instance of
`Crustum\Mongo\ODM\ResultSet`, an object you can iterate and apply several
extracting and traversing methods on.

Often, there is no need to call `all()`, you can simply iterate the
SelectQuery object to get its results. Query objects can also be used directly
as the result object; trying to iterate the query, calling `toList()` or
`toArray()`, will result in the query being executed and results returned to
you.

### Selecting A Single Row From A Collection

You can use the `first()` method to get the first result in the query:

```php
$article = $articles
    ->find()
    ->where(['_id' => '000000000000000000000001'])
    ->first();

debug($article->title);
```

The `first()` method adds a `$limit: 1` stage when called on a fresh query.

### Getting A List Of Values From A Column

```php
// Use the extract() method from the collections library
// This executes the query as well
$allTitles = $articles->find()->all()->extract('title');

foreach ($allTitles as $title) {
    echo $title;
}
```

You can also get a key-value list out of a query result:

```php
$list = $articles->find('list')->all();
foreach ($list as $id => $title) {
    echo "$id : $title"
}
```

The keys are the `_id` values (hex strings). For more information on how to
customize the fields used for populating the list refer to the
[Table Find List](../ODM/retrieving-data-and-resultsets#table-find-list)
section.

### ResultSet Is A Collection Object

Once you get familiar with the Query object methods, it is strongly encouraged
that you visit the [Collection](../core-libraries/collections) section to
improve your skills in efficiently traversing the results. The ResultSet
(returned by calling the `SelectQuery`'s `all()` method) implements the
collection interface:

```php
// Use the combine() method from the collections library
// This is equivalent to find('list')
$keyValueList = $articles->find()->all()->combine('_id', 'title');

// An advanced example
$results = $articles->find()
    ->where(['_id >' => '000000000000000000000001'])
    ->orderBy(['title' => 'DESC'])
    ->all()
    ->map(function ($row) {
        $row->trimmedTitle = trim($row->title);

        return $row;
    })
    ->combine('_id', 'trimmedTitle') // combine() is another collection method
    ->toArray(); // Also a collections library method

foreach ($results as $id => $trimmedTitle) {
    echo "$id : $trimmedTitle";
}
```

### Queries Are Lazily Evaluated

Query objects are lazily evaluated. This means a query is not executed until one
of the following things occur:

- The query is iterated with `foreach`.
- The query's `execute()` method is called. This will return the underlying
  statement object, and is to be used with insert/update/delete queries.
- The query's `first()` method is called. This will return the first result in
  the set built by the find (it adds a `$limit: 1` stage).
- The query's `all()` method is called. This will return the result set and can
  only be used with select queries.
- The query's `toList()` or `toArray()` method is called.

Until one of these conditions are met, the query can be modified without
additional work being sent to the database. It also means that if a Query hasn't
been evaluated, no pipeline is ever sent to the database. Once executed,
modifying and re-evaluating a query will result in additional work being run.
Calling the same query without modification multiple times will return the same
reference.

If you want to take a look at what pipeline/filter Crustum is generating, you
can turn database [query logging](../ODM/Database-basics#database-query-logging)
on.

## Selecting Data

To limit the fields fetched, you can use the `select()` method. The `select()`
method builds a Mongo **projection map** — `['field' => 1]`:

```php
$query = $articles->find();
$query->select(['_id', 'title', 'body']);
foreach ($query->all() as $row) {
    debug($row->title);
}
```

You can set aliases for fields by providing fields as an associative array:

```php
// Results in {pk: '$_id', aliased_title: '$title', body: 1}
$query = $articles->find();
$query->select(['pk' => '_id', 'aliased_title' => 'title', 'body']);
```

To select distinct fields, you can use the `distinct()` method:

```php
$query = $articles->find();
$query->select(['country'])
    ->distinct(['country']);
```

To set some basic conditions you can use the `where()` method:

```php
// Conditions are combined with AND ($and)
$query = $articles->find();
$query->where(['title' => 'First Post', 'published' => true]);

// You can call where() multiple times
$query = $articles->find();
$query->where(['title' => 'First Post'])
    ->where(['published' => true]);
```

You can also pass an anonymous function to the `where()` method. The passed
anonymous function will receive an instance of
`Crustum\Mongo\Database\Expression\QueryExpression` as its first argument, and
`Crustum\Mongo\ODM\Query\SelectQuery` as its second:

```php
$query = $articles->find();
$query->where(function (QueryExpression $exp, SelectQuery $q) {
    return $exp->eq('published', true);
});
```

See the [Advanced Conditions](#advanced-conditions) section to find out how to
construct more complex `WHERE` conditions.

### Selecting Specific Fields

By default, a query will select all fields from a collection, the exception is
when you call the `select()` function yourself and pass certain fields:

```php
// Only select _id and title from the articles collection
$articles->find()->select(['_id', 'title']);
```

If you wish to still select all fields from a collection after having called
`select($fields)`, you can pass the collection instance to `select()` for this
purpose:

```php
// Only all fields from the articles collection including
// a calculated slug field.
$query = $articlesCollection->find();
$query
    ->select(['slug' => $query->func()->concat(['title' => 'identifier', '-', '_id' => 'identifier'])])
    ->select($articlesCollection); // Select all fields from articles
```

The `concat` argument markers `[field => 'identifier']` reference a field
(`$title`); a plain argument is bound as a literal string value.

You can use `selectAlso()` to select all fields on a collection and
*also* select some additional fields:

```php
$query = $articlesCollection->find();
$query->selectAlso(['count' => $query->func()->count()]);
```

If you want to select all but a few fields on a collection, you can use
`selectAllExcept()`:

```php
$query = $articlesCollection->find();

// Get all fields except the published field.
$query->selectAllExcept($articlesCollection, ['published']);
```

You can also pass an `Association` object when working with contained
associations.

### Using Aggregation Functions

Crustum's `FunctionsBuilder` wraps a set of MongoDB aggregation operators. The
builder is reached through `$query->func()`. Each method compiles to a `$`
operator inside the pipeline stage it is used in:

```php
// Results in a $count accumulator (renders $sum: 1 inside a $group)
$query = $articles->find();
$query->select(['count' => $query->func()->count()]);
```

To count non-null values of a field inside a `$group` (SQL `COUNT(field)`), use
`countField()`:

```php
$query = $articles->find();
$query->select(['viewed' => $query->func()->countField('view_count')]);
```

You can access existing wrappers for several aggregation operators through
`SelectQuery::func()`:

`rand()`
Generate a random value via `$rand`.

`sum()`
Calculate a sum (`$sum`).

`avg()`
Calculate an average (`$avg`).

`min()`
Calculate the min of a field (`$min`).

`max()`
Calculate the max of a field (`$max`).

`count()`
Calculate a count (`$count` — renders `$sum: 1` inside a `$group`). Use
`countField($field)` for `COUNT(field)`.

`cast()`
Convert a field or expression from one data type to another (`$convert`).

`concat()`
Concatenate two or more values together (`$concat`).

`coalesce()`
Coalesce values (`$ifNull`-style). *Assumes arguments are values/fields.*

`dateDiff()`
Get the difference between two dates/times: `dateDiff($startDate, $endDate,
$unit, $timezone = null)` — `$dateDiff`.

`extract()` / `datePart()`
Return a date part (year, month, day, …) from a date expression (`$year`,
`$month`, …).

`dateAdd()`
Add a time unit to a date expression (`$dateAdd`).

`dayOfWeek()` / `weekday()`
Return the day-of-week from a date (`$dayOfWeek`).

`now()`
Defaults to returning the current date and time as `$$NOW`.

Window-only operators `rowNumber()`, `lag()`, `lead()` exist for use inside a
`$setWindowFields` stage (see
[Window Functions](#window-functions) below).

When providing arguments for aggregation functions, there are two kinds of
parameters you can use: identifier arguments and literal values. Identifier
arguments allow you to reference fields; mark them with a `[field =>
'identifier']` entry. Plain (unmarked) arguments are bound as literal values.
For example:

```php
$query = $articles->find()->innerJoinWith('Categories');
$concat = $query->func()->concat([
    'title' => 'identifier',
    ' - CAT: ',
    'Categories.name' => 'identifier',
]);
$query->select(['link_title' => $concat]);
```

The `' - CAT: '` argument is passed as a literal string; the identifier-marked
arguments become `$title` / `$Categories.name` field references.

> [!NOTE]
> Use `func()` to pass untrusted user data to any operator — values are typed
> native values, never interpolated into operator names or field paths.

> [!WARNING]
> The SQL-oriented wrapper section of the source page (`stringAgg()`, custom
> SQL functions such as `year()`/`date_format()`) is **pending doc 50** — the
> explicit operator expansion in
> `docs/reference/50-aggregation-builder-comparison-proposals.md` (P1.4). Use
> `func()->datePart('year', ...)` for year extraction; string aggregation with
> a separator is not yet wrapped.

### Ordering Results

To apply ordering, you can use the `orderBy()` method. This compiles to a
`$sort` stage:

```php
$query = $articles->find()
    ->orderBy(['title' => 'ASC', '_id' => 'ASC']);
```

When calling `orderBy()` multiple times on a query, multiple clauses will be
appended. However, when using finders you may sometimes need to overwrite the
ordering. Set the second parameter of `orderBy()` (as well as `orderByAsc()` or
`orderByDesc()`) to `SelectQuery::OVERWRITE` or to `true`:

```php
$query = $articles->find()
    ->orderBy(['title' => 'ASC']);
// Later, overwrite the sort instead of appending to it.
$query = $articles->find()
    ->orderBy(['created' => 'DESC'], SelectQuery::OVERWRITE);
```

The `orderByAsc` and `orderByDesc` methods can be used when you need to sort on
complex expressions:

```php
$query = $articles->find();
$concat = $query->func()->concat([
    'title' => 'identifier',
    'synopsis' => 'identifier',
]);
$query->orderByAsc($concat);
```

### Limiting Results

To limit the number of rows or set the row offset you can use the `limit()`
and `page()` methods. These compile to `$limit` and `$skip` stages:

```php
// Fetch rows 50 to 100
$query = $articles->find()
    ->limit(50)
    ->page(2);
```

As you can see from the examples above, all the methods that modify the query
provide a fluent interface, allowing you to build a query through chained method
calls.

### Aggregates - Group and Having

When using aggregate functions like `count` and `sum` you may want to use
`group by` and `having` clauses:

```php
$query = $articles->find();
$query->select([
    'count' => $query->func()->count(),
    'published_date' => $query->func()->datePart('year', $query->identifier('created')),
])
->groupBy('published_date')
->having(['count >' => 3]);
```

`groupBy()` compiles to the `$group` stage's `_id`, and `having()` filters the
grouped output with an additional `$match` stage. The `count` accumulator
renders as `$sum: 1` inside the `$group`.

### Case Statements

Conditional values are built with `func()->cond()` (the `$cond` operator) —
the SQL `CASE ... WHEN ... THEN ... ELSE` construct:

```php
$query = $articles->find();
$published = $query->func()->cond(
    $query->func()->eq('$published', true),
    'Y',
    'N',
);
$query->select(['published_flag' => $published]);
```

The `condWhenPresent()` shorthand returns one value when a field path is present
and another when it is missing or null:

```php
$query = $articles->find();
$query->select([
    'display_name' => $query->func()->condWhenPresent('nickname', '$nickname', '$full_name'),
]);
```

> [!NOTE]
> The multi-branch `case()`/`addCase()` chain and its `$switch` equivalent are
> **pending doc 50** (P1.4 conditional operator expansion). For now compose
> nested `$cond` calls, or build the stage directly with a raw
> `new FunctionExpression('$switch', [...])` and pass it through `select()`.

## Fetching Arrays Instead of Documents

While ORMs and object result sets are powerful, creating documents is sometimes
unnecessary. For example, when accessing aggregated data, building a Document
may not make sense. The process of converting the database results to documents
is called hydration. If you wish to disable this process you can do this:

```php
$query = $articles->find();
$query->enableHydration(false); // Results as arrays instead of documents
$result = $query->toList(); // Execute the query and return the array
```

After executing those lines, your result should look similar to this:

```php
[
    ['_id' => '000000000000000000000001', 'title' => 'First Article', 'body' => 'Article 1 body' ...],
    ['_id' => '000000000000000000000002', 'title' => 'Second Article', 'body' => 'Article 2 body' ...],
    ...
]
```

## Projecting Results Into DTOs

In addition to fetching results as Document objects or arrays, you can project
query results directly into Data Transfer Objects (DTOs). DTOs offer several
advantages:

- **Memory efficiency** - DTOs consume less memory than Document objects.
- **Type safety** - DTOs provide strong typing and IDE autocompletion support,
  unlike plain arrays.
- **Decoupled serialization** - DTOs let you separate your API response
  structure from your database schema, making it easier to version APIs or
  expose only specific fields.
- **Read-only data** - Using `readonly` classes ensures data integrity and
  makes your intent clear.

The `projectAs()` method allows you to specify a DTO class that results will
be hydrated into:

```php
// Define a DTO class
readonly class ArticleDto
{
    public function __construct(
        public string $_id,
        public string $title,
        public ?string $body = null,
    ) {
    }
}

// Use projectAs() to hydrate results into DTOs
$articles = $articlesCollection->find()
    ->select(['_id', 'title', 'body'])
    ->projectAs(ArticleDto::class)
    ->toArray();
```

#### DTO Creation Methods

Crustum supports two approaches for creating DTOs:

**Reflection-based constructor mapping** - Crustum will use reflection to map
database columns to constructor parameters:

```php
readonly class ArticleDto
{
    public function __construct(
        public string $_id,
        public string $title,
        public ?AuthorDto $author = null,
    ) {
    }
}
```

**Factory method pattern** - If your DTO class has a `createFromArray()`
static method, Crustum will use that instead:

```php
class ArticleDto
{
    public string $_id;
    public string $title;

    public static function createFromArray(
        array $data,
        bool $ignoreMissing = false,
    ): self {
        $dto = new self();
        $dto->_id = $data['_id'];
        $dto->title = $data['title'];

        return $dto;
    }
}
```

#### Nested Association DTOs

You can project associated data into nested DTOs. Use the `#[CollectionOf]`
attribute to specify the type of elements in array properties:

```php
use Cake\ORM\Attribute\CollectionOf;

readonly class ArticleDto
{
    public function __construct(
        public string $_id,
        public string $title,
        public ?AuthorDto $author = null,
        #[CollectionOf(CommentDto::class)]
        public array $comments = [],
    ) {
    }
}

readonly class AuthorDto
{
    public function __construct(
        public string $_id,
        public string $name,
    ) {
    }
}

readonly class CommentDto
{
    public function __construct(
        public string $_id,
        public string $body,
    ) {
    }
}

// Fetch articles with associations projected into DTOs
$articles = $articlesCollection->find()
    ->contain(['Authors', 'Comments'])
    ->projectAs(ArticleDto::class)
    ->toArray();
```

#### Using DTOs for API Responses

DTOs are particularly useful for building API responses where you want to
control the output structure independently from your database schema:

```php
readonly class ArticleApiResponse
{
    public function __construct(
        public string $_id,
        public string $title,
        public string $slug,
        public string $authorName,
        public string $publishedAt,
    ) {
    }

    public static function createFromArray(
        array $data,
        bool $ignoreMissing = false,
    ): self {
        return new self(
            _id: $data['_id'],
            title: $data['title'],
            slug: Inflector::slug($data['title']),
            authorName: $data['author']['name'] ?? 'Unknown',
            publishedAt: $data['created']->format('c'),
        );
    }
}

// In your controller
$articles = $this->Articles->find()
    ->contain(['Authors'])
    ->projectAs(ArticleApiResponse::class)
    ->toArray();

return $this->response->withType('application/json')
    ->withStringBody(json_encode(['articles' => $articles]));
```

> [!NOTE]
> DTO projection is applied as the final formatting step, after all other
> formatters and behaviors have processed the results. This ensures
> compatibility with existing behavior formatters while still providing the
> benefits of DTOs.

## Adding Calculated Fields

After your queries, you may need to do some post-processing. If you need to add
a few calculated fields or derived data, you can use the `formatResults()`
method. This is a lightweight way to map over the result sets. If you need more
control over the process, or want to reduce results you should use the
[Map/Reduce](../ODM/retrieving-data-and-resultsets#map-reduce) feature instead.
If you were querying a list of people, you could calculate their age with a
result formatter:

```php
// Assuming we have built the fields, conditions and containments.
$query->formatResults(function (\Cake\Collection\CollectionInterface $results) {
    return $results->map(function ($row) {
        $row['age'] = $row['birth_date']->diff(new \DateTime)->y;

        return $row;
    });
});
```

As you can see in the example above, formatting callbacks will get a
`ResultSet` as their first argument. The second argument will be the Query
instance the formatter was attached to. The `$results` argument can be
traversed and modified as necessary.

Result formatters are required to return an iterator object, which will be used
as the return value for the query. Formatter functions are applied after all the
Map/Reduce routines have been executed. Result formatters can be applied from
within contained associations as well. Crustum will ensure that your formatters
are properly scoped. For example, doing the following would work as you may
expect:

```php
// In a method in the Articles collection
$query->contain(['Authors' => function ($q) {
    return $q->formatResults(function (\Cake\Collection\CollectionInterface $authors) {
        return $authors->map(function ($author) {
            $author['age'] = $author['birth_date']->diff(new \DateTime)->y;

            return $author;
        });
    });
}]);

// Get results
$results = $query->all();

// Outputs 29
echo $results->first()->author->age;
```

As seen above, the formatters attached to associated query builders are scoped
to operate only on the data in the association. Crustum will ensure that
computed values are inserted into the correct document.

If you want to replace the results of an association finder with
`formatResults` and your replacement data is an associative array, use
`preserveKeys` to retain keys when results are mapped to the parent query. For
example:

```php
public function findSlugged(SelectQuery $query): SelectQuery
{
    return $query->applyOptions(['preserveKeys' => true])
        ->formatResults(function ($results) {
            return $results->indexBy(function ($record) {
                return Text::slug($record->name);
            });
        });
}
```

The `preserveKeys` option can be set as a contain option as well.

## Advanced Conditions

The query builder makes it simple to build complex filters. Grouped conditions
can be expressed by combining `where()` and expression objects. For simple
queries, you can build conditions using an array of conditions:

```php
$query = $articles->find()
    ->where([
        'author_id' => '000000000000000000000003',
        'OR' => [['view_count' => 2], ['view_count' => 3]],
    ]);
```

The above would generate a filter like:

```php
[
    'author_id' => '000000000000000000000003',
    '$or' => [['view_count' => 2], ['view_count' => 3]],
]
```

If you'd prefer to avoid deeply nested arrays, you can use the callback form of
`where()` to build your queries. The callback accepts a QueryExpression which
allows you to use the expression builder interface to build more complex
conditions without arrays. For example:

```php
$query = $articles->find()->where(function (QueryExpression $exp, SelectQuery $query) {
    // Use add() to add multiple conditions for the same field.
    $author = $query->expr()->or(['author_id' => '000000000000000000000003'])->add(['author_id' => '000000000000000000000002']);
    $published = $query->expr()->and(['published' => true, 'view_count' => 10]);

    return $exp->or([
        'promoted' => true,
        $query->expr()->and([$author, $published]),
    ]);
});
```

The above generates a filter similar to:

```php
[
    '$or' => [
        ['promoted' => true],
        ['$and' => [
            ['$or' => [
                ['author_id' => '000000000000000000000002'],
                ['author_id' => '000000000000000000000003'],
            ]],
            ['$and' => ['published' => true, 'view_count' => 10]],
        ]],
    ],
]
```

The `QueryExpression` passed to the callback allows you to use both
**combinators** and **conditions** to build the full expression.

Combinators
These create new `QueryExpression` objects and set how the conditions added
to that expression are joined together.

- `and()` creates new expression objects that join all conditions with `$and`.
- `or()` creates new expression objects that join all conditions with `$or`.

Conditions
These are added to the expression and automatically joined together
depending on which combinator was used.

The `QueryExpression` passed to the callback function defaults to `$and`:

```php
$query = $articles->find()
    ->where(function (QueryExpression $exp) {
        return $exp
            ->eq('author_id', '000000000000000000000002')
            ->eq('published', true)
            ->notEq('spam', true)
            ->gt('view_count', 10);
    });
```

Since we started off using `where()`, we don't need to call `and()`, as
that happens implicitly. The above shows a few new condition methods being
combined with `$and`. The resulting filter would look like:

```php
[
    '$and' => [
        ['author_id' => '000000000000000000000002'],
        ['published' => true],
        ['spam' => ['$ne' => true]],
        ['view_count' => ['$gt' => 10]],
    ],
]
```

However, if we wanted to use both `$and` & `$or` conditions we could do the
following:

```php
$query = $articles->find()
    ->where(function (QueryExpression $exp) {
        $orConditions = $exp->or(['author_id' => '000000000000000000000002'])
            ->eq('author_id', '000000000000000000000005');

        return $exp
            ->add($orConditions)
            ->eq('published', true)
            ->gte('view_count', 10);
    });
```

The **combinators** also allow you pass in a callback which takes the new
expression object as a parameter if you want to separate the method chaining:

```php
$query = $articles->find()
    ->where(function (QueryExpression $exp) {
        $orConditions = $exp->or(function (QueryExpression $or) {
            return $or->eq('author_id', '000000000000000000000002')
                ->eq('author_id', '000000000000000000000005');
        });

        return $exp
            ->not($orConditions)
            ->lte('view_count', 10);
    });
```

You can negate sub-expressions using `not()`:

```php
$query = $articles->find()
    ->where(function (QueryExpression $exp) {
        $orConditions = $exp->or(['author_id' => '000000000000000000000002'])
            ->eq('author_id', '000000000000000000000005');

        return $exp
            ->not($orConditions)
            ->lte('view_count', 10);
    });
```

Which will generate a filter similar to:

```php
[
    '$and' => [
        ['$nor' => [
            ['$or' => [
                ['author_id' => '000000000000000000000002'],
                ['author_id' => '000000000000000000000005'],
            ]],
        ]],
        ['view_count' => ['$lte' => 10]],
    ],
]
```

When using the expression objects you can use the following methods to create
conditions:

- `eq()` Creates an equality condition:

  ```php
  $query = $cities->find()
      ->where(function (QueryExpression $exp, SelectQuery $q) {
          return $exp->eq('population', 10000);
      });
  ```

- `notEq()` Creates an inequality condition:

  ```php
  $query = $cities->find()
      ->where(function (QueryExpression $exp, SelectQuery $q) {
          return $exp->notEq('population', 10000);
      });
  ```

- `like()` Creates a condition using a regex comparison. The pattern is used
  verbatim as a `$regex` pattern (SQL `%`/`_` wildcards are not translated —
  write regex directly):

  ```php
  $query = $cities->find()
      ->where(function (QueryExpression $exp, SelectQuery $q) {
          return $exp->like('name', '^A.*$');
      });
  # {name: {$regex: '^A.*$'}}
  ```

- `notLike()` Creates a negated regex condition:

  ```php
  $query = $cities->find()
      ->where(function (QueryExpression $exp, SelectQuery $q) {
          return $exp->notLike('name', '^A.*$');
      });
  ```

  > [!NOTE]
  > When the `LIKE` key is used in a **condition array** instead of the
  > expression method, SQL wildcards ARE translated — `%` becomes `.*` and `_`
  > becomes `.` (anchored with `^…$`):
  >
  > ```php
  > $query = $cities->find()->where(['name LIKE' => '%A%']);
  > # {name: {$regex: '^.*A.*$'}}
  > ```

- `in()` Create a condition using `$in`:

  ```php
  $query = $cities->find()
      ->where(function (QueryExpression $exp, SelectQuery $q) {
          return $exp->in('country_id', ['AFG', 'USA', 'EST']);
      });
  # {country_id: {$in: ['AFG', 'USA', 'EST']}}
  ```

- `notIn()` Create a negated `$nin` condition:

  ```php
  $query = $cities->find()
      ->where(function (QueryExpression $exp, SelectQuery $q) {
          return $exp->notIn('country_id', ['AFG', 'USA', 'EST']);
      });
  ```

- `gt()` Create a `$gt` condition:

  ```php
  $query = $cities->find()
      ->where(function (QueryExpression $exp, SelectQuery $q) {
          return $exp->gt('population', 10000);
      });
  ```

- `gte()` Create a `$gte` condition:

  ```php
  $query = $cities->find()
      ->where(function (QueryExpression $exp, SelectQuery $q) {
          return $exp->gte('population', 10000);
      });
  ```

- `lt()` Create a `$lt` condition:

  ```php
  $query = $cities->find()
      ->where(function (QueryExpression $exp, SelectQuery $q) {
          return $exp->lt('population', 10000);
      });
  ```

- `lte()` Create a `$lte` condition:

  ```php
  $query = $cities->find()
      ->where(function (QueryExpression $exp, SelectQuery $q) {
          return $exp->lte('population', 10000);
      });
  ```

- `isNull()` Create an equality-to-null condition (`{field: null}` matches
  documents where the field is null or missing):

  ```php
  $query = $cities->find()
      ->where(function (QueryExpression $exp, SelectQuery $q) {
          return $exp->isNull('population');
      });
  # {population: null}
  ```

- `isNotNull()` Create a negated-null condition (`{field: {$ne: null}}`):

  ```php
  $query = $cities->find()
      ->where(function (QueryExpression $exp, SelectQuery $q) {
          return $exp->isNotNull('population');
      });
  ```

- `between()` Create a `$gte`/`$lte` range condition:

  ```php
  $query = $cities->find()
      ->where(function (QueryExpression $exp, SelectQuery $q) {
          return $exp->between('population', 999, 5000000);
      });
  # {population: {$gte: 999, $lte: 5000000}}
  ```

- `notBetween()` Create a negated `BETWEEN` condition:

  ```php
  $query = $cities->find()
      ->where(function (QueryExpression $exp, SelectQuery $q) {
          return $exp->notBetween('population', 999, 5000000);
      });
  ```

- `equalFields()` Compare two fields to each other:

  ```php
  $query = $cities->find()
      ->where(function (QueryExpression $exp, SelectQuery $q) {
          return $exp->equalFields('countries.id', 'cities.country_id');
      });
  ```

- `exists()` / `notExists()` Create a **field-existence** condition (`$exists`):

  ```php
  $query = $cities->find()
      ->where(function (QueryExpression $exp, SelectQuery $q) {
          return $exp->exists('population');
      });
  ```

  > [!NOTE]
  > Unlike SQL, `exists()` here checks field existence in a document, not the
  > result of a `SELECT EXISTS (subquery)`. Subquery-style existence is
  > expressed with a `$lookup` + `$match` (see
  > [Subqueries](#subqueries)).

For a condition on a set of values combined with a null check, use
`whereNotInListOrNull()` (the port of SQL `NOT IN (…) OR field IS NULL`):

```php
$query = $cities->find()
    ->whereNotInListOrNull('country_id', ['AFG', 'USA', 'EST']);
# {$or: [{country_id: {$nin: [...]}}, {country_id: {$exists: false}}]}
```

The complementary `IN (...) OR field IS NULL` case has no dedicated helper —
compose the conditions with `$or`:

```php
$query = $cities->find()
    ->where(function (QueryExpression $exp, SelectQuery $q) {
        return $exp->or([
            $exp->in('country_id', ['AFG', 'USA', 'EST']),
            $exp->isNull('country_id'),
        ]);
    });
# {$or: [{country_id: {$in: [...]}}, {country_id: null}]}
```

Similarly, null-safe inequality (SQL `IS DISTINCT FROM`) is expressed with an
explicit `$ne`/`$eq` plus a null check.

### Using Identifiers in Expressions

When you need to reference a field in an expression you can use the
`identifier()` method:

```php
$query = $countries->find();
$query->select([
        'year' => $query->func()->datePart('year', $query->identifier('created')),
    ])
    ->where(function ($exp, $query) {
        return $exp->gt('population', 100000);
    });
```

You can use `identifier()` in comparisons to aggregations too:

```php
$query = $this->Orders->find();
$query->select(['Customers.customer_name', 'total_orders' => $query->func()->count()])
    ->contain('Customers')
    ->groupBy(['Customers.customer_name'])
    ->having(['total_orders >=' => $query->identifier('Customers.minimum_order_count')]);
```

> [!WARNING]
> Identifier expressions should never have untrusted data passed into them —
> they are field paths in the compiled pipeline.

### Collation

Collation in Mongo is a collection/server-level setting, not a per-comparison
feature. There is no per-expression collation on identifiers — the SQL
`name COLLATE ...` construct has no analog here.

### Automatically Creating IN Clauses

When building queries using the ODM, you will generally not have to indicate the
data types of the columns you are interacting with, as Crustum can infer the
types based on the schema data. To force an `$in` comparison, use an explicit
`IN` key or the `whereInList()` method:

```php
// Explicit IN key.
$query = $articles->find()
    ->where(['_id IN' => $ids]);

// Or whereInList().
$query = $articles->find()
    ->whereInList('_id', $ids);
```

Hex `_id` strings in the value list are converted to `ObjectId` values so the
query matches the database. The SQL `['field' => $list, ['field' => 'integer[]']]`
type-suffix form has no analog — an unmarked list value is treated as an
exact-array match, not `$in`.

### Automatic IS NULL Creation

When a condition value is expected to be `null` or any other value, you can
use the `IS` operator to automatically create the correct expression:

```php
$query = $categories->find()
    ->where(['parent_id IS' => $parentId]);
```

The above will generate `parent_id = :value` or `parent_id: {$exists: false}`
depending on the type of `$parentId`.

### Automatic IS NOT NULL Creation

When a condition value is expected not to be `null` or any other value, you
can use the `IS NOT` operator to automatically create the correct expression:

```php
$query = $categories->find()
    ->where(['parent_id IS NOT' => $parentId]);
```

The above will generate `parent_id != :value` or `parent_id: {$exists: true}`
depending on the type of `$parentId`.

### Raw Expressions

There is no raw SQL in Crustum — expressions are built from typed values and
operators only. Field-to-field comparisons use `equalFields()`, conditional
values use `func()->cond()`. Building a raw pipeline fragment is only possible
through the Database-layer `AggregationBuilder` / `RawStage` escape hatch (see
`docs/reference/50-aggregation-builder-comparison-proposals.md`).

> [!WARNING]
> Even with the escape hatch, never pass untrusted data into operator names or
> field paths.

### Using Connection Roles

If you have configured read/write connections in your application, you can have
a query run on the `read` connection using one of the role methods:

```php
// Run a query on the read connection
$query->useReadRole();

// Run a query on the write connection (default)
$query->useWriteRole();
```

### Expression Conjunction

The conjunction that joins conditions in a `QueryExpression` defaults to `$and`
and can be changed with `setConjunction()`:

```php
$query = $articles->find();
$expr = $query->expr(['1', '1'])->setConjunction('$or');
$query->select(['two' => $expr]);
```

The SQL arithmetic conjunction (`expr(['a','b'])->setConjunction('*')`) has no
analog — arithmetic is expressed with the `func()->add()` / `func()->multiply()`
operators:

```php
$query = $products->find();
$query->select(function ($query) {
    $stockQuantity = $query->func()->sum('Stocks.quantity');
    $totalStockValue = $query->func()->sum(
        $query->func()->multiply('Stocks.quantity', 'Products.unit_price'),
    );

    return [
        'Products.name',
        'stock_quantity' => $stockQuantity,
        'Products.unit_price',
        'total_stock_value' => $totalStockValue,
    ];
})
->innerJoinWith('Stocks')
->groupBy(['Products._id', 'Products.name', 'Products.unit_price']);
```

### Tuple Comparison

Tuple comparison involves comparing two sets of values element by element,
typically using comparison operators like `<, >, =`. The array form ports
directly:

```php
$products->find()
    ->where([
        'OR' => [
            ['unit_price <' => 20],
            ['unit_price' => 20, 'tax_percentage <=' => 5],
        ]
    ]);

# {$or: [{unit_price: {$lt: 20}}, {unit_price: 20, tax_percentage: {$lte: 5}}]}
```

For tuple membership, use `tupleIn()`:

```php
$query = $articles->find()
    ->where(function (QueryExpression $exp, SelectQuery $q) {
        return $exp->tupleIn(
            ['articles._id', 'articles.author_id'],
            [[10, 10], [30, 10]],
        );
    });
```

Row-wise comparison of tuples with `<`/`<=`/`=` (SQL `TupleComparison`) has no
direct Mongo analog — express it as the `$or`/`$and` combination shown above.

### Optimizer Hints

Optimizer hints are an SQL database feature and are not applicable to MongoDB.

### Getting the Driver

There is no `getDriver()` on the query — the Mongo driver is the server client
exposed through the `Connection` object (see
[Database basics](../ODM/Database-basics#connections)).

## Getting Results

Once you've made your query, you'll want to retrieve rows from it. There are
a few ways of doing this:

```php
// Iterate the query
foreach ($query as $row) {
    // Do stuff.
}

// Get the results
$results = $query->all();
```

You can use any of the collection methods on your result sets to pre-process or
transform the results:

```php
// Use one of the collection methods.
$ids = $query->all()->map(function ($row) {
    return $row->_id;
});

$maxAge = $query->all()->max(function ($max) {
    return $max->age;
});
```

You can use `first` or `firstOrFail` to retrieve a single record. These
methods will alter the query adding a `$limit: 1` stage:

```php
// Get just the first row
$row = $query->first();

// Get the first row or an exception.
$row = $query->firstOrFail();
```

### Returning the Total Count of Records

Using a single query object, it is possible to obtain the total number of rows
found for a set of conditions:

```php
$total = $articles->find()->where(['is_active' => true])->count();
```

The `count()` method will ignore the `limit`, `offset` and `page`
clauses, thus the following will return the same result:

```php
$total = $articles->find()->where(['is_active' => true])->limit(10)->count();
```

This is useful when you need to know the total result set size in advance,
without having to construct another `SelectQuery` object. Likewise, all result
formatting and map-reduce routines are ignored when using the `count()`
method.

Moreover, it is possible to return the total count for a query containing group
by clauses without having to rewrite the query in any way. For example, consider
this query for retrieving article ids and their comments count:

```php
$query = $articles->find();
$query->select(['Articles._id', $query->func()->count()])
    ->matching('Comments')
    ->groupBy(['Articles._id']);
$total = $query->count();
```

After counting, the query can still be used for fetching the associated
records:

```php
$list = $query->all();
```

### Caching Loaded Results

When fetching documents that don't change often you may want to cache the
results. The `SelectQuery` class makes this simple:

```php
$query->cache('recent_articles');
```

Will enable caching on the query's result set. If only one argument is provided
to `cache()` then the 'default' cache configuration will be used. You can
control which caching configuration is used with the second parameter:

```php
// String config name.
$query->cache('recent_articles', 'dbResults');

// Instance of CacheEngine
$query->cache('recent_articles', $memcache);
```

In addition to supporting static keys, the `cache()` method accepts a function
to generate the key. The function you give it will receive the query as an
argument. You can then read aspects of the query to dynamically generate the
cache key:

```php
// Generate a key based on a simple checksum
// of the query's filter
$query->cache(function ($q) {
    return 'articles-' . md5(serialize($q->clause('where')));
});
```

The cache method makes it simple to add cached results to your custom finders or
through event listeners.

When the results for a cached query are fetched the following happens:

1. If the query has results set, those will be returned.
2. The cache key will be resolved and cache data will be read. If the cache data
   is not empty, those results will be returned.
3. If the cache misses, the query will be executed, the `Collection.beforeFind`
   event will be triggered, and a new `ResultSet` will be created. This
   `ResultSet` will be written to the cache and returned.

## Loading Associations

The builder can help you retrieve data from multiple collections at the same
time with the minimum amount of work possible. To be able to fetch associated
data, you first need to setup associations between the collections as described
in the [Associations - Linking Collections Together](../ODM/associations)
section. This technique of combining queries to fetch associated data from
other collections is called **eager loading**. See
[Loading Associations](../ODM/retrieving-data-and-resultsets#loading-associations)
and [Filtering by Associated Data](../ODM/retrieving-data-and-resultsets#filtering-by-associated-data).

### Adding Joins

In addition to loading related data with `contain()`, you can also add
additional `$lookup` joins with the query builder. The join methods build a
Mongo `$lookup` stage from a target query: the `join()` method is INNER (drops
source rows with no match), `leftJoin()` is LEFT (keeps them):

```php
$query = $articles->find();
$query->leftJoin('Comments', function ($q) {
    return $q->where(['Comments.article_id' => $query->identifier('articles._id')]);
});
```

`join()` and `leftJoin()` accept a target collection (or `[alias =>
collection]`) and a callable receiving the target query to configure. By
default the joined rows are `$unwind`-ed into the parent document; pass
`['asArray' => true]` to keep the nested array:

```php
$query = $articles->find();
$query->leftJoin(['Comments' => 'comments'], function ($q) {
    return $q->where(['Comments.article_id' => $query->identifier('articles._id')]);
}, ['asArray' => true]);
```

The SQL join form with `table`/`alias`/string conditions is replaced by the
`$lookup` pipeline — conditions between the two collections use
`equalFields()` or `$query->identifier()` field references.

## Inserting Data

Unlike earlier examples, you shouldn't use `find()` to create insert queries.
Instead, create a new `InsertQuery` object using `insertQuery()`:

```php
$query = $articles->insertQuery();
$query->insert(['title', 'body'])
    ->values([
        'title' => 'First post',
        'body' => 'Some body text',
    ])
    ->execute();
```

To insert multiple rows with only one query, you can chain the `values()`
method as many times as you need:

```php
$query = $articles->insertQuery();
$query->insert(['title', 'body'])
    ->values([
        'title' => 'First post',
        'body' => 'Some body text',
    ])
    ->values([
        'title' => 'Second post',
        'body' => 'Another body text',
    ])
    ->execute();
```

Generally, it is easier to insert data using documents and
`Crustum\Mongo\ODM\BaseCollection::save()`. The SQL `INSERT INTO ... SELECT`
form has no analog — use an aggregation `$out`/`$merge` stage or a find +
`insertMany` instead.

> [!NOTE]
> Inserting records with the query builder will not trigger events such as
> `Collection.afterSave`. Instead you should use the ODM to save
> data (see [Saving Data](../ODM/saving-data)).

## Updating Data

As with insert queries, you should not use `find()` to create update queries.
Instead, create a new `Query` object using `updateQuery()`:

```php
$query = $articles->updateQuery();
$query->set(['published' => true])
    ->where(['_id' => $id])
    ->execute();
```

The `UpdateQuery` exposes Mongo update operators directly:
`set()`, `unset()`, `increment()` (`$inc`), `decrement()`, `push()` (`$push`),
`pull()` (`$pull`), `addToSet()` (`$addToSet`), `pop()`, `multiply()` (`$mul`),
`rename()` (`$rename`).

Generally, it is easier to update data using documents and
`Crustum\Mongo\ODM\BaseCollection::patchDocument()`.

> [!NOTE]
> Updating records with the query builder will not trigger events such as
> `Collection.afterSave`. Instead you should use the ODM to save
> data (see [Saving Data](../ODM/saving-data)).

## Deleting Data

As with insert queries, you can't use `find()` to create delete queries.
Instead, create a new query object using `deleteQuery()`:

```php
$query = $articles->deleteQuery();
$query->where(['_id' => $id])
    ->execute();
```

Generally, it is easier to delete data using documents and
`Crustum\Mongo\ODM\BaseCollection::delete()`.

## SQL Injection Prevention

While the ODM and database abstraction layers prevent most SQL injection
issues, it is still possible to leave yourself vulnerable through improper use.

When using condition arrays, the key/left-hand side as well as single value
entries must not contain user data:

```php
$query->where([
    // Data on the key/left-hand side is unsafe, as it will be
    // compiled into the pipeline as-is
    $userData => $value,

    // The same applies to single value entries, they are not
    // safe to use with user data in any form
    $userData,
]);
```

When using the expression builder, column names must not contain user data:

```php
$query->where(function (QueryExpression $exp) use ($userData, $values) {
    // Column names in all expressions are not safe.
    return $exp->in($userData, $values);
});
```

When building function expressions, function/operator names should never
contain user data:

```php
// Not safe.
$query->func()->{$userData}($arg1);

// Also not safe to use an array of
// user data in a function expression
$query->func()->coalesce($userData);
```

Raw expressions are never safe:

```php
$expr = $query->func()->expr($userData);
$query->select(['two' => $expr]);
```

Unlike SQL, there are no query bindings in Crustum — values are passed as
native typed values. The safe way to include user data is to use it as a
*value* in condition arrays, `whereInList()`, or function arguments:

```php
$query
    ->where(['title' => $userData])
    ->where(['created <' => $moreUserData]);
```

## More Complex Queries

If your application requires using more complex queries, you can express many
complex queries using the ODM query builder.

### Unions

Unions combine the result sets of multiple collections. The `$unionWith`
aggregation stage merges documents from another collection into the pipeline
(`AggregationBuilder::unionWith()` / the Database-layer sugar):

```php
$query = $articles->find();

// Append documents from the archived_articles collection.
$query->unionWith('archived_articles');
```

Unlike SQL, `$unionWith` takes a **collection name**, not a composed query.
Pipelines can be appended to the unioned collection with the options argument.

### Intersections

Intersections find documents present in the result sets of two queries. There
is no SQL `INTERSECT` operator in Mongo — express the overlap with a `$lookup`
into the other collection plus a `$match` on the foreign key:

```php
$query = $articles->find()
    ->leftJoin('PublishedArticles', function ($q) {
        return $q->where(['PublishedArticles.article_id' => $query->identifier('articles._id')]);
    })
    ->where(['PublishedArticles._id IS NOT' => null]);
```

### Except

Except operations return rows from one query that do not appear in another
query. Express with a `$lookup` plus a `$match` on the missing foreign key:

```php
$query = $articles->find()
    ->leftJoin('PublishedArticles', function ($q) {
        return $q->where(['PublishedArticles.article_id' => $query->identifier('articles._id')]);
    })
    ->where(['PublishedArticles._id IS' => null]);
```

### Subqueries

Subqueries enable you to compose queries together and build conditions and
results based on the results of other queries:

```php
$matchingComment = $articles->getAssociation('Comments')->find()
    ->select(['article_id'])
    ->distinct()
    ->where(['comment LIKE' => '%CakePHP%']);
```

SQL subquery composition (`id IN $sub`, `from([...])`, joining against a
subquery) has no direct Mongo analog. Express the same intent with a `$lookup`
into the target collection:

```php
$query = $articles->find()
    ->leftJoin('Comments', function ($q) {
        return $q->where(['Comments.comment LIKE' => '%CakePHP%']);
    })
    ->where(['Comments._id IS NOT' => null]);
```

`BaseCollection::subquery()` exists but produces a full ODM `SelectQuery` for
finders — it is not a SQL alias-free subquery builder.

### Adding Locking Statements

Locking statements are an SQL database feature and are not applicable to
MongoDB.

### Window Functions

Window functions allow you to perform calculations across a set of related
documents. Mongo exposes this through the `$setWindowFields` stage, added to an
ODM query with `window()`. The `Window` value object configures the partition,
the sort order, and the output fields:

```php
use Crustum\Mongo\Database\Query\Window;

$query = $articles->find();

$query->window(
    (new Window())
        ->partitionBy('$Comments.article_id')
        ->sortBy(['Comments.created' => 1])
        ->output('oldest_comment', ['$first' => '$Comments.created'])
        ->output('row', ['$rowNumber' => (object)[]]),
);
```

The SQL `OVER (PARTITION BY ...)` chaining on aggregate wrappers does not exist —
window aggregates are expressed with `$setWindowFields` and the window operators
wrapped in `func()` (`rowNumber()`, `lag()`, `lead()`).

The window section is **pending doc 50** — the `window()`/`Window` API surface
is confirmed in
`docs/reference/50-aggregation-builder-comparison-proposals.md` (§1 stage
coverage); the exact user-facing sample needs to be verified against the live
Database layer before the banner is removed.

### Common Table Expressions

Common Table Expressions (CTEs) are an SQL construct and have no direct Mongo
analog. Composition of smaller result sets is done with `$lookup`
sub-pipelines; the recursive CTE pattern maps to `$graphLookup`
(`AggregationBuilder::graphLookup()`), which is suited to tree/hierarchical
data.

### Executing Complex Queries

While the query builder makes most queries possible through builder methods,
very complex queries can be tedious and complicated to build. You may want to
append raw pipeline stages directly using `pipeline()`, which receives an
`AggregationBuilder` (or `RawStage` as an escape hatch for stages with no
fluent builder):

```php
$query = $articles->find();

$query->pipeline(function (AggregationBuilder $builder) {
    $builder->unwind('$tags')
        ->sort(['count' => -1])
        ->limit(10);
});
```

See [Database queries](../ODM/Database-basics#database-queries).

Executing a raw pipeline directly allows you to fine tune the query that will
be run. However, doing so doesn't let you use `contain` or other higher level
ODM features.